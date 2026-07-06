<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Importer;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class StoreProfileRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_IMPORT_CREATE;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:191',
            'protocol' => 'required|string|in:sftp,ftp,http,https',
            'host' => 'required|string|max:191',
            'port' => 'required|integer|between:1,65535',
            'username' => 'required|string|max:191',
            'password' => 'required|string',
            'settings' => 'nullable|array',
        ];
    }
}
