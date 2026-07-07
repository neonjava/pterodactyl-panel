<?php

namespace Tests\Unit\Services\Network;

use Tests\TestCase;
use Pterodactyl\Models\NetworkSetting;
use Pterodactyl\Models\NetworkNodeTelemetry;
use Pterodactyl\Models\NetworkAttackEvent;
use Pterodactyl\Services\Network\AttackDetectionEngineService;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class AttackDetectionEngineServiceTest extends TestCase
{
    use DatabaseTransactions;

    private AttackDetectionEngineService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = $this->app->make(AttackDetectionEngineService);

        // Seed a default settings row
        NetworkSetting::query()->delete();
        NetworkSetting::create([
            'sampling_interval' => 5,
            'history_retention_days' => 7,
            'bandwidth_threshold_mbps' => 10,
            'pps_threshold' => 100,
            'enable_geoip' => true,
            'enable_asn_lookup' => true,
            'enable_ebpf' => false,
        ]);
    }

    /**
     * Test that high UDP pps triggers a UDP flood attack alert.
     */
    public function testDetectsUdpFlood()
    {
        $telemetry = new NetworkNodeTelemetry([
            'node_id' => 1,
            'rx_bytes' => 12000000,
            'tx_bytes' => 100000,
            'rx_pps' => 150,
            'tx_pps' => 10,
            'connections_count' => 1000,
            'dropped_packets' => 0,
            'tcp_packets' => 10,
            'udp_packets' => 130, // 86% of rx_pps
            'icmp_packets' => 10,
        ]);

        $event = $this->service->detect($telemetry);

        $this->assertInstanceOf(NetworkAttackEvent::class, $event);
        $this->assertEquals('UDP Flood', $event->attack_type);
        $this->assertEquals('Critical', $event->severity);
        $this->assertEquals('UDP', $event->protocol);
    }

    /**
     * Test that low pps returns null (no attack).
     */
    public function testDoesNotDetectNormalTraffic()
    {
        $telemetry = new NetworkNodeTelemetry([
            'node_id' => 1,
            'rx_bytes' => 10000,
            'tx_bytes' => 10000,
            'rx_pps' => 5,
            'tx_pps' => 5,
            'connections_count' => 10,
            'dropped_packets' => 0,
            'tcp_packets' => 5,
            'udp_packets' => 0,
            'icmp_packets' => 0,
        ]);

        $event = $this->service->detect($telemetry);

        $this->assertNull($event);
    }
}
