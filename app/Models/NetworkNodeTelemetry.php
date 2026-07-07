<?php

namespace Pterodactyl\Models;

/**
 * @property int $id
 * @property int $node_id
 * @property int $rx_bytes
 * @property int $tx_bytes
 * @property int $rx_pps
 * @property int $tx_pps
 * @property int $connections_count
 * @property int $dropped_packets
 * @property int $tcp_packets
 * @property int $udp_packets
 * @property int $icmp_packets
 * @property array|null $interface_stats
 * @property array|null $top_talkers
 * @property array|null $server_telemetry
 * @property \Carbon\Carbon $created_at
 */
class NetworkNodeTelemetry extends Model
{
    protected $table = 'network_node_telemetry';

    public $timestamps = false;

    protected $fillable = [
        'node_id',
        'rx_bytes',
        'tx_bytes',
        'rx_pps',
        'tx_pps',
        'connections_count',
        'dropped_packets',
        'tcp_packets',
        'udp_packets',
        'icmp_packets',
        'interface_stats',
        'top_talkers',
        'server_telemetry',
    ];

    protected $casts = [
        'rx_bytes' => 'integer',
        'tx_bytes' => 'integer',
        'rx_pps' => 'integer',
        'tx_pps' => 'integer',
        'connections_count' => 'integer',
        'dropped_packets' => 'integer',
        'tcp_packets' => 'integer',
        'udp_packets' => 'integer',
        'icmp_packets' => 'integer',
        'interface_stats' => 'array',
        'top_talkers' => 'array',
        'server_telemetry' => 'array',
        'created_at' => 'datetime',
    ];

    public static array $validationRules = [
        'node_id' => 'required|integer|exists:nodes,id',
    ];

    /**
     * Get the node associated with this telemetry sample.
     */
    public function node()
    {
        return $this->belongsTo(Node::class);
    }
}
