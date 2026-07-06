<?php

namespace Pterodactyl\Jobs;

use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Pterodactyl\Models\ImporterTransfer;
use Pterodactyl\Services\Importer\StreamImporterService;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

#[DeleteWhenMissingModels]
class ProcessImporterTransfer implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly ImporterTransfer $transfer,
        public readonly array $connectionDetails,
    ) {
    }

    /**
     * Execute the job.
     */
    public function handle(StreamImporterService $service): void
    {
        $service->handle($this->transfer, $this->connectionDetails);
    }
}
