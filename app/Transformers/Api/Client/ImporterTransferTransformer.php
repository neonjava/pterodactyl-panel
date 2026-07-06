<?php

namespace Pterodactyl\Transformers\Api\Client;

use Pterodactyl\Models\ImporterTransfer;

class ImporterTransferTransformer extends BaseClientTransformer
{
    public function getResourceName(): string
    {
        return ImporterTransfer::RESOURCE_NAME;
    }

    public function transform(ImporterTransfer $transfer): array
    {
        return [
            'id' => $transfer->id,
            'status' => $transfer->status,
            'protocol' => $transfer->protocol,
            'source_path' => $transfer->source_path,
            'destination_path' => $transfer->destination_path,
            'bytes_transferred' => $transfer->bytes_transferred,
            'bytes_total' => $transfer->bytes_total,
            'speed' => $transfer->speed,
            'eta' => $transfer->eta,
            'checksum_expected' => $transfer->checksum_expected,
            'checksum_actual' => $transfer->checksum_actual,
            'error_message' => $transfer->error_message,
            'started_at' => $transfer->started_at ? $transfer->started_at->toAtomString() : null,
            'completed_at' => $transfer->completed_at ? $transfer->completed_at->toAtomString() : null,
            'created_at' => $transfer->created_at->toAtomString(),
            'updated_at' => $transfer->updated_at->toAtomString(),
        ];
    }
}
