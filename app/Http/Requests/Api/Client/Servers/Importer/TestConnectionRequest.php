<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Importer;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class TestConnectionRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_IMPORT_CREATE;
    }

    public function rules(): array
    {
        return [
            'profile_id' => 'nullable|integer|exists:saved_importer_profiles,id',
            'protocol' => 'required_without:profile_id|string|in:sftp,ftp,http,https',
            'host' => 'required_without:profile_id|string|max:191',
            'port' => 'required_without:profile_id|integer|between:1,65535',
            'username' => 'required_without:profile_id|string|max:191',
            'password' => 'required_without:profile_id|string',
            'settings' => 'nullable|array',
        ];
    }
}
