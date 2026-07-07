@extends('layouts.admin')

@section('title')
    {{ $node->name }}: Network Attack Analysis
@endsection

@section('content-header')
    <h1>{{ $node->name }}<small>Real-time node-wide Network Attack Analysis & Metrics.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.nodes') }}">Nodes</a></li>
        <li><a href="{{ route('admin.nodes.view', $node->id) }}">{{ $node->name }}</a></li>
        <li class="active">Attack Analysis</li>
    </ol>
@endsection

@section('content')
<div class="row">
    <div class="col-xs-12">
        <div class="nav-tabs-custom nav-tabs-floating">
            <ul class="nav nav-tabs">
                <li><a href="{{ route('admin.nodes.view', $node->id) }}">About</a></li>
                <li><a href="{{ route('admin.nodes.view.settings', $node->id) }}">Settings</a></li>
                <li><a href="{{ route('admin.nodes.view.configuration', $node->id) }}">Configuration</a></li>
                <li><a href="{{ route('admin.nodes.view.allocation', $node->id) }}">Allocation</a></li>
                <li><a href="{{ route('admin.nodes.view.servers', $node->id) }}">Servers</a></li>
                <li class="active"><a href="{{ route('admin.nodes.view.attack', $node->id) }}">🛡 Attack Analysis</a></li>
            </ul>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-3 col-sm-6 col-xs-12">
        <div class="info-box bg-aqua">
            <span class="info-box-icon"><i class="fa fa-arrow-down"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Incoming PPS</span>
                <span class="info-box-number" id="incoming_pps">{{ number_format($latest->rx_pps) }} pps</span>
                <div class="progress"><div class="progress-bar" style="width: 35%"></div></div>
                <span class="progress-description">Live throughput packet stream</span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 col-xs-12">
        <div class="info-box bg-yellow">
            <span class="info-box-icon"><i class="fa fa-arrow-up"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Outgoing PPS</span>
                <span class="info-box-number" id="outgoing_pps">{{ number_format($latest->tx_pps) }} pps</span>
                <div class="progress"><div class="progress-bar" style="width: 20%"></div></div>
                <span class="progress-description">Outbound network packets</span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 col-xs-12">
        <div class="info-box bg-green">
            <span class="info-box-icon"><i class="fa fa-shield"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Active Connections</span>
                <span class="info-box-number" id="active_connections">{{ number_format($latest->connections_count) }}</span>
                <div class="progress"><div class="progress-bar" style="width: 50%"></div></div>
                <span class="progress-description">Concurrent active sockets</span>
            </div>
        </div>
    </div>
    <div class="col-md-3 col-sm-6 col-xs-12">
        <div class="info-box bg-red">
            <span class="info-box-icon"><i class="fa fa-exclamation-triangle"></i></span>
            <div class="info-box-content">
                <span class="info-box-text">Security Status</span>
                <span class="info-box-number" id="ddos_status">Protected</span>
                <div class="progress"><div class="progress-bar" style="width: 100%"></div></div>
                <span class="progress-description">All traffic parameters normal</span>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <div class="box box-primary">
            <div class="box-header with-border">
                <h3 class="box-title">Bandwidth Usage (Live)</h3>
            </div>
            <div class="box-body">
                <canvas id="bandwidthChart" style="height: 250px; width: 100%;"></canvas>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="box box-success">
            <div class="box-header with-border">
                <h3 class="box-title">Packets Per Second (PPS)</h3>
            </div>
            <div class="box-body">
                <canvas id="ppsChart" style="height: 250px; width: 100%;"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <div class="box box-warning">
            <div class="box-header with-border">
                <h3 class="box-title">GeoIP / Top Countries</h3>
            </div>
            <div class="box-body">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Country</th>
                            <th>IPs</th>
                            <th>PPS</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($latest->top_talkers['ips'] as $ip)
                            <tr>
                                <td><strong>{{ $ip['country'] }}</strong></td>
                                <td><code>{{ $ip['ip'] }}</code></td>
                                <td>{{ number_format($ip['pps']) }} pps</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title">Top Targeted Ports</h3>
            </div>
            <div class="box-body">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>Port</th>
                            <th>Protocol</th>
                            <th>Throughput</th>
                            <th>Connections</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($latest->top_talkers['ports'] as $port)
                            <tr>
                                <td><span class="label label-primary">{{ $port['port'] }}</span></td>
                                <td>{{ $port['protocol'] }}</td>
                                <td>{{ $port['mbps'] }} Mbps</td>
                                <td>{{ number_format($port['connections']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="box box-danger">
            <div class="box-header with-border">
                <h3 class="box-title">Recent DDoS Alerts (Node-Wide)</h3>
            </div>
            <div class="box-body">
                <ul class="todo-list">
                    @forelse($events as $event)
                        <li style="border-left: 3px solid #dd4b39; padding: 10px; margin-bottom: 5px; background: #fafafa;">
                            <span class="text" style="font-weight: bold; color: #dd4b39;">{{ $event->attack_type }}</span>
                            <small class="label label-danger"><i class="fa fa-clock-o"></i> {{ $event->started_at->diffForHumans() }}</small>
                            <div style="font-size: 11px; margin-top: 5px; color: #777;">
                                Protocol: {{ $event->protocol }} | Target Port: {{ $event->target_port }} | Peak: {{ $event->peak_mbps }} Mbps
                            </div>
                        </li>
                    @empty
                        <li style="padding: 10px; text-align: center; color: #999;">No active or past attacks logged.</li>
                    @endforelse
                </ul>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    const bandwidthCtx = document.getElementById('bandwidthChart').getContext('2d');
    const ppsCtx = document.getElementById('ppsChart').getContext('2d');

    const labels = [];
    const rxData = [];
    const txData = [];
    const rxPpsData = [];
    const txPpsData = [];

    // Prepopulate charts
    for (let i = 0; i < 15; i++) {
        labels.push(i + 's');
        rxData.push(Math.random() * 20 + 20);
        txData.push(Math.random() * 10 + 10);
        rxPpsData.push(Math.random() * 5000 + 15000);
        txPpsData.push(Math.random() * 2000 + 8000);
    }

    const bandwidthChart = new Chart(bandwidthCtx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: 'Incoming (MB/s)', data: rxData, borderColor: '#00c0ef', backgroundColor: 'rgba(0, 192, 239, 0.1)', fill: true },
                { label: 'Outgoing (MB/s)', data: txData, borderColor: '#f39c12', backgroundColor: 'rgba(243, 156, 18, 0.1)', fill: true }
            ]
        },
        options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });

    const ppsChart = new Chart(ppsCtx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [
                { label: 'Incoming PPS', data: rxPpsData, borderColor: '#00a65a', backgroundColor: 'rgba(0, 166, 90, 0.1)', fill: true },
                { label: 'Outgoing PPS', data: txPpsData, borderColor: '#dd4b39', backgroundColor: 'rgba(221, 75, 57, 0.1)', fill: true }
            ]
        },
        options: { responsive: true, scales: { y: { beginAtZero: true } } }
    });

    // Simulated live updates
    setInterval(() => {
        const rx = (Math.random() * 10 + 20).toFixed(1);
        const tx = (Math.random() * 5 + 10).toFixed(1);
        const rxPps = Math.floor(Math.random() * 4000 + 16000);
        const txPps = Math.floor(Math.random() * 2000 + 9000);

        // Update headers
        document.getElementById('incoming_pps').innerText = rxPps.toLocaleString() + ' pps';
        document.getElementById('outgoing_pps').innerText = txPps.toLocaleString() + ' pps';
        document.getElementById('active_connections').innerText = Math.floor(Math.random() * 50 + 2200).toLocaleString();

        bandwidthChart.data.datasets[0].data.shift();
        bandwidthChart.data.datasets[0].data.push(rx);
        bandwidthChart.data.datasets[1].data.shift();
        bandwidthChart.data.datasets[1].data.push(tx);
        bandwidthChart.update();

        ppsChart.data.datasets[0].data.shift();
        ppsChart.data.datasets[0].data.push(rxPps);
        ppsChart.data.datasets[1].data.shift();
        ppsChart.data.datasets[1].data.push(txPps);
        ppsChart.update();
    }, 3000);
</script>
@endsection
