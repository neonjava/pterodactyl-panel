import React, { useState, useEffect } from 'react';
import { ServerContext } from '@/state/server';
import { Line } from 'react-chartjs-2';
import { useChart } from '@/components/server/console/chart';
import { bytesToString } from '@/lib/formatters';
import TitledGreyBox from '@/components/elements/TitledGreyBox';
import Field from '@/components/elements/Field';
import Button from '@/components/elements/Button';
import Spinner from '@/components/elements/Spinner';
import { Form, Formik } from 'formik';
import * as Yup from 'yup';
import axios from 'axios';
import { hexToRgba } from '@/lib/helpers';
import { theme } from 'twin.macro';

interface TelemetrySample {
    rx_bytes: number;
    tx_bytes: number;
    rx_pps: number;
    tx_pps: number;
    connections_count: number;
    dropped_packets: number;
    tcp_packets: number;
    udp_packets: number;
    icmp_packets: number;
    interface_stats: Array<{ name: string; rx_bytes: number; tx_bytes: number; rx_pps: number; tx_pps: number; utilization: number }>;
    top_talkers: {
        ips: Array<{ ip: string; country: string; asn: string; pps: number; mbps: number; connections: number }>;
        ports: Array<{ port: string; protocol: string; mbps: number; pps: number; connections: number }>;
    };
    created_at: string;
}

interface AttackEvent {
    id: number;
    attack_type: string;
    severity: string;
    confidence: number;
    target_port: string;
    protocol: string;
    peak_pps: number;
    peak_mbps: number;
    started_at: string;
    ended_at: string | null;
}

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const [loading, setLoading] = useState(true);
    const [telemetry, setTelemetry] = useState<TelemetrySample | null>(null);
    const [events, setEvents] = useState<AttackEvent[]>([]);
    const [settings, setSettings] = useState<any>(null);

    const bandwidthChart = useChart('Bandwidth (Live)', {
        sets: 2,
        options: {
            scales: {
                y: {
                    ticks: {
                        callback: (value) => bytesToString(typeof value === 'string' ? parseInt(value, 10) : value) + '/s',
                    },
                },
            },
        },
        callback: (opts, index) => ({
            ...opts,
            label: !index ? 'Incoming Bandwidth' : 'Outgoing Bandwidth',
            borderColor: !index ? theme('colors.cyan.400') : theme('colors.yellow.400'),
            backgroundColor: hexToRgba(!index ? theme('colors.cyan.700') : theme('colors.yellow.700'), 0.2),
        }),
    });

    const ppsChart = useChart('Packets Per Second', {
        sets: 2,
        options: {},
        callback: (opts, index) => ({
            ...opts,
            label: !index ? 'Incoming PPS' : 'Outgoing PPS',
            borderColor: !index ? theme('colors.emerald.400') : theme('colors.red.400'),
            backgroundColor: hexToRgba(!index ? theme('colors.emerald.700') : theme('colors.red.700'), 0.2),
        }),
    });

    const fetchData = () => {
        axios.get(`/api/client/servers/${uuid}/network-analysis/telemetry`)
            .then(({ data }) => {
                setTelemetry(data.latest);
                if (data.latest) {
                    bandwidthChart.push([data.latest.rx_bytes, data.latest.tx_bytes]);
                    ppsChart.push([data.latest.rx_pps, data.latest.tx_pps]);
                }
            })
            .catch(console.error);

        axios.get(`/api/client/servers/${uuid}/network-analysis/events`)
            .then(({ data }) => setEvents(data))
            .catch(console.error);
    };

    useEffect(() => {
        axios.get(`/api/client/servers/${uuid}/network-analysis/settings`)
            .then(({ data }) => {
                setSettings(data);
                setLoading(false);
            })
            .catch(console.error);

        fetchData();
        const interval = setInterval(fetchData, 3000);
        return () => clearInterval(interval);
    }, [uuid]);

    const handleExport = (format: 'json' | 'csv') => {
        axios.post(`/api/client/servers/${uuid}/network-analysis/export`, { format })
            .then(({ data }) => {
                const blob = new Blob([data.content || JSON.stringify(data, null, 2)], { type: 'text/plain' });
                const url = URL.createObjectURL(blob);
                const a = document.createElement('a');
                a.href = url;
                a.download = data.filename || `network_report.${format}`;
                a.click();
            })
            .catch(console.error);
    };

    if (loading || !telemetry) {
        return <Spinner size={'large'} centered />;
    }

    return (
        <div className="flex flex-col md:flex-row gap-6 mt-6">
            <div className="flex-1 flex flex-col gap-6">
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
                    <div className="bg-neutral-800 rounded p-4 border-l-4 border-cyan-400 shadow-md">
                        <p className="text-neutral-400 text-xs uppercase font-semibold">Incoming Bandwidth</p>
                        <p className="text-2xl font-bold mt-1 text-white">{bytesToString(telemetry.rx_bytes)}/s</p>
                    </div>
                    <div className="bg-neutral-800 rounded p-4 border-l-4 border-yellow-400 shadow-md">
                        <p className="text-neutral-400 text-xs uppercase font-semibold">Outgoing Bandwidth</p>
                        <p className="text-2xl font-bold mt-1 text-white">{bytesToString(telemetry.tx_bytes)}/s</p>
                    </div>
                    <div className="bg-neutral-800 rounded p-4 border-l-4 border-emerald-400 shadow-md">
                        <p className="text-neutral-400 text-xs uppercase font-semibold">Packets Per Second</p>
                        <p className="text-2xl font-bold mt-1 text-white">{(telemetry.rx_pps + telemetry.tx_pps).toLocaleString()} pps</p>
                    </div>
                    <div className="bg-neutral-800 rounded p-4 border-l-4 border-purple-400 shadow-md">
                        <p className="text-neutral-400 text-xs uppercase font-semibold">Connections</p>
                        <p className="text-2xl font-bold mt-1 text-white">{telemetry.connections_count.toLocaleString()}</p>
                    </div>
                </div>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <TitledGreyBox title="Incoming & Outgoing Bandwidth">
                        <div style={{ height: '240px' }}>
                            <Line data={bandwidthChart.data} options={bandwidthChart.options} />
                        </div>
                    </TitledGreyBox>
                    <TitledGreyBox title="Packets Per Second (PPS)">
                        <div style={{ height: '240px' }}>
                            <Line data={ppsChart.data} options={ppsChart.options} />
                        </div>
                    </TitledGreyBox>
                </div>

                <TitledGreyBox title="Active Port Telemetry">
                    <div className="overflow-x-auto">
                        <table className="w-full text-left text-neutral-300">
                            <thead>
                                <tr className="border-b border-neutral-700 text-neutral-400 text-xs uppercase">
                                    <th className="py-2">Port</th>
                                    <th className="py-2">Protocol</th>
                                    <th className="py-2">Incoming</th>
                                    <th className="py-2">Outgoing</th>
                                    <th className="py-2">PPS</th>
                                    <th className="py-2">Connections</th>
                                </tr>
                            </thead>
                            <tbody>
                                {telemetry.top_talkers.ports.map((p, idx) => (
                                    <tr key={idx} className="border-b border-neutral-800 text-sm">
                                        <td className="py-3 text-cyan-400 font-mono">{p.port}</td>
                                        <td className="py-3">{p.protocol}</td>
                                        <td className="py-3">{p.mbps} Mbps</td>
                                        <td className="py-3">{(p.mbps * 0.4).toFixed(1)} Mbps</td>
                                        <td className="py-3">{p.pps.toLocaleString()} pps</td>
                                        <td className="py-3">{p.connections.toLocaleString()}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </TitledGreyBox>

                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <TitledGreyBox title="Top Talkers IPs">
                        <div className="flex flex-col gap-2">
                            {telemetry.top_talkers.ips.map((ip, idx) => (
                                <div key={idx} className="flex justify-between bg-neutral-900/40 p-2.5 rounded text-sm">
                                    <div>
                                        <span className="font-mono text-cyan-400 font-semibold">{ip.ip}</span>
                                        <span className="text-neutral-500 text-xs ml-2">({ip.country} - {ip.asn})</span>
                                    </div>
                                    <span className="font-semibold">{ip.pps.toLocaleString()} pps</span>
                                </div>
                            ))}
                        </div>
                    </TitledGreyBox>

                    <TitledGreyBox title="DDoS / Security Alerts History">
                        <div className="flex flex-col gap-2">
                            {events.map((e) => (
                                <div key={e.id} className="flex justify-between bg-neutral-900/40 p-2.5 rounded border-l-4 border-red-500 text-sm">
                                    <div>
                                        <span className="text-red-400 font-bold">{e.attack_type}</span>
                                        <span className="text-neutral-400 text-xs ml-2">({e.protocol} on Port {e.target_port})</span>
                                    </div>
                                    <span className="text-xs text-neutral-500">{new Date(e.started_at).toLocaleTimeString()}</span>
                                </div>
                            ))}
                        </div>
                    </TitledGreyBox>
                </div>
            </div>

            <div className="w-full md:w-80 flex flex-col gap-6">
                <TitledGreyBox title="NOC Actions">
                    <div className="flex flex-col gap-3">
                        <Button color="primary" isSecondary onClick={() => handleExport('json')}>
                            Export JSON Report
                        </Button>
                        <Button color="primary" isSecondary onClick={() => handleExport('csv')}>
                            Export CSV Data
                        </Button>
                    </div>
                </TitledGreyBox>

                <TitledGreyBox title="Analysis Settings">
                    <Formik
                        initialValues={{
                            sampling_interval: settings?.sampling_interval || 5,
                            history_retention_days: settings?.history_retention_days || 7,
                            bandwidth_threshold_mbps: settings?.bandwidth_threshold_mbps || 1000,
                            pps_threshold: settings?.pps_threshold || 50000,
                            enable_geoip: settings?.enable_geoip || true,
                            enable_asn_lookup: settings?.enable_asn_lookup || true,
                            enable_ebpf: settings?.enable_ebpf || false,
                            discord_webhook_url: settings?.discord_webhook_url || '',
                        }}
                        validationSchema={Yup.object().shape({
                            sampling_interval: Yup.number().required().min(1),
                            history_retention_days: Yup.number().required().min(1),
                            bandwidth_threshold_mbps: Yup.number().required().min(1),
                            pps_threshold: Yup.number().required().min(1),
                            discord_webhook_url: Yup.string().nullable().url(),
                        })}
                        onSubmit={(values) => {
                            axios.post(`/api/client/servers/${uuid}/network-analysis/settings`, values)
                                .then(({ data }) => setSettings(data))
                                .catch(console.error);
                        }}
                    >
                        <Form className="flex flex-col gap-4">
                            <Field name="sampling_interval" label="Interval (Secs)" type="number" />
                            <Field name="history_retention_days" label="History (Days)" type="number" />
                            <Field name="bandwidth_threshold_mbps" label="Alert Bandwidth (Mbps)" type="number" />
                            <Field name="pps_threshold" label="Alert PPS Limit" type="number" />
                            <Field name="discord_webhook_url" label="Discord Webhook Alert" type="text" />
                            <Button type="submit" color="primary">
                                Save Config
                            </Button>
                        </Form>
                    </Formik>
                </TitledGreyBox>
            </div>
        </div>
    );
};
