<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $transfer_id
 * @property string $level
 * @property string $message
 * @property \Carbon\CarbonImmutable $created_at
 *
 * @property ImporterTransfer $transfer
 */
class ImporterLog extends Model
{
    /**
     * The resource name for this model when it is transformed into an
     * API representation using fractal.
     */
    public const RESOURCE_NAME = 'importer_log';

    public const LEVEL_INFO = 'info';
    public const LEVEL_WARNING = 'warning';
    public const LEVEL_ERROR = 'error';

    /**
     * The table associated with the model.
     */
    protected $table = 'importer_logs';

    /**
     * Should timestamps be used on this model. Only created_at is used.
     */
    public $timestamps = false;

    protected bool $immutableDates = true;

    /**
     * Fields that are not mass assignable.
     */
    protected $guarded = ['id', 'created_at'];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'transfer_id' => 'int',
        'created_at' => 'datetime',
    ];

    public static array $validationRules = [
        'transfer_id' => 'required|numeric|exists:importer_transfers,id',
        'level' => 'required|string|in:info,warning,error',
        'message' => 'required|string',
    ];

    /**
     * Perform boot operations.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (ImporterLog $model) {
            $model->created_at = $model->freshTimestamp();
        });
    }

    /**
     * Gets the transfer associated with the log entry.
     */
    public function transfer(): BelongsTo
    {
        return $this->belongsTo(ImporterTransfer::class, 'transfer_id');
    }
}
