<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_id
 * @property int $user_id
 * @property string $name
 * @property string $protocol
 * @property string $host
 * @property int $port
 * @property string $username
 * @property string $password
 * @property array|null $settings
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property Server $server
 * @property User $user
 */
class SavedImporterProfile extends Model
{
    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'saved_importer_profile';

    /**
     * The table associated with the model.
     */
    protected $table = 'saved_importer_profiles';

    /**
     * Fields that are not mass assignable.
     */
    protected $guarded = ['id', 'created_at', 'updated_at'];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'server_id' => 'int',
        'user_id' => 'int',
        'port' => 'int',
        'settings' => 'array',
    ];

    public static array $validationRules = [
        'server_id' => 'required|numeric|exists:servers,id',
        'user_id' => 'required|numeric|exists:users,id',
        'name' => 'required|string|max:191',
        'protocol' => 'required|string|max:191',
        'host' => 'required|string|max:191',
        'port' => 'required|numeric|between:1,65535',
        'username' => 'required|string|max:191',
        'password' => 'required|string',
        'settings' => 'nullable|array',
    ];

    /**
     * Get the password attribute decrypted.
     */
    public function getPasswordAttribute(string $value): string
    {
        try {
            return decrypt($value);
        } catch (\Exception $e) {
            return $value;
        }
    }

    /**
     * Set the password attribute encrypted.
     */
    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password'] = encrypt($value);
    }

    /**
     * Gets the server associated with the importer profile.
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Gets the user associated with the importer profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
