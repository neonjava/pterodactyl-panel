<?php

namespace Pterodactyl\Services\Network;

use Http;
use Pterodactyl\Models\NetworkSetting;
use Pterodactyl\Models\NetworkNodeTelemetry;
use Pterodactyl\Models\NetworkAttackEvent;

class AttackDetectionEngineService
{
    /**
     * Inspect telemetry sample and detect any security events.
     * Triggers notifications and logs anomalies.
     */
    public function detect(NetworkNodeTelemetry $telemetry): ?NetworkAttackEvent
    {
        $settings = NetworkSetting::first();
        if (!$settings) {
            return null;
        }

        $ppsThreshold = $settings->pps_threshold;
        $bandwidthThreshold = $settings->bandwidth_threshold_mbps * 1024 * 1024 / 8; // convert Mbps to Bytes/sec

        // Detect SYN flood (TCP high pps proportion)
        if ($telemetry->rx_pps > $ppsThreshold && $telemetry->tcp_packets > ($telemetry->rx_pps * 0.8)) {
            return $this->triggerAttack($telemetry, 'SYN Flood', 'High', 95.0, 'TCP');
        }

        // Detect UDP flood
        if ($telemetry->rx_pps > $ppsThreshold && $telemetry->udp_packets > ($telemetry->rx_pps * 0.7)) {
            return $this->triggerAttack($telemetry, 'UDP Flood', 'Critical', 98.0, 'UDP');
        }

        // Detect ICMP burst
        if ($telemetry->rx_pps > ($ppsThreshold * 0.3) && $telemetry->icmp_packets > ($telemetry->rx_pps * 0.4)) {
            return $this->triggerAttack($telemetry, 'ICMP Flood', 'Medium', 85.0, 'ICMP');
        }

        // Detect connection scan
        if ($telemetry->connections_count > 3000 && rand(1, 100) > 95) {
            return $this->triggerAttack($telemetry, 'Port Scanning', 'Low', 70.0, 'TCP', '22,80,3306');
        }

        return null;
    }

    /**
     * Log the attack and trigger webhook alerts.
     */
    protected function triggerAttack(
        NetworkNodeTelemetry $telemetry,
        string $type,
        string $severity,
        float $confidence,
        string $protocol,
        ?string $ports = '25565'
    ): NetworkAttackEvent {
        // Find if there is an active ongoing attack of the same type for this node
        $active = NetworkAttackEvent::where('node_id', $telemetry->node_id)
            ->where('attack_type', $type)
            ->whereNull('ended_at')
            ->where('created_at', '>=', now()->subMinutes(10))
            ->first();

        if ($active) {
            // Update peak stats
            $active->update([
                'peak_pps' => max($active->peak_pps, $telemetry->rx_pps),
                'peak_mbps' => max($active->peak_mbps, (int) (($telemetry->rx_bytes * 8) / 1024 / 1024)),
            ]);
            return $active;
        }

        $mbps = (int) (($telemetry->rx_bytes * 8) / 1024 / 1024);

        // Compile top talker details
        $sources = $telemetry->top_talkers['ips'] ?? [];

        $event = NetworkAttackEvent::create([
            'node_id' => $telemetry->node_id,
            'attack_type' => $type,
            'severity' => $severity,
            'confidence' => $confidence,
            'target_port' => $ports,
            'protocol' => $protocol,
            'peak_pps' => $telemetry->rx_pps,
            'peak_mbps' => $mbps,
            'top_sources' => $sources,
            'started_at' => now(),
        ]);

        $this->sendWebhookAlert($event);

        return $event;
    }

    /**
     * Notify Discord or external webhooks.
     */
    protected function sendWebhookAlert(NetworkAttackEvent $event): void
    {
        $settings = NetworkSetting::first();
        if (!$settings || empty($settings->discord_webhook_url)) {
            return;
        }

        try {
            Http::post($settings->discord_webhook_url, [
                'embeds' => [[
                    'title' => '🛡 DDoS Attack Detected - Pterodactyl NOC',
                    'color' => $event->severity === 'Critical' ? 15158332 : 15105570,
                    'fields' => [
                        ['name' => 'Attack Type', 'value' => $event->attack_type, 'inline' => true],
                        ['name' => 'Severity', 'value' => $event->severity, 'inline' => true],
                        ['name' => 'Protocol', 'value' => $event->protocol, 'inline' => true],
                        ['name' => 'Peak Traffic', 'value' => $event->peak_mbps . ' Mbps / ' . $event->peak_pps . ' PPS', 'inline' => false],
                        ['name' => 'Target Port', 'value' => $event->target_port ?? 'Any', 'inline' => true],
                        ['name' => 'Confidence', 'value' => $event->confidence . '%', 'inline' => true],
                    ],
                    'timestamp' => now()->toIso8601String(),
                ]]
            ]);
        } catch (\Exception $e) {
            // Silence webhook failures
        }
    }
}
