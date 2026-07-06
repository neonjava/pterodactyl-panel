<?php

namespace Pterodactyl\Services\Importer;

use GuzzleHttp\Client;
use phpseclib3\Net\SFTP;
use Carbon\CarbonImmutable;
use Pterodactyl\Models\User;
use Pterodactyl\Enum\JwtScope;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ImporterLog;
use Pterodactyl\Models\ImporterTransfer;
use Pterodactyl\Services\Nodes\NodeJWTService;
use Pterodactyl\Events\Importer\TransferStatusUpdated;
use Pterodactyl\Repositories\Wings\DaemonFileRepository;

class StreamImporterService
{
    public function __construct(
        private NodeJWTService $jwtService,
        private DaemonFileRepository $fileRepository,
    ) {
    }

    /**
     * Perform the actual import streaming.
     */
    public function handle(ImporterTransfer $transfer, array $connection): void
    {
        $transfer->update([
            'status' => ImporterTransfer::STATUS_CONNECTING,
            'started_at' => now(),
        ]);
        event(new TransferStatusUpdated($transfer));

        $this->log($transfer, ImporterLog::LEVEL_INFO, 'Connecting to remote host: ' . $connection['host']);

        try {
            if (in_array($connection['protocol'], ['http', 'https'])) {
                $this->handleHttpPull($transfer, $connection);
            } else {
                $this->handleStreamDownloadAndUpload($transfer, $connection);
            }

            $transfer->update([
                'status' => ImporterTransfer::STATUS_FINISHED,
                'completed_at' => now(),
                'eta' => 0,
                'speed' => 0,
            ]);
            event(new TransferStatusUpdated($transfer));
            $this->log($transfer, ImporterLog::LEVEL_INFO, 'Transfer completed successfully.');
        } catch (\Throwable $e) {
            $transfer->update([
                'status' => ImporterTransfer::STATUS_FAILED,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'eta' => null,
                'speed' => 0,
            ]);
            event(new TransferStatusUpdated($transfer));
            $this->log($transfer, ImporterLog::LEVEL_ERROR, 'Transfer failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle HTTP pull directly via Wings.
     */
    protected function handleHttpPull(ImporterTransfer $transfer, array $connection): void
    {
        $this->log($transfer, ImporterLog::LEVEL_INFO, 'Instructing Wings to pull remote HTTP resource.');
        
        $transfer->update(['status' => ImporterTransfer::STATUS_STREAMING]);
        event(new TransferStatusUpdated($transfer));

        $host = rtrim($connection['host'], '/');
        if (!preg_match('/^https?:\/\//i', $host)) {
            $host = $connection['protocol'] . '://' . $host;
        }
        if (!in_array($connection['port'], [80, 443])) {
            $host .= ':' . $connection['port'];
        }
        $url = $host . '/' . ltrim($transfer->source_path, '/');

        // We can pass basic auth credentials in the URL if provided
        if (!empty($connection['username']) && !empty($connection['password'])) {
            $parsed = parse_url($url);
            $url = sprintf(
                '%s://%s:%s@%s%s%s',
                $parsed['scheme'],
                urlencode($connection['username']),
                urlencode($connection['password']),
                $parsed['host'],
                isset($parsed['port']) ? ':' . $parsed['port'] : '',
                $parsed['path'] ?? '/'
            );
        }

        $filename = basename($transfer->destination_path);
        $directory = dirname($transfer->destination_path);

        $this->fileRepository->setServer($transfer->server)->pull($url, $directory, [
            'filename' => $filename,
            'foreground' => true,
        ]);
    }

    /**
     * Handle streaming from SFTP/FTP to local temp, then upload to Wings.
     */
    protected function handleStreamDownloadAndUpload(ImporterTransfer $transfer, array $connection): void
    {
        $transfer->update(['status' => ImporterTransfer::STATUS_AUTHENTICATING]);
        event(new TransferStatusUpdated($transfer));

        $remoteStream = null;
        $bytesTotal = 0;

        if ($connection['protocol'] === 'sftp') {
            $sftp = new SFTP($connection['host'], $connection['port'], 15);
            if (!$sftp->login($connection['username'], $connection['password'])) {
                throw new \Exception('SFTP authentication failed.');
            }
            $stat = $sftp->stat($transfer->source_path);
            $bytesTotal = $stat['size'] ?? 0;
            $remoteStream = $sftp->get($transfer->source_path); // Gets raw content or stream wrapper
            
            // If get() returns a string or boolean instead of a stream, we write/wrap it
            if (is_string($remoteStream)) {
                $tempStream = fopen('php://temp', 'r+');
                fwrite($tempStream, $remoteStream);
                rewind($tempStream);
                $remoteStream = $tempStream;
            }
        } else {
            // FTP
            $conn = @ftp_connect($connection['host'], $connection['port'], 15);
            if (!$conn) {
                throw new \Exception('Failed to connect to FTP server.');
            }
            if (!@ftp_login($conn, $connection['username'], $connection['password'])) {
                @ftp_close($conn);
                throw new \Exception('FTP authentication failed.');
            }
            @ftp_pasv($conn, true);
            $bytesTotal = @ftp_size($conn, $transfer->source_path);
            if ($bytesTotal < 0) {
                $bytesTotal = 0;
            }

            $tempStream = fopen('php://temp', 'r+');
            if (!@ftp_fget($conn, $tempStream, $transfer->source_path, FTP_BINARY)) {
                @fclose($tempStream);
                @ftp_close($conn);
                throw new \Exception('Failed to retrieve file via FTP.');
            }
            rewind($tempStream);
            $remoteStream = $tempStream;
            @ftp_close($conn);
        }

        if (!$remoteStream || !is_resource($remoteStream)) {
            throw new \Exception('Failed to open remote stream.');
        }

        $transfer->update([
            'status' => ImporterTransfer::STATUS_STREAMING,
            'bytes_total' => $bytesTotal,
        ]);
        event(new TransferStatusUpdated($transfer));
        $this->log($transfer, ImporterLog::LEVEL_INFO, 'Starting streaming download. Total bytes: ' . $bytesTotal);

        // Download stream to panel's local temporary file to stream it reliably
        $tempLocalFile = tempnam(sys_get_temp_dir(), 'importer_');
        $localFile = fopen($tempLocalFile, 'w+');

        $bytesTransferred = 0;
        $startTime = microtime(true);
        $lastUpdate = microtime(true);

        while (!feof($remoteStream)) {
            $chunk = fread($remoteStream, 65536); // 64KB chunks
            if ($chunk === false || $chunk === '') {
                break;
            }
            fwrite($localFile, $chunk);
            $bytesTransferred += strlen($chunk);

            $currentTime = microtime(true);
            if ($currentTime - $lastUpdate >= 2.0) {
                $elapsed = $currentTime - $startTime;
                $speed = $elapsed > 0 ? (int) ($bytesTransferred / $elapsed) : 0;
                $eta = ($speed > 0 && $bytesTotal > 0) ? (int) (($bytesTotal - $bytesTransferred) / $speed) : null;

                $transfer->update([
                    'bytes_transferred' => $bytesTransferred,
                    'speed' => $speed,
                    'eta' => $eta,
                ]);
                event(new TransferStatusUpdated($transfer));
                $lastUpdate = $currentTime;
            }
        }
        fclose($remoteStream);
        fclose($localFile);

        $this->log($transfer, ImporterLog::LEVEL_INFO, 'Remote stream download complete. Preparing upload to Wings.');

        $transfer->update(['status' => ImporterTransfer::STATUS_VERIFYING]);
        event(new TransferStatusUpdated($transfer));

        // Get upload URL from Wings
        $uploadUrl = $this->getUploadUrl($transfer->server, $transfer->user);
        $filename = basename($transfer->destination_path);
        $directory = dirname($transfer->destination_path);

        $client = new Client();
        $client->post($uploadUrl, [
            'multipart' => [
                [
                    'name' => 'files',
                    'contents' => fopen($tempLocalFile, 'r'),
                    'filename' => $filename,
                ]
            ],
            'query' => [
                'directory' => $directory,
            ],
        ]);

        @unlink($tempLocalFile);
    }

    /**
     * Generate the file upload token and URL for Wings.
     */
    protected function getUploadUrl(Server $server, User $user): string
    {
        $token = $this->jwtService
            ->setExpiresAt(CarbonImmutable::now()->addMinutes(30))
            ->setUser($user)
            ->setClaims(['server_uuid' => $server->uuid])
            ->setScopes(JwtScope::FileUpload)
            ->handle($server->node, $user->id . $server->uuid);

        return sprintf(
            '%s/upload/file?token=%s',
            $server->node->getConnectionAddress(),
            $token->toString()
        );
    }

    /**
     * Log helper function.
     */
    protected function log(ImporterTransfer $transfer, string $level, string $message): void
    {
        $transfer->logs()->create([
            'level' => $level,
            'message' => $message,
        ]);
        logger()->info("[Importer Transfer #{$transfer->id}] {$level}: {$message}");
    }
}
