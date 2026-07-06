<?php

namespace Pterodactyl\Http\Controllers\Api\Client\Servers;

use Illuminate\Http\Response;
use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Pterodactyl\Models\ImporterLog;
use Pterodactyl\Models\ImporterTransfer;
use Pterodactyl\Jobs\ProcessImporterTransfer;
use Pterodactyl\Models\SavedImporterProfile;
use Pterodactyl\Http\Controllers\Api\Client\ClientApiController;
use Pterodactyl\Services\Importer\ImporterConnectionService;
use Pterodactyl\Transformers\Api\Client\ImporterLogTransformer;
use Pterodactyl\Transformers\Api\Client\ImporterTransferTransformer;
use Pterodactyl\Transformers\Api\Client\SavedImporterProfileTransformer;
use Pterodactyl\Http\Requests\Api\Client\Servers\Importer\StoreProfileRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Importer\ViewImporterRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Importer\StartTransferRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Importer\BrowseRemoteRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Importer\DeleteImporterRequest;
use Pterodactyl\Http\Requests\Api\Client\Servers\Importer\TestConnectionRequest;

class ImporterController extends ClientApiController
{
    public function __construct(
        private ImporterConnectionService $connectionService
    ) {
        parent::__construct();
    }

    /**
     * List saved importer profiles.
     */
    public function indexProfiles(ViewImporterRequest $request, Server $server): array
    {
        $profiles = SavedImporterProfile::where('server_id', $server->id)->get();

        return $this->fractal->collection($profiles)
            ->transformWith($this->getTransformer(SavedImporterProfileTransformer::class))
            ->toArray();
    }

    /**
     * Store a new saved importer profile.
     */
    public function storeProfile(StoreProfileRequest $request, Server $server): array
    {
        $profile = SavedImporterProfile::create(array_merge($request->validated(), [
            'server_id' => $server->id,
            'user_id' => $request->user()->id,
        ]));

        return $this->fractal->item($profile)
            ->transformWith($this->getTransformer(SavedImporterProfileTransformer::class))
            ->toArray();
    }

    /**
     * Update/rename a saved importer profile.
     */
    public function updateProfile(StoreProfileRequest $request, Server $server, int $profileId): array
    {
        $profile = SavedImporterProfile::where('server_id', $server->id)->findOrFail($profileId);
        $profile->update($request->validated());

        return $this->fractal->item($profile)
            ->transformWith($this->getTransformer(SavedImporterProfileTransformer::class))
            ->toArray();
    }

    /**
     * Delete a saved importer profile.
     */
    public function deleteProfile(DeleteImporterRequest $request, Server $server, int $profileId): JsonResponse
    {
        $profile = SavedImporterProfile::where('server_id', $server->id)->findOrFail($profileId);
        $profile->delete();

        return new JsonResponse([], Response::HTTP_NO_CONTENT);
    }

    /**
     * Test connection to a remote importer source.
     */
    public function testConnection(TestConnectionRequest $request, Server $server): JsonResponse
    {
        try {
            $connection = $this->resolveConnectionDetails($request, $server);
            $this->connectionService->testConnection($connection);
            return new JsonResponse(['success' => true, 'message' => 'Connection tested successfully.']);
        } catch (\Exception $e) {
            return new JsonResponse(['success' => false, 'message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * List remote files and directories.
     */
    public function browseRemote(BrowseRemoteRequest $request, Server $server): JsonResponse
    {
        try {
            $connection = $this->resolveConnectionDetails($request, $server);
            $files = $this->connectionService->listDirectory($connection, $request->input('directory'));
            return new JsonResponse($files);
        } catch (\Exception $e) {
            return new JsonResponse(['message' => $e->getMessage()], Response::HTTP_BAD_REQUEST);
        }
    }

    /**
     * Start a new importer transfer.
     */
    public function startTransfer(StartTransferRequest $request, Server $server): array
    {
        $connection = $this->resolveConnectionDetails($request, $server);

        $transfer = ImporterTransfer::create([
            'server_id' => $server->id,
            'user_id' => $request->user()->id,
            'status' => ImporterTransfer::STATUS_QUEUED,
            'protocol' => $connection['protocol'],
            'source_path' => $request->input('source_path'),
            'destination_path' => $request->input('destination_path'),
            'bytes_transferred' => 0,
            'bytes_total' => 0,
            'speed' => 0,
        ]);

        // Dispatch the queue job
        dispatch(new ProcessImporterTransfer($transfer, $connection));

        return $this->fractal->item($transfer)
            ->transformWith($this->getTransformer(ImporterTransferTransformer::class))
            ->toArray();
    }

    /**
     * Retrieve transfer history.
     */
    public function indexTransfers(ViewImporterRequest $request, Server $server): array
    {
        $transfers = ImporterTransfer::where('server_id', $server->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->fractal->collection($transfers)
            ->transformWith($this->getTransformer(ImporterTransferTransformer::class))
            ->toArray();
    }

    /**
     * Get log contents for a transfer.
     */
    public function getTransferLogs(ViewImporterRequest $request, Server $server, int $transferId): array
    {
        $transfer = ImporterTransfer::where('server_id', $server->id)->findOrFail($transferId);
        $logs = ImporterLog::where('transfer_id', $transfer->id)->orderBy('created_at', 'asc')->get();

        return $this->fractal->collection($logs)
            ->transformWith($this->getTransformer(ImporterLogTransformer::class))
            ->toArray();
    }

    /**
     * Helper to resolve connection details.
     */
    private function resolveConnectionDetails($request, Server $server): array
    {
        if ($request->has('profile_id')) {
            $profile = SavedImporterProfile::where('server_id', $server->id)
                ->findOrFail($request->input('profile_id'));
            return [
                'protocol' => $profile->protocol,
                'host' => $profile->host,
                'port' => $profile->port,
                'username' => $profile->username,
                'password' => $profile->password,
                'settings' => $profile->settings ?? [],
            ];
        }

        return [
            'protocol' => $request->input('protocol'),
            'host' => $request->input('host'),
            'port' => (int) $request->input('port'),
            'username' => $request->input('username'),
            'password' => $request->input('password'),
            'settings' => $request->input('settings', []),
        ];
    }
}
