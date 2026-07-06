<?php

namespace Pterodactyl\Transformers\Api\Client;

use Pterodactyl\Models\ImporterLog;

class ImporterLogTransformer extends BaseClientTransformer
{
    public function getResourceName(): string
    {
        return ImporterLog::RESOURCE_NAME;
    }

    public function transform(ImporterLog $log): array
    {
        return [
            'id' => $log->id,
            'level' => $log->level,
            'message' => $log->message,
            'created_at' => $log->created_at->toAtomString(),
        ];
    }
}
