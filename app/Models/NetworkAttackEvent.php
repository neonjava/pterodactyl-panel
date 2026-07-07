<?php

namespace Pterodactyl\Models;

/**
 * @property int $id
 * @property int|null $node_id
 * @property int|null $server_id
 * @property string $attack_type
 * @property string $severity
 * @property float $confidence
 * @property string|null $target_port
 * @property string $protocol
 * @property int $peak_pps
 * @property int $peak_mbps
 * @property array|null $top_sources
 * @property \Carbon\Carbon $started_at
 * @property \Carbon\Carbon|null $ended_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class NetworkAttackEvent extends Model
{
    protected $table = 'network_attack_events';

    protected $fillable = [
        'node_id',
        'server_id',
        'attack_type',
        'severity',
        'confidence',
        'target_port',
        'protocol',
        'peak_pps',
        'peak_mbps',
        'top_sources',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'confidence' => 'float',
        'peak_pps' => 'integer',
        'peak_mbps' => 'integer',
        'top_sources' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public static array $validationRules = [
        'attack_type' => 'required|string',
        'severity' => 'required|string|in:Normal,Low,Medium,High,Critical',
        'confidence' => 'required|numeric|between:0,100',
        'protocol' => 'required|string|max:10',
    ];

    /**
     * Get the node associated with this attack event.
     */
    public function node()
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * Get the server associated with this attack event.
     */
    public function server()
    {
        return $this->belongsTo(Server::class);
    }
}
