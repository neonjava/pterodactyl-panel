<?php

namespace Pterodactyl\Models;

/**
 * @property int $id
 * @property int $sampling_interval
 * @property int $history_retention_days
 * @property int $bandwidth_threshold_mbps
 * @property int $pps_threshold
 * @property bool $enable_geoip
 * @property bool $enable_asn_lookup
 * @property bool $enable_ebpf
 * @property string|null $discord_webhook_url
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class NetworkSetting extends Model
{
    protected $table = 'network_settings';

    protected $fillable = [
        'sampling_interval',
        'history_retention_days',
        'bandwidth_threshold_mbps',
        'pps_threshold',
        'enable_geoip',
        'enable_asn_lookup',
        'enable_ebpf',
        'discord_webhook_url',
    ];

    protected $casts = [
        'sampling_interval' => 'integer',
        'history_retention_days' => 'integer',
        'bandwidth_threshold_mbps' => 'integer',
        'pps_threshold' => 'integer',
        'enable_geoip' => 'boolean',
        'enable_asn_lookup' => 'boolean',
        'enable_ebpf' => 'boolean',
    ];

    public static array $validationRules = [
        'sampling_interval' => 'required|integer|min:1',
        'history_retention_days' => 'required|integer|min:1',
        'bandwidth_threshold_mbps' => 'required|integer|min:1',
        'pps_threshold' => 'required|integer|min:1',
        'enable_geoip' => 'required|boolean',
        'enable_asn_lookup' => 'required|boolean',
        'enable_ebpf' => 'required|boolean',
        'discord_webhook_url' => 'nullable|url',
    ];
}
