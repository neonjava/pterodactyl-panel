<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('network_attack_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('node_id')->nullable();
            $table->unsignedInteger('server_id')->nullable();
            $table->string('attack_type'); // e.g. SYN flood, UDP flood, Port scan
            $table->string('severity'); // Normal, Low, Medium, High, Critical
            $table->decimal('confidence', 5, 2); // Confidence percentage
            $table->string('target_port')->nullable();
            $table->string('protocol', 10)->default('TCP');
            $table->unsignedBigInteger('peak_pps')->default(0);
            $table->unsignedBigInteger('peak_mbps')->default(0);
            $table->json('top_sources')->nullable(); // Top IPs, countries, ASN
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['node_id', 'started_at']);
            $table->index(['server_id', 'started_at']);
        });

        Schema::create('network_node_telemetry', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('node_id');
            $table->unsignedBigInteger('rx_bytes');
            $table->unsignedBigInteger('tx_bytes');
            $table->unsignedBigInteger('rx_pps');
            $table->unsignedBigInteger('tx_pps');
            $table->unsignedInteger('connections_count');
            $table->unsignedBigInteger('dropped_packets');
            $table->unsignedBigInteger('tcp_packets');
            $table->unsignedBigInteger('udp_packets');
            $table->unsignedBigInteger('icmp_packets');
            $table->json('interface_stats')->nullable(); // JSON of per-interface details
            $table->json('top_talkers')->nullable(); // top countries/IPs/ports
            $table->json('server_telemetry')->nullable(); // stats per pterodactyl server
            $table->timestamp('created_at')->useCurrent();

            $table->index(['node_id', 'created_at']);
        });

        Schema::create('network_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sampling_interval')->default(5); // in seconds
            $table->unsignedInteger('history_retention_days')->default(7);
            $table->unsignedBigInteger('bandwidth_threshold_mbps')->default(1000);
            $table->unsignedBigInteger('pps_threshold')->default(50000);
            $table->boolean('enable_geoip')->default(true);
            $table->boolean('enable_asn_lookup')->default(true);
            $table->boolean('enable_ebpf')->default(false);
            $table->string('discord_webhook_url')->nullable();
            $table->timestamps();
        });

        // Insert default settings
        DB::table('network_settings')->insert([
            'sampling_interval' => 5,
            'history_retention_days' => 7,
            'bandwidth_threshold_mbps' => 1000,
            'pps_threshold' => 50000,
            'enable_geoip' => true,
            'enable_asn_lookup' => true,
            'enable_ebpf' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('network_attack_events');
        Schema::dropIfExists('network_node_telemetry');
        Schema::dropIfExists('network_settings');
    }
};
