<?php

namespace Pterodactyl\Http\Requests\Api\Client\Servers\Importer;

use Pterodactyl\Models\Permission;
use Pterodactyl\Http\Requests\Api\Client\ClientApiRequest;

class ViewImporterRequest extends ClientApiRequest
{
    public function permission(): string
    {
        return Permission::ACTION_IMPORT_READ;
    }

    public function rules(): array
    {
        return [];
    }
}
