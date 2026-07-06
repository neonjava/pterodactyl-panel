<?php

namespace Pterodactyl\Transformers\Api\Client;

use Pterodactyl\Models\SavedImporterProfile;

class SavedImporterProfileTransformer extends BaseClientTransformer
{
    public function getResourceName(): string
    {
        return SavedImporterProfile::RESOURCE_NAME;
    }

    public function transform(SavedImporterProfile $profile): array
    {
        return [
            'id' => $profile->id,
            'name' => $profile->name,
            'protocol' => $profile->protocol,
            'host' => $profile->host,
            'port' => $profile->port,
            'username' => $profile->username,
            'settings' => $profile->settings,
            'created_at' => $profile->created_at->toAtomString(),
            'updated_at' => $profile->updated_at->toAtomString(),
        ];
    }
}
