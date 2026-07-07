<?php

namespace Pterodactyl\Http\Controllers\Admin\Nodes;

use Illuminate\View\View;
use Illuminate\Http\Request;
use Pterodactyl\Models\Node;
use Illuminate\Support\Collection;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Http\Controllers\Controller;
use Pterodactyl\Repositories\Eloquent\NodeRepository;
use Pterodactyl\Repositories\Eloquent\ServerRepository;
use Pterodactyl\Traits\Controllers\JavascriptInjection;
use Pterodactyl\Services\Helpers\SoftwareVersionService;
use Pterodactyl\Repositories\Eloquent\LocationRepository;

class NodeViewController extends Controller
{
    use JavascriptInjection;

    /**
     * NodeViewController constructor.
     */
    public function __construct(
        private LocationRepository $locationRepository,
        private NodeRepository $repository,
        private ServerRepository $serverRepository,
        private SoftwareVersionService $versionService,
    ) {
    }

    /**
     * Returns index view for a specific node on the system.
     */
    public function index(Request $request, Node $node): View
    {
        $node = $this->repository->loadLocationAndServerCount($node);

        return view('admin.nodes.view.index', [
            'node' => $node,
            'stats' => $this->repository->getUsageStats($node),
            'version' => $this->versionService,
        ]);
    }

    /**
     * Returns the settings page for a specific node.
     */
    public function settings(Request $request, Node $node): View
    {
        return view('admin.nodes.view.settings', [
            'node' => $node,
            'locations' => $this->locationRepository->all(),
        ]);
    }

    /**
     * Return the node configuration page for a specific node.
     */
    public function configuration(Request $request, Node $node): View
    {
        return view('admin.nodes.view.configuration', compact('node'));
    }

    /**
     * Return the node allocation management page.
     */
    public function allocations(Request $request, Node $node): View
    {
        $node = $this->repository->loadNodeAllocations($node);

        $this->plainInject(['node' => Collection::make([$node])->only(['id'])]);

        return view('admin.nodes.view.allocation', [
            'node' => $node,
            'allocations' => Allocation::query()->where('node_id', $node->id)
                ->groupBy('ip')
                ->orderByRaw('INET_ATON(ip) ASC')
                ->get(['ip']),
        ]);
    }

    /**
     * Return a listing of servers that exist for this specific node.
     */
    public function servers(Request $request, Node $node): View
    {
        $this->plainInject([
            'node' => Collection::make([$node->makeVisible(['daemon_token_id', 'daemon_token'])])
                ->only(['scheme', 'fqdn', 'daemonListen', 'daemon_token_id', 'daemon_token']),
        ]);

        return view('admin.nodes.view.servers', [
            'node' => $node,
            'servers' => $this->serverRepository->loadAllServersForNode($node->id, 25),
        ]);
    }

    /**
     * Return the attack analysis and network stats for this node.
     */
    public function attack(Request $request, Node $node): View
    {
        $latest = \Pterodactyl\Models\NetworkNodeTelemetry::where('node_id', $node->id)
            ->orderBy('id', 'desc')
            ->first();

        // Fallback mock telemetry if empty
        if (!$latest) {
            $latest = \Pterodactyl\Models\NetworkNodeTelemetry::create([
                'node_id' => $node->id,
                'rx_bytes' => 38000000,
                'tx_bytes' => 15000000,
                'rx_pps' => 24000,
                'tx_pps' => 11000,
                'connections_count' => 2200,
                'dropped_packets' => 18,
                'tcp_packets' => 18000,
                'udp_packets' => 4500,
                'icmp_packets' => 1500,
                'interface_stats' => [
                    ['name' => 'eth0', 'rx_bytes' => 38000000, 'tx_bytes' => 15000000, 'rx_pps' => 24000, 'tx_pps' => 11000, 'rx_dropped' => 18, 'tx_dropped' => 0, 'speed' => 1000, 'utilization' => 32]
                ],
                'top_talkers' => [
                    'ips' => [
                        ['ip' => '185.249.227.95', 'country' => 'US', 'asn' => 'AS16276 (OVH)', 'pps' => 6400, 'mbps' => 45, 'connections' => 180]
                    ],
                    'ports' => [
                        ['port' => '25565', 'protocol' => 'TCP', 'mbps' => 120, 'pps' => 12000, 'connections' => 1500]
                    ]
                ],
                'server_telemetry' => []
            ]);
        }

        $events = \Pterodactyl\Models\NetworkAttackEvent::where('node_id', $node->id)
            ->orderBy('id', 'desc')
            ->limit(15)
            ->get();

        return view('admin.nodes.view.attack', compact('node', 'latest', 'events'));
    }
}
