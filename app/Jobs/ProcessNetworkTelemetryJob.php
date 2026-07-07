<?php

namespace Pterodactyl\Jobs;

use Illuminate\Bus\Queueable;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\NetworkSetting;
use Pterodactyl\Models\NetworkNodeTelemetry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Pterodactyl\Services\Network\NetworkTelemetryCollectorService;
use Pterodactyl\Services\Network\AttackDetectionEngineService;
use Pterodactyl\Events\Network\TelemetryUpdated;

class ProcessNetworkTelemetryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(
        NetworkTelemetryCollectorService $collector,
        AttackDetectionEngineService $detector
    ): void {
        $settings = NetworkSetting::first();
        if (!$settings) {
            return;
        }

        $nodes = Node::all();
        foreach ($nodes as $node) {
            // Collect metrics
            $telemetry = $collector->collect($node, [
                'enable_geoip' => $settings->enable_geoip,
                'enable_asn_lookup' => $settings->enable_asn_lookup,
                'enable_ebpf' => $settings->enable_ebpf,
            ]);
            $telemetry->save();

            // Run detection
            $detector->detect($telemetry);

            // Broadcast real-time update
            event(new TelemetryUpdated($telemetry->toArray()));
        }

        // Prune logs beyond history_retention_days
        $retentionLimit = now()->subDays($settings->history_retention_days);
        NetworkNodeTelemetry::where('created_at', '<', $retentionLimit)->delete();
    }
}
