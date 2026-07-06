<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $server_id
 * @property int $user_id
 * @property string $status
 * @property string $protocol
 * @property string $source_path
 * @property string $destination_path
 * @property int $bytes_transferred
 * @property int $bytes_total
 * @property int $speed
 * @property int|null $eta
 * @property string|null $checksum_expected
 * @property string|null $checksum_actual
 * @property string|null $error_message
 * @property \Carbon\CarbonImmutable|null $started_at
 * @property \Carbon\CarbonImmutable|null $completed_at
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 *
 * @property Server $server
 * @property User $user
 * @property ImporterLog[] $logs
 */
class ImporterTransfer extends Model
{
    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'importer_transfer';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_CONNECTING = 'connecting';
    public const STATUS_AUTHENTICATING = 'authenticating';
    public const STATUS_STREAMING = 'streaming';
    public const STATUS_VERIFYING = 'verifying';
    public const STATUS_FINISHED = 'finished';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_PAUSED = 'paused';

    /**
     * The table associated with the model.
     */
    protected $table = 'importer_transfers';

    /**
     * Fields that are not mass assignable.
     */
    protected $guarded = ['id', 'created_at', 'updated_at'];

    protected bool $immutableDates = true;

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'server_id' => 'int',
        'user_id' => 'int',
        'bytes_transferred' => 'int',
        'bytes_total' => 'int',
        'speed' => 'int',
        'eta' => 'int',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public static array $validationRules = [
        'server_id' => 'required|numeric|exists:servers,id',
        'user_id' => 'required|numeric|exists:users,id',
        'status' => 'required|string|in:queued,connecting,authenticating,streaming,verifying,finished,failed,cancelled,paused',
        'protocol' => 'required|string|max:191',
        'source_path' => 'required|string|max:191',
        'destination_path' => 'required|string|max:191',
        'bytes_transferred' => 'numeric|min:0',
        'bytes_total' => 'numeric|min:0',
        'speed' => 'numeric|min:0',
        'eta' => 'nullable|integer',
        'checksum_expected' => 'nullable|string|max:191',
        'checksum_actual' => 'nullable|string|max:191',
        'error_message' => 'nullable|string',
        'started_at' => 'nullable|date',
        'completed_at' => 'nullable|date',
    ];

    /**
     * Gets the server associated with the importer transfer.
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * Gets the user associated with the importer transfer.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Gets the logs associated with the importer transfer.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(ImporterLog::class, 'transfer_id');
    }
}
