<?php

namespace Pterodactyl\Services\Importer;

use GuzzleHttp\Client;
use phpseclib3\Net\SFTP;
use Pterodactyl\Models\SavedImporterProfile;

class ImporterConnectionService
{
    /**
     * Test the connection to the remote server.
     *
     * @throws \Exception
     */
    public function testConnection(array|SavedImporterProfile $profile): bool
    {
        $details = $this->resolveDetails($profile);

        switch ($details['protocol']) {
            case 'sftp':
                $sftp = new SFTP($details['host'], $details['port'], 10);
                if (!$sftp->login($details['username'], $details['password'])) {
                    throw new \Exception('Failed to authenticate with SFTP server.');
                }
                return true;

            case 'ftp':
                $conn = @ftp_connect($details['host'], $details['port'], 10);
                if (!$conn) {
                    throw new \Exception('Failed to connect to FTP server.');
                }
                if (!@ftp_login($conn, $details['username'], $details['password'])) {
                    @ftp_close($conn);
                    throw new \Exception('Failed to authenticate with FTP server.');
                }
                @ftp_close($conn);
                return true;

            case 'http':
            case 'https':
                $client = new Client(['timeout' => 10]);
                $url = $this->buildHttpUrl($details, '');
                $options = [];
                if (!empty($details['username']) && !empty($details['password'])) {
                    $options['auth'] = [$details['username'], $details['password']];
                }
                try {
                    $client->request('GET', $url, $options);
                } catch (\Exception $e) {
                    throw new \Exception('Failed to connect to HTTP(S) server: ' . $e->getMessage());
                }
                return true;

            default:
                throw new \Exception('Unsupported connection protocol: ' . $details['protocol']);
        }
    }

    /**
     * Fetch a list of files and directories from the remote server.
     *
     * @throws \Exception
     */
    public function listDirectory(array|SavedImporterProfile $profile, ?string $directory): array
    {
        $details = $this->resolveDetails($profile);
        $directory = $directory ?: '/';

        switch ($details['protocol']) {
            case 'sftp':
                $sftp = new SFTP($details['host'], $details['port'], 15);
                if (!$sftp->login($details['username'], $details['password'])) {
                    throw new \Exception('Failed to authenticate with SFTP server.');
                }
                $rawList = $sftp->rawlist($directory);
                if ($rawList === false) {
                    throw new \Exception('Failed to list SFTP directory: ' . $directory);
                }
                $files = [];
                foreach ($rawList as $name => $stat) {
                    if ($name === '.' || $name === '..') {
                        continue;
                    }
                    $files[] = [
                        'name' => $name,
                        'size' => $stat['size'] ?? 0,
                        'is_file' => ($stat['type'] ?? 1) !== 2, // 2 is SFTP::TYPE_DIRECTORY
                    ];
                }
                return $files;

            case 'ftp':
                $conn = @ftp_connect($details['host'], $details['port'], 15);
                if (!$conn) {
                    throw new \Exception('Failed to connect to FTP server.');
                }
                if (!@ftp_login($conn, $details['username'], $details['password'])) {
                    @ftp_close($conn);
                    throw new \Exception('Failed to authenticate with FTP server.');
                }
                @ftp_pasv($conn, true);
                $filenames = @ftp_nlist($conn, $directory);
                if ($filenames === false) {
                    @ftp_close($conn);
                    throw new \Exception('Failed to list FTP directory: ' . $directory);
                }
                $files = [];
                foreach ($filenames as $filename) {
                    $basename = basename($filename);
                    if ($basename === '.' || $basename === '..') {
                        continue;
                    }
                    $fullPath = rtrim($directory, '/') . '/' . $basename;
                    $isDir = false;
                    if (@ftp_chdir($conn, $fullPath)) {
                        $isDir = true;
                        @ftp_chdir($conn, $directory);
                    }
                    $size = 0;
                    if (!$isDir) {
                        $size = @ftp_size($conn, $fullPath);
                        if ($size < 0) {
                            $size = 0;
                        }
                    }
                    $files[] = [
                        'name' => $basename,
                        'size' => $size,
                        'is_file' => !$isDir,
                    ];
                }
                @ftp_close($conn);
                return $files;

            case 'http':
            case 'https':
                // For HTTP/HTTPS, the listing is just the target file itself
                $client = new Client(['timeout' => 10]);
                $url = $this->buildHttpUrl($details, $directory);
                $options = [];
                if (!empty($details['username']) && !empty($details['password'])) {
                    $options['auth'] = [$details['username'], $details['password']];
                }
                try {
                    $response = $client->request('GET', $url, $options);
                    $size = (int) $response->getHeaderLine('Content-Length');
                    return [
                        [
                            'name' => basename($directory) ?: 'file',
                            'size' => $size,
                            'is_file' => true,
                        ]
                    ];
                } catch (\Exception $e) {
                    throw new \Exception('Failed to fetch remote HTTP file details: ' . $e->getMessage());
                }

            default:
                throw new \Exception('Unsupported protocol: ' . $details['protocol']);
        }
    }

    /**
     * Resolve connection details array.
     */
    protected function resolveDetails(array|SavedImporterProfile $profile): array
    {
        if ($profile instanceof SavedImporterProfile) {
            return [
                'protocol' => $profile->protocol,
                'host' => $profile->host,
                'port' => $profile->port,
                'username' => $profile->username,
                'password' => $profile->password,
                'settings' => $profile->settings,
            ];
        }

        return $profile;
    }

    /**
     * Build http url from details.
     */
    protected function buildHttpUrl(array $details, string $path): string
    {
        $host = rtrim($details['host'], '/');
        // If host doesn't start with scheme, prepend it
        if (!preg_match('/^https?:\/\//i', $host)) {
            $host = $details['protocol'] . '://' . $host;
        }

        // Add port if not standard
        $parsed = parse_url($host);
        if (empty($parsed['port']) && !in_array($details['port'], [80, 443])) {
            $host .= ':' . $details['port'];
        }

        return $host . '/' . ltrim($path, '/');
    }
}
