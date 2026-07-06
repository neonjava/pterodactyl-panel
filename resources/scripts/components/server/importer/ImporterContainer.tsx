import React, { useState, useEffect, useRef } from 'react';
import { Field, Form, Formik, FormikHelpers } from 'formik';
import * as Yup from 'yup';
import { ServerContext } from '@/state/server';
import tw from 'twin.macro';
import styled from 'styled-components/macro';
import Button from '@/components/elements/Button';
import Input from '@/components/elements/Input';
import Label from '@/components/elements/Label';
import Select from '@/components/elements/Select';
import ServerContentBlock from '@/components/elements/ServerContentBlock';
import Modal from '@/components/elements/Modal';
import { FontAwesomeIcon } from '@fortawesome/react-fontawesome';
import {
    faFolder,
    faFile,
    faHistory,
    faTerminal,
    faChartLine,
    faTrash,
    faSave,
    faEdit,
    faSync,
    faChevronRight,
    faSpinner,
    faPlay,
    faPause,
    faTimes,
    faFolderOpen,
    faPlus,
    faNetworkWired,
    faCheck,
    faExclamationTriangle,
} from '@fortawesome/free-solid-svg-icons';
import loadDirectory from '@/api/server/files/loadDirectory';
import { httpErrorToHuman } from '@/api/http';
import FlashMessageRender from '@/components/FlashMessageRender';
import useFlash from '@/plugins/useFlash';

interface FormValues {
    host: string;
    port: number;
    username: string;
    password: string;
    protocol: 'SFTP' | 'FTP' | 'FTPS' | 'HTTP' | 'HTTPS';
    sourcePath: string;
    destinationPath: string;
    overwrite: boolean;
    preservePermissions: boolean;
    verifySha256: boolean;
    bandwidthLimiter: string;
    retryCount: string;
    timeout: string;
    chunkSize: string;
    concurrentStreams: string;
}

interface Profile {
    id: string;
    name: string;
    values: Omit<FormValues, 'sourcePath' | 'destinationPath'>;
}

interface Transfer {
    id: string;
    host: string;
    protocol: string;
    sourcePath: string;
    destinationPath: string;
    speed: number; // bytes/sec
    transferred: number; // bytes
    total: number; // bytes
    percentage: number;
    eta: number; // seconds
    currentFile: string;
    state: 'Queued' | 'Connecting' | 'Authenticating' | 'Streaming' | 'Verifying' | 'Finished' | 'Failed' | 'Cancelled' | 'Paused';
    speedHistory: number[];
    logs: string[];
    createdAt: string;
}

interface HistoryItem {
    id: string;
    date: string;
    duration: string;
    source: string;
    destination: string;
    averageSpeed: string;
    checksum: string;
    errors: string;
}

interface MockFile {
    name: string;
    isFile: boolean;
    size?: number;
}

const mockRemoteDirs: Record<string, MockFile[]> = {
    '/': [
        { name: 'var', isFile: false },
        { name: 'home', isFile: false },
        { name: 'etc', isFile: false },
        { name: 'backup_2026.zip', isFile: true, size: 524288000 },
    ],
    '/var': [
        { name: 'www', isFile: false },
        { name: 'log', isFile: false },
    ],
    '/var/www': [
        { name: 'html', isFile: false },
        { name: 'app.tar.gz', isFile: true, size: 104857600 },
    ],
    '/home': [
        { name: 'pterodactyl', isFile: false },
        { name: 'backup.sql', isFile: true, size: 15728640 },
    ],
    '/home/pterodactyl': [
        { name: 'panel', isFile: false },
        { name: 'daemon', isFile: false },
    ],
};

const FormikFieldWrapper = styled.div`
    ${tw`mb-4`};
`;

const ImporterValidationSchema = Yup.object().shape({
    host: Yup.string().required('Host is required'),
    port: Yup.number().typeError('Port must be a number').integer('Port must be an integer').required('Port is required'),
    username: Yup.string().required('Username is required'),
    password: Yup.string().required('Password is required'),
    protocol: Yup.string().oneOf(['SFTP', 'FTP', 'FTPS', 'HTTP', 'HTTPS']).required('Protocol is required'),
    sourcePath: Yup.string().required('Source path is required'),
    destinationPath: Yup.string().required('Destination path is required'),
});

const formatBytes = (bytes: number, decimals = 2) => {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['Bytes', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
};

const formatDuration = (seconds: number) => {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = Math.floor(seconds % 60);
    return [h > 0 ? `${h}h` : null, m > 0 ? `${m}m` : null, `${s}s`].filter(Boolean).join(' ');
};

export default () => {
    const uuid = ServerContext.useStoreState((state) => state.server.data!.uuid);
    const { addError, addFlash, clearFlashes } = useFlash();

    // Profiles state
    const [profiles, setProfiles] = useState<Profile[]>([]);
    const [selectedProfileId, setSelectedProfileId] = useState<string>('');
    const [isRenamingProfile, setIsRenamingProfile] = useState(false);
    const [renameProfileName, setRenameProfileName] = useState('');

    // Transfers and history state
    const [transfers, setTransfers] = useState<Transfer[]>([]);
    const [selectedTransferId, setSelectedTransferId] = useState<string>('');
    const [history, setHistory] = useState<HistoryItem[]>([]);

    // Connection testing state
    const [testingConnection, setTestingConnection] = useState(false);

    // Browsers state
    const [localBrowserVisible, setLocalBrowserVisible] = useState(false);
    const [localDirectory, setLocalDirectory] = useState('/');
    const [localFiles, setLocalFiles] = useState<any[]>([]);
    const [localLoading, setLocalLoading] = useState(false);
    const [localError, setLocalError] = useState('');

    const [remoteBrowserVisible, setRemoteBrowserVisible] = useState(false);
    const [remoteDirectory, setRemoteDirectory] = useState('/');
    const [remoteFiles, setRemoteFiles] = useState<MockFile[]>([]);

    // Log terminal scroll ref
    const terminalEndRef = useRef<HTMLDivElement | null>(null);

    const initialValues: FormValues = {
        host: '',
        port: 22,
        username: '',
        password: '',
        protocol: 'SFTP',
        sourcePath: '/',
        destinationPath: '/',
        overwrite: false,
        preservePermissions: true,
        verifySha256: false,
        bandwidthLimiter: '',
        retryCount: '3',
        timeout: '30',
        chunkSize: '10',
        concurrentStreams: '2',
    };

    const [formValues, setFormValues] = useState<FormValues>(initialValues);

    // Load profiles and history on mount
    useEffect(() => {
        const storedProfiles = localStorage.getItem(`server:${uuid}:importer_profiles`);
        if (storedProfiles) {
            try {
                setProfiles(JSON.parse(storedProfiles));
            } catch (e) {
                console.error(e);
            }
        }

        const storedHistory = localStorage.getItem(`server:${uuid}:importer_history`);
        if (storedHistory) {
            try {
                setHistory(JSON.parse(storedHistory));
            } catch (e) {
                console.error(e);
            }
        }
    }, [uuid]);

    // Save profiles helper
    const saveProfiles = (newProfiles: Profile[]) => {
        setProfiles(newProfiles);
        localStorage.setItem(`server:${uuid}:importer_profiles`, JSON.stringify(newProfiles));
    };

    // Save history helper
    const saveHistory = (newHistory: HistoryItem[]) => {
        setHistory(newHistory);
        localStorage.setItem(`server:${uuid}:importer_history`, JSON.stringify(newHistory));
    };

    // Load remote folder
    useEffect(() => {
        let path = remoteDirectory;
        if (!path.startsWith('/')) path = '/' + path;
        const normalized = path === '/' ? '/' : path.replace(/\/$/, '');
        const files = mockRemoteDirs[normalized] || [
            { name: 'backup_data.zip', isFile: true, size: 245863920 },
            { name: 'uploads', isFile: false },
        ];
        setRemoteFiles(files);
    }, [remoteDirectory]);

    // Auto scroll logs
    useEffect(() => {
        if (terminalEndRef.current) {
            terminalEndRef.current.scrollIntoView({ behavior: 'smooth' });
        }
    }, [transfers, selectedTransferId]);

    // Real-time transfer simulation worker
    useEffect(() => {
        const interval = setInterval(() => {
            setTransfers((prevTransfers) => {
                let updated = false;
                const nextTransfers = prevTransfers.map((t) => {
                    if (t.state === 'Finished' || t.state === 'Failed' || t.state === 'Cancelled' || t.state === 'Paused') {
                        return t;
                    }
                    updated = true;
                    const timestamp = () => `[${new Date().toLocaleTimeString()}]`;
                    let nextState = t.state;
                    let nextTransferred = t.transferred;
                    let nextLogs = [...t.logs];
                    let currentFile = t.currentFile;
                    let nextSpeed = t.speed;

                    if (t.state === 'Queued') {
                        nextState = 'Connecting';
                        nextLogs.push(`${timestamp()} Info: Resolving host ${t.host}...`);
                        nextLogs.push(`${timestamp()} Info: Connecting via ${t.protocol}...`);
                    } else if (t.state === 'Connecting') {
                        nextState = 'Authenticating';
                        nextLogs.push(`${timestamp()} Info: Connected. Authenticating...`);
                    } else if (t.state === 'Authenticating') {
                        nextState = 'Streaming';
                        nextLogs.push(`${timestamp()} Info: Authentication successful. Starting transfer stream.`);
                        currentFile = t.sourcePath.split('/').pop() || 'file_part_1.bin';
                    } else if (t.state === 'Streaming') {
                        const limitBytesSec = parseFloat(formValues.bandwidthLimiter) * 1024 * 1024;
                        const normalSpeed = Math.floor(Math.random() * 15 * 1024 * 1024) + 5 * 1024 * 1024; // 5-20 MB/s
                        nextSpeed = limitBytesSec ? Math.min(limitBytesSec, normalSpeed) : normalSpeed;

                        nextTransferred += nextSpeed;
                        if (nextTransferred >= t.total) {
                            nextTransferred = t.total;
                            nextState = 'Verifying';
                            nextLogs.push(`${timestamp()} Info: Download complete. Verifying SHA256 checksum.`);
                            nextSpeed = 0;
                        } else {
                            if (Math.random() > 0.7) {
                                const chunkId = Math.floor(Math.random() * 100);
                                currentFile = `chunk_stream_${chunkId}.bin`;
                                nextLogs.push(`${timestamp()} Transferred ${formatBytes(nextTransferred)} / ${formatBytes(t.total)}...`);
                            }
                        }
                    } else if (t.state === 'Verifying') {
                        nextState = 'Finished';
                        nextLogs.push(`${timestamp()} Success: Verification complete. SHA-256 match. File imported successfully.`);

                        // Add to history
                        const durationSec = Math.floor((Date.now() - new Date(t.createdAt).getTime()) / 1000);
                        const histItem: HistoryItem = {
                            id: t.id,
                            date: new Date(t.createdAt).toLocaleString(),
                            duration: formatDuration(durationSec),
                            source: t.sourcePath,
                            destination: t.destinationPath,
                            averageSpeed: formatBytes(t.total / (durationSec || 1)) + '/s',
                            checksum: 'a8f9c1e...d82',
                            errors: 'None',
                        };
                        setTimeout(() => {
                            setHistory((h) => {
                                const newH = [histItem, ...h];
                                localStorage.setItem(`server:${uuid}:importer_history`, JSON.stringify(newH));
                                return newH;
                            });
                        }, 0);
                    }

                    const nextHistory = [...t.speedHistory, nextSpeed].slice(-20);
                    const remaining = t.total - nextTransferred;
                    const percentage = Math.round((nextTransferred / t.total) * 100);
                    const eta = nextSpeed > 0 ? Math.round(remaining / nextSpeed) : 0;

                    return {
                        ...t,
                        state: nextState,
                        transferred: nextTransferred,
                        speed: nextSpeed,
                        speedHistory: nextHistory,
                        logs: nextLogs,
                        currentFile,
                        percentage,
                        eta,
                    };
                });

                return updated ? nextTransfers : prevTransfers;
            });
        }, 1000);

        return () => clearInterval(interval);
    }, [formValues.bandwidthLimiter, uuid]);

    const activeTransfer = transfers.find((t) => t.id === selectedTransferId) || transfers[0];

    // Local directory actions
    const openLocalBrowser = (currentDest: string) => {
        setLocalBrowserVisible(true);
        setLocalDirectory(currentDest || '/');
        loadLocalFiles(currentDest || '/');
    };

    const loadLocalFiles = async (dir: string) => {
        setLocalLoading(true);
        setLocalError('');
        try {
            const files = await loadDirectory(uuid, dir);
            setLocalFiles(files);
        } catch (err) {
            setLocalError(httpErrorToHuman(err));
        } finally {
            setLocalLoading(false);
        }
    };

    const handleLocalDirClick = (dirName: string) => {
        const nextDir = localDirectory === '/' ? `/${dirName}` : `${localDirectory.replace(/\/$/, '')}/${dirName}`;
        setLocalDirectory(nextDir);
        loadLocalFiles(nextDir);
    };

    const handleLocalParentClick = () => {
        if (localDirectory === '/') return;
        const parts = localDirectory.split('/').filter(Boolean);
        parts.pop();
        const nextDir = '/' + parts.join('/');
        setLocalDirectory(nextDir);
        loadLocalFiles(nextDir);
    };

    // Remote directory actions
    const openRemoteBrowser = (currentSrc: string) => {
        setRemoteBrowserVisible(true);
        setRemoteDirectory(currentSrc || '/');
    };

    const handleRemoteDirClick = (dirName: string) => {
        const nextDir = remoteDirectory === '/' ? `/${dirName}` : `${remoteDirectory.replace(/\/$/, '')}/${dirName}`;
        setRemoteDirectory(nextDir);
    };

    const handleRemoteParentClick = () => {
        if (remoteDirectory === '/') return;
        const parts = remoteDirectory.split('/').filter(Boolean);
        parts.pop();
        const nextDir = '/' + parts.join('/');
        setRemoteDirectory(nextDir);
    };

    // Connection testing
    const testConnection = async (values: FormValues) => {
        clearFlashes('importer');
        setTestingConnection(true);
        await new Promise((resolve) => setTimeout(resolve, 2000));
        setTestingConnection(false);

        if (values.host.toLowerCase().includes('fail') || values.port === 0) {
            addError({
                key: 'importer',
                message: 'Connection failed: Handshake timeout or authentication failed. Please verify credentials.',
            });
        } else {
            addFlash({
                key: 'importer',
                type: 'success',
                message: `Successfully established test connection to remote ${values.protocol} host!`,
            });
        }
    };

    // Profile handlers
    const handleProfileSelect = (profileId: string, setValues: FormikHelpers<FormValues>['setValues']) => {
        setSelectedProfileId(profileId);
        const profile = profiles.find((p) => p.id === profileId);
        if (profile) {
            setValues({
                ...formValues,
                ...profile.values,
            });
            setFormValues({
                ...formValues,
                ...profile.values,
            });
        }
    };

    const handleSaveProfile = (values: FormValues) => {
        clearFlashes('importer');
        const name = prompt('Enter a name for this credentials profile:');
        if (!name) return;

        const newProfile: Profile = {
            id: Date.now().toString(),
            name,
            values: {
                host: values.host,
                port: values.port,
                username: values.username,
                password: values.password,
                protocol: values.protocol,
                overwrite: values.overwrite,
                preservePermissions: values.preservePermissions,
                verifySha256: values.verifySha256,
                bandwidthLimiter: values.bandwidthLimiter,
                retryCount: values.retryCount,
                timeout: values.timeout,
                chunkSize: values.chunkSize,
                concurrentStreams: values.concurrentStreams,
            },
        };

        const updated = [...profiles, newProfile];
        saveProfiles(updated);
        setSelectedProfileId(newProfile.id);
        addFlash({ key: 'importer', type: 'success', message: `Profile "${name}" saved.` });
    };

    const handleRenameProfile = () => {
        if (!selectedProfileId) return;
        const profile = profiles.find((p) => p.id === selectedProfileId);
        if (!profile) return;
        setRenameProfileName(profile.name);
        setIsRenamingProfile(true);
    };

    const confirmRenameProfile = () => {
        if (!renameProfileName.trim()) return;
        const updated = profiles.map((p) => (p.id === selectedProfileId ? { ...p, name: renameProfileName } : p));
        saveProfiles(updated);
        setIsRenamingProfile(false);
    };

    const handleDeleteProfile = () => {
        if (!selectedProfileId) return;
        if (!confirm('Are you sure you want to delete this profile?')) return;
        const updated = profiles.filter((p) => p.id !== selectedProfileId);
        saveProfiles(updated);
        setSelectedProfileId('');
    };

    // Trigger Import
    const triggerImport = (values: FormValues, { resetForm }: FormikHelpers<FormValues>) => {
        clearFlashes('importer');
        const fileWeight = values.sourcePath.endsWith('.zip') || values.sourcePath.endsWith('.gz') ? 120 * 1024 * 1024 : 50 * 1024 * 1024;
        const totalSize = Math.floor(Math.random() * 200 * 1024 * 1024) + fileWeight;

        const newTransfer: Transfer = {
            id: Date.now().toString(),
            host: values.host,
            protocol: values.protocol,
            sourcePath: values.sourcePath,
            destinationPath: values.destinationPath,
            speed: 0,
            transferred: 0,
            total: totalSize,
            percentage: 0,
            eta: 999,
            currentFile: 'Initializing...',
            state: 'Queued',
            speedHistory: Array(20).fill(0),
            logs: [`[${new Date().toLocaleTimeString()}] Info: Starting import job for ${values.sourcePath}`],
            createdAt: new Date().toISOString(),
        };

        setTransfers((prev) => [newTransfer, ...prev]);
        setSelectedTransferId(newTransfer.id);
        addFlash({ key: 'importer', type: 'success', message: 'Import transfer task scheduled!' });
    };

    // SVG Speed graph points helper
    const renderGraphPoints = (historyList: number[]) => {
        if (!historyList || historyList.length === 0) return '';
        const maxVal = Math.max(...historyList, 1024 * 1024); // at least 1MB
        const width = 500;
        const height = 120;
        const points = historyList.map((val, index) => {
            const x = (index / (historyList.length - 1 || 1)) * width;
            const y = height - (val / maxVal) * (height - 10) - 5;
            return `${x},${y}`;
        });
        return points.join(' ');
    };

    return (
        <ServerContentBlock title={'Importer'} showFlashKey={'importer'}>
            <FlashMessageRender byKey={'importer'} css={tw`mb-4`} />

            <Formik
                initialValues={formValues}
                validationSchema={ImporterValidationSchema}
                onSubmit={triggerImport}
                enableReinitialize
            >
                {({ values, errors, touched, setFieldValue, setValues, isSubmitting }) => {
                    const handleFormChange = (e: React.ChangeEvent<HTMLInputElement | HTMLSelectElement>) => {
                        const { name, value, type } = e.target;
                        const val = type === 'checkbox' ? (e.target as HTMLInputElement).checked : value;
                        setFieldValue(name, val);
                        setFormValues((prev) => ({ ...prev, [name]: val }));
                    };

                    return (
                        <Form>
                            {/* Profile selector row */}
                            <div css={tw`bg-neutral-800 p-4 rounded-lg mb-6 border border-neutral-700 flex flex-wrap items-center justify-between gap-4`}>
                                <div css={tw`flex items-center gap-3 flex-1 min-w-[250px]`}>
                                    <Label css={tw`mb-0 text-sm whitespace-nowrap`}>Credentials Profile:</Label>
                                    <Select
                                        value={selectedProfileId}
                                        onChange={(e) => handleProfileSelect(e.target.value, setValues)}
                                        css={tw`max-w-xs`}
                                    >
                                        <option value="">-- Select or Create --</option>
                                        {profiles.map((p) => (
                                            <option key={p.id} value={p.id}>
                                                {p.name}
                                            </option>
                                        ))}
                                    </Select>
                                </div>
                                <div css={tw`flex gap-2`}>
                                    <Button
                                        type="button"
                                        color="green"
                                        size="small"
                                        onClick={() => handleSaveProfile(values)}
                                    >
                                        <FontAwesomeIcon icon={faSave} css={tw`mr-2`} /> Save
                                    </Button>
                                    {selectedProfileId && (
                                        <>
                                            <Button
                                                type="button"
                                                color="primary"
                                                size="small"
                                                onClick={handleRenameProfile}
                                            >
                                                <FontAwesomeIcon icon={faEdit} css={tw`mr-2`} /> Rename
                                            </Button>
                                            <Button
                                                type="button"
                                                color="red"
                                                size="small"
                                                onClick={handleDeleteProfile}
                                            >
                                                <FontAwesomeIcon icon={faTrash} css={tw`mr-2`} /> Delete
                                            </Button>
                                        </>
                                    )}
                                </div>
                            </div>

                            {/* Main Input Config */}
                            <div css={tw`grid grid-cols-1 md:grid-cols-3 gap-6 mb-6`}>
                                {/* Connection Column */}
                                <div css={tw`bg-neutral-800 p-5 rounded-lg border border-neutral-700`}>
                                    <h3 css={tw`text-neutral-200 text-lg font-semibold mb-4 flex items-center gap-2`}>
                                        <FontAwesomeIcon icon={faNetworkWired} /> Connection Details
                                    </h3>

                                    <FormikFieldWrapper>
                                        <Label>Protocol</Label>
                                        <Select
                                            name="protocol"
                                            value={values.protocol}
                                            onChange={handleFormChange}
                                        >
                                            <option value="SFTP">SFTP (SSH File Transfer)</option>
                                            <option value="FTP">FTP (File Transfer Protocol)</option>
                                            <option value="FTPS">FTPS (FTP over SSL)</option>
                                            <option value="HTTP">HTTP (Web)</option>
                                            <option value="HTTPS">HTTPS (Secure Web)</option>
                                        </Select>
                                    </FormikFieldWrapper>

                                    <div css={tw`grid grid-cols-4 gap-2`}>
                                        <div css={tw`col-span-3`}>
                                            <FormikFieldWrapper>
                                                <Label>Host</Label>
                                                <Input
                                                    name="host"
                                                    value={values.host}
                                                    onChange={handleFormChange}
                                                    placeholder="1.2.3.4 or hostname"
                                                    hasError={!!(touched.host && errors.host)}
                                                />
                                                {touched.host && errors.host && <p css={tw`text-xs text-red-400 mt-1`}>{errors.host}</p>}
                                            </FormikFieldWrapper>
                                        </div>
                                        <div>
                                            <FormikFieldWrapper>
                                                <Label>Port</Label>
                                                <Input
                                                    name="port"
                                                    type="number"
                                                    value={values.port}
                                                    onChange={handleFormChange}
                                                    hasError={!!(touched.port && errors.port)}
                                                />
                                                {touched.port && errors.port && <p css={tw`text-xs text-red-400 mt-1`}>{errors.port}</p>}
                                            </FormikFieldWrapper>
                                        </div>
                                    </div>

                                    <FormikFieldWrapper>
                                        <Label>Username</Label>
                                        <Input
                                            name="username"
                                            value={values.username}
                                            onChange={handleFormChange}
                                            hasError={!!(touched.username && errors.username)}
                                        />
                                        {touched.username && errors.username && <p css={tw`text-xs text-red-400 mt-1`}>{errors.username}</p>}
                                    </FormikFieldWrapper>

                                    <FormikFieldWrapper>
                                        <Label>Password / Token</Label>
                                        <Input
                                            name="password"
                                            type="password"
                                            value={values.password}
                                            onChange={handleFormChange}
                                            hasError={!!(touched.password && errors.password)}
                                        />
                                        {touched.password && errors.password && <p css={tw`text-xs text-red-400 mt-1`}>{errors.password}</p>}
                                    </FormikFieldWrapper>
                                </div>

                                {/* Files Paths & Options Column */}
                                <div css={tw`bg-neutral-800 p-5 rounded-lg border border-neutral-700 flex flex-col justify-between`}>
                                    <div>
                                        <h3 css={tw`text-neutral-200 text-lg font-semibold mb-4 flex items-center gap-2`}>
                                            <FontAwesomeIcon icon={faFolderOpen} /> Source & Destination
                                        </h3>

                                        <FormikFieldWrapper>
                                            <Label>Source Path (Remote)</Label>
                                            <div css={tw`flex gap-2`}>
                                                <Input
                                                    name="sourcePath"
                                                    value={values.sourcePath}
                                                    onChange={handleFormChange}
                                                    onClick={() => openRemoteBrowser(values.sourcePath)}
                                                    placeholder="Click to browse or type"
                                                    hasError={!!(touched.sourcePath && errors.sourcePath)}
                                                />
                                                <Button
                                                    type="button"
                                                    color="grey"
                                                    size="small"
                                                    onClick={() => openRemoteBrowser(values.sourcePath)}
                                                >
                                                    Browse
                                                </Button>
                                            </div>
                                            {touched.sourcePath && errors.sourcePath && <p css={tw`text-xs text-red-400 mt-1`}>{errors.sourcePath}</p>}
                                        </FormikFieldWrapper>

                                        <FormikFieldWrapper>
                                            <Label>Destination Path (Local)</Label>
                                            <div css={tw`flex gap-2`}>
                                                <Input
                                                    name="destinationPath"
                                                    value={values.destinationPath}
                                                    onChange={handleFormChange}
                                                    onClick={() => openLocalBrowser(values.destinationPath)}
                                                    placeholder="Click to browse or type"
                                                    hasError={!!(touched.destinationPath && errors.destinationPath)}
                                                />
                                                <Button
                                                    type="button"
                                                    color="grey"
                                                    size="small"
                                                    onClick={() => openLocalBrowser(values.destinationPath)}
                                                >
                                                    Browse
                                                </Button>
                                            </div>
                                            {touched.destinationPath && errors.destinationPath && <p css={tw`text-xs text-red-400 mt-1`}>{errors.destinationPath}</p>}
                                        </FormikFieldWrapper>

                                        {/* Checkboxes */}
                                        <div css={tw`mt-4 space-y-3`}>
                                            <label css={tw`flex items-center gap-3 cursor-pointer text-sm text-neutral-300`}>
                                                <Input
                                                    type="checkbox"
                                                    name="overwrite"
                                                    checked={values.overwrite}
                                                    onChange={handleFormChange}
                                                />
                                                <span>Overwrite existing files</span>
                                            </label>
                                            <label css={tw`flex items-center gap-3 cursor-pointer text-sm text-neutral-300`}>
                                                <Input
                                                    type="checkbox"
                                                    name="preservePermissions"
                                                    checked={values.preservePermissions}
                                                    onChange={handleFormChange}
                                                />
                                                <span>Preserve attributes & permissions</span>
                                            </label>
                                            <label css={tw`flex items-center gap-3 cursor-pointer text-sm text-neutral-300`}>
                                                <Input
                                                    type="checkbox"
                                                    name="verifySha256"
                                                    checked={values.verifySha256}
                                                    onChange={handleFormChange}
                                                />
                                                <span>Verify integrity (SHA-256)</span>
                                            </label>
                                        </div>
                                    </div>

                                    {/* Action Buttons */}
                                    <div css={tw`flex gap-3 mt-6`}>
                                        <Button
                                            type="button"
                                            color="grey"
                                            isLoading={testingConnection}
                                            onClick={() => testConnection(values)}
                                            css={tw`flex-1`}
                                        >
                                            Test Connection
                                        </Button>
                                        <Button
                                            type="submit"
                                            color="primary"
                                            isLoading={isSubmitting}
                                            css={tw`flex-1`}
                                        >
                                            Start Import
                                        </Button>
                                    </div>
                                </div>

                                {/* Advanced Settings Column */}
                                <div css={tw`bg-neutral-800 p-5 rounded-lg border border-neutral-700`}>
                                    <h3 css={tw`text-neutral-200 text-lg font-semibold mb-4 flex items-center gap-2`}>
                                        <FontAwesomeIcon icon={faPlus} /> Advanced (Optional)
                                    </h3>

                                    <FormikFieldWrapper>
                                        <Label>Bandwidth Limiter (MB/s)</Label>
                                        <Input
                                            name="bandwidthLimiter"
                                            type="number"
                                            placeholder="Unlimited"
                                            value={values.bandwidthLimiter}
                                            onChange={handleFormChange}
                                        />
                                    </FormikFieldWrapper>

                                    <FormikFieldWrapper>
                                        <Label>Retry Count</Label>
                                        <Input
                                            name="retryCount"
                                            type="number"
                                            value={values.retryCount}
                                            onChange={handleFormChange}
                                        />
                                    </FormikFieldWrapper>

                                    <FormikFieldWrapper>
                                        <Label>Timeout (seconds)</Label>
                                        <Input
                                            name="timeout"
                                            type="number"
                                            value={values.timeout}
                                            onChange={handleFormChange}
                                        />
                                    </FormikFieldWrapper>

                                    <FormikFieldWrapper>
                                        <Label>Chunk Size (MB)</Label>
                                        <Input
                                            name="chunkSize"
                                            type="number"
                                            value={values.chunkSize}
                                            onChange={handleFormChange}
                                        />
                                    </FormikFieldWrapper>

                                    <FormikFieldWrapper>
                                        <Label>Concurrent Streams</Label>
                                        <Input
                                            name="concurrentStreams"
                                            type="number"
                                            value={values.concurrentStreams}
                                            onChange={handleFormChange}
                                        />
                                    </FormikFieldWrapper>
                                </div>
                            </div>
                        </Form>
                    );
                }}
            </Formik>

            {/* Active Transfers and Real-Time Dashboard */}
            <div css={tw`grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8`}>
                {/* Active Transfers List */}
                <div css={tw`bg-neutral-800 rounded-lg border border-neutral-700 lg:col-span-1 flex flex-col`}>
                    <div css={tw`p-4 border-b border-neutral-700 flex justify-between items-center`}>
                        <h3 css={tw`text-neutral-200 font-semibold text-lg`}>Active Transfers ({transfers.filter(t => t.state !== 'Finished' && t.state !== 'Failed').length})</h3>
                        {transfers.length > 0 && (
                            <button
                                onClick={() => {
                                    setTransfers([]);
                                    setSelectedTransferId('');
                                }}
                                css={tw`text-neutral-400 hover:text-red-400 text-xs transition-colors`}
                            >
                                Clear All
                            </button>
                        )}
                    </div>
                    <div css={tw`flex-1 overflow-y-auto max-h-[350px] p-2 space-y-2`}>
                        {transfers.length === 0 ? (
                            <p css={tw`text-center text-sm text-neutral-400 py-8`}>No active or scheduled transfers.</p>
                        ) : (
                            transfers.map((t) => {
                                const isSelected = t.id === selectedTransferId || (!selectedTransferId && transfers[0]?.id === t.id);
                                return (
                                    <div
                                        key={t.id}
                                        onClick={() => setSelectedTransferId(t.id)}
                                        css={[
                                            tw`p-3 rounded border cursor-pointer transition-all`,
                                            isSelected
                                                ? tw`bg-neutral-700 border-primary-500`
                                                : tw`bg-neutral-900 border-neutral-800 hover:bg-neutral-700`
                                        ]}
                                    >
                                        <div css={tw`flex justify-between items-center mb-1`}>
                                            <span css={tw`text-sm font-medium text-neutral-200 truncate max-w-[150px]`}>
                                                {t.sourcePath.split('/').pop() || '/'}
                                            </span>
                                            <span
                                                css={[
                                                    tw`text-xs px-2 py-0.5 rounded font-semibold`,
                                                    t.state === 'Finished' && tw`bg-green-800 text-green-200`,
                                                    t.state === 'Failed' && tw`bg-red-800 text-red-200`,
                                                    t.state === 'Cancelled' && tw`bg-neutral-600 text-neutral-300`,
                                                    t.state === 'Paused' && tw`bg-yellow-800 text-yellow-200`,
                                                    !['Finished', 'Failed', 'Cancelled', 'Paused'].includes(t.state) && tw`bg-primary-900 text-primary-200`
                                                ]}
                                            >
                                                {t.state}
                                            </span>
                                        </div>

                                        <div css={tw`text-xs text-neutral-400 truncate mb-2`}>
                                            {t.protocol.toLowerCase()}://{t.host}
                                        </div>

                                        <div css={tw`w-full bg-neutral-800 rounded-full h-1.5 mb-2`}>
                                            <div
                                                css={[
                                                    tw`h-1.5 rounded-full transition-all duration-300`,
                                                    t.state === 'Finished' ? tw`bg-green-500` : t.state === 'Failed' ? tw`bg-red-500` : tw`bg-primary-500`
                                                ]}
                                                style={{ width: `${t.percentage}%` }}
                                            />
                                        </div>

                                        <div css={tw`flex justify-between text-xs text-neutral-400`}>
                                            <span>{t.percentage}%</span>
                                            <span>{formatBytes(t.speed)}/s</span>
                                        </div>

                                        {/* Action buttons inside item */}
                                        <div css={tw`flex gap-2 mt-2 justify-end`}>
                                            {['Queued', 'Connecting', 'Authenticating', 'Streaming'].includes(t.state) && (
                                                <button
                                                    type="button"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setTransfers(prev => prev.map(item => item.id === t.id ? { ...item, state: 'Paused' } : item));
                                                    }}
                                                    css={tw`text-yellow-400 hover:text-yellow-300 p-1 text-xs`}
                                                    title="Pause"
                                                >
                                                    <FontAwesomeIcon icon={faPause} />
                                                </button>
                                            )}
                                            {t.state === 'Paused' && (
                                                <button
                                                    type="button"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setTransfers(prev => prev.map(item => item.id === t.id ? { ...item, state: 'Streaming' } : item));
                                                    }}
                                                    css={tw`text-green-400 hover:text-green-300 p-1 text-xs`}
                                                    title="Resume"
                                                >
                                                    <FontAwesomeIcon icon={faPlay} />
                                                </button>
                                            )}
                                            {!['Finished', 'Failed', 'Cancelled'].includes(t.state) && (
                                                <button
                                                    type="button"
                                                    onClick={(e) => {
                                                        e.stopPropagation();
                                                        setTransfers(prev => prev.map(item => item.id === t.id ? { ...item, state: 'Cancelled', speed: 0 } : item));
                                                    }}
                                                    css={tw`text-red-400 hover:text-red-300 p-1 text-xs`}
                                                    title="Cancel"
                                                >
                                                    <FontAwesomeIcon icon={faTimes} />
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                );
                            })
                        )}
                    </div>
                </div>

                {/* Graph and Logs Console Display */}
                <div css={tw`lg:col-span-2 bg-neutral-800 rounded-lg border border-neutral-700 flex flex-col min-h-[350px]`}>
                    <div css={tw`p-4 border-b border-neutral-700 flex gap-4`}>
                        <h3 css={tw`text-neutral-200 font-semibold text-lg flex-1`}>
                            {activeTransfer ? `Monitoring Job ID: ${activeTransfer.id}` : 'Performance Monitor'}
                        </h3>
                    </div>

                    {activeTransfer ? (
                        <div css={tw`flex flex-col flex-1`}>
                            {/* Live Stats Row */}
                            <div css={tw`grid grid-cols-2 md:grid-cols-4 gap-4 p-4 bg-neutral-900 border-b border-neutral-700 text-xs text-neutral-300`}>
                                <div>
                                    <span css={tw`text-neutral-500 block`}>Speed</span>
                                    <span css={tw`font-mono text-sm text-neutral-100`}>{formatBytes(activeTransfer.speed)}/s</span>
                                </div>
                                <div>
                                    <span css={tw`text-neutral-500 block`}>Transferred</span>
                                    <span css={tw`font-mono text-sm text-neutral-100`}>
                                        {formatBytes(activeTransfer.transferred)} / {formatBytes(activeTransfer.total)}
                                    </span>
                                </div>
                                <div>
                                    <span css={tw`text-neutral-500 block`}>ETA / Progress</span>
                                    <span css={tw`font-mono text-sm text-neutral-100`}>
                                        {activeTransfer.state === 'Streaming' ? `${activeTransfer.eta}s` : '--'} ({activeTransfer.percentage}%)
                                    </span>
                                </div>
                                <div>
                                    <span css={tw`text-neutral-500 block`}>Active File</span>
                                    <span css={tw`truncate block font-mono text-sm text-neutral-100`} title={activeTransfer.currentFile}>
                                        {activeTransfer.currentFile}
                                    </span>
                                </div>
                            </div>

                            {/* Upper: SVG Speed Graph */}
                            <div css={tw`p-4 bg-neutral-800 border-b border-neutral-700 flex flex-col items-center justify-center`}>
                                <div css={tw`w-full flex justify-between text-xs text-neutral-400 mb-1`}>
                                    <span>Transfer Rate over time</span>
                                    <span>Max Peak: {formatBytes(Math.max(...activeTransfer.speedHistory, 1024 * 1024))}/s</span>
                                </div>
                                <svg
                                    viewBox="0 0 500 120"
                                    css={tw`w-full h-24 bg-neutral-900 rounded border border-neutral-700 p-1`}
                                >
                                    <polyline
                                        fill="none"
                                        stroke="#10B981"
                                        strokeWidth="2.5"
                                        points={renderGraphPoints(activeTransfer.speedHistory)}
                                    />
                                    {/* Grid Lines */}
                                    <line x1="0" y1="30" x2="500" y2="30" stroke="#374151" strokeDasharray="3,3" />
                                    <line x1="0" y1="60" x2="500" y2="60" stroke="#374151" strokeDasharray="3,3" />
                                    <line x1="0" y1="90" x2="500" y2="90" stroke="#374151" strokeDasharray="3,3" />
                                </svg>
                            </div>

                            {/* Lower: Log console */}
                            <div css={tw`flex-1 p-4 bg-neutral-900 font-mono text-xs text-green-400 overflow-y-auto max-h-[160px]`}>
                                {activeTransfer.logs.map((log, index) => (
                                    <div key={index} css={tw`mb-1 whitespace-pre-wrap leading-relaxed`}>
                                        {log}
                                    </div>
                                ))}
                                <div ref={terminalEndRef} />
                            </div>
                        </div>
                    ) : (
                        <div css={tw`flex-1 flex flex-col items-center justify-center text-neutral-400 p-8`}>
                            <FontAwesomeIcon icon={faChartLine} size="3x" css={tw`mb-2 opacity-50`} />
                            <p>Select an active transfer from the list to display statistics and logs.</p>
                        </div>
                    )}
                </div>
            </div>

            {/* Transfer History Table */}
            <div css={tw`bg-neutral-800 rounded-lg border border-neutral-700 overflow-hidden`}>
                <div css={tw`p-4 border-b border-neutral-700 flex justify-between items-center`}>
                    <h3 css={tw`text-neutral-200 font-semibold text-lg flex items-center gap-2`}>
                        <FontAwesomeIcon icon={faHistory} /> Transfer History
                    </h3>
                    {history.length > 0 && (
                        <button
                            onClick={() => {
                                if (confirm('Clear entire transfer history?')) {
                                    saveHistory([]);
                                }
                            }}
                            css={tw`text-neutral-400 hover:text-red-400 text-xs transition-colors`}
                        >
                            Clear History
                        </button>
                    )}
                </div>
                <div css={tw`overflow-x-auto`}>
                    <table css={tw`w-full text-left text-sm text-neutral-300`}>
                        <thead css={tw`bg-neutral-900 text-xs text-neutral-400 uppercase border-b border-neutral-700`}>
                            <tr>
                                <th css={tw`px-6 py-3`}>Date</th>
                                <th css={tw`px-6 py-3`}>Duration</th>
                                <th css={tw`px-6 py-3`}>Source</th>
                                <th css={tw`px-6 py-3`}>Destination</th>
                                <th css={tw`px-6 py-3`}>Avg Speed</th>
                                <th css={tw`px-6 py-3`}>SHA256</th>
                                <th css={tw`px-6 py-3`}>Errors</th>
                            </tr>
                        </thead>
                        <tbody css={tw`divide-y divide-neutral-700`}>
                            {history.length === 0 ? (
                                <tr>
                                    <td colSpan={7} css={tw`px-6 py-8 text-center text-neutral-400`}>
                                        No historical import transactions.
                                    </td>
                                </tr>
                            ) : (
                                history.map((item) => (
                                    <tr key={item.id} css={tw`hover:bg-neutral-700 transition-colors`}>
                                        <td css={tw`px-6 py-4 font-mono text-xs whitespace-nowrap`}>{item.date}</td>
                                        <td css={tw`px-6 py-4`}>{item.duration}</td>
                                        <td css={tw`px-6 py-4 truncate max-w-xs`} title={item.source}>
                                            {item.source}
                                        </td>
                                        <td css={tw`px-6 py-4 truncate max-w-xs`} title={item.destination}>
                                            {item.destination}
                                        </td>
                                        <td css={tw`px-6 py-4 font-mono text-xs`}>{item.averageSpeed}</td>
                                        <td css={tw`px-6 py-4 font-mono text-xs text-green-400`}>{item.checksum}</td>
                                        <td css={tw`px-6 py-4`}>
                                            <span
                                                css={[
                                                    tw`text-xs px-2 py-0.5 rounded font-semibold`,
                                                    item.errors === 'None'
                                                        ? tw`bg-green-900/50 text-green-300`
                                                        : tw`bg-red-900/50 text-red-300`
                                                ]}
                                            >
                                                {item.errors}
                                            </span>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Local Server Filesystem Browser Modal */}
            <Modal
                visible={localBrowserVisible}
                onDismissed={() => setLocalBrowserVisible(false)}
                top={false}
            >
                <div css={tw`p-6 bg-neutral-800 rounded-lg text-neutral-200`}>
                    <div css={tw`flex justify-between items-center mb-4`}>
                        <h3 css={tw`text-lg font-bold`}>Local Server File Browser</h3>
                        <button onClick={() => setLocalBrowserVisible(false)} css={tw`text-neutral-400 hover:text-white`}>
                            <FontAwesomeIcon icon={faTimes} />
                        </button>
                    </div>

                    <div css={tw`flex items-center gap-2 mb-4 bg-neutral-900 p-2 rounded text-sm text-neutral-400 font-mono`}>
                        <span>Directory:</span>
                        <span css={tw`text-neutral-200`}>{localDirectory}</span>
                    </div>

                    {localError && <div css={tw`bg-red-800 text-red-100 p-3 rounded mb-4 text-sm`}>{localError}</div>}

                    <div css={tw`bg-neutral-900 rounded border border-neutral-700 max-h-[300px] overflow-y-auto`}>
                        {localLoading ? (
                            <div css={tw`flex justify-center items-center py-12`}>
                                <FontAwesomeIcon icon={faSpinner} spin size="2x" css={tw`text-primary-500`} />
                            </div>
                        ) : (
                            <div css={tw`divide-y divide-neutral-800`}>
                                {localDirectory !== '/' && (
                                    <div
                                        onClick={handleLocalParentClick}
                                        css={tw`p-3 hover:bg-neutral-800 cursor-pointer flex items-center gap-2 text-neutral-400 text-sm`}
                                    >
                                        <FontAwesomeIcon icon={faFolder} />
                                        <span>.. (Parent Directory)</span>
                                    </div>
                                )}
                                {localFiles.length === 0 ? (
                                    <div css={tw`p-6 text-center text-neutral-500 text-sm`}>Empty directory</div>
                                ) : (
                                    localFiles.map((file) => (
                                        <div
                                            key={file.name}
                                            onClick={() => {
                                                if (!file.isFile) {
                                                    handleLocalDirClick(file.name);
                                                } else {
                                                    const path = localDirectory === '/' ? `/${file.name}` : `${localDirectory.replace(/\/$/, '')}/${file.name}`;
                                                    setFieldValue('destinationPath', path);
                                                    setFormValues((prev) => ({ ...prev, destinationPath: path }));
                                                    setLocalBrowserVisible(false);
                                                }
                                            }}
                                            css={tw`p-3 hover:bg-neutral-800 cursor-pointer flex items-center justify-between text-sm`}
                                        >
                                            <div css={tw`flex items-center gap-3`}>
                                                <FontAwesomeIcon
                                                    icon={file.isFile ? faFile : faFolder}
                                                    css={file.isFile ? tw`text-neutral-400` : tw`text-yellow-500`}
                                                />
                                                <span css={file.isFile ? tw`text-neutral-300` : tw`font-medium text-neutral-200`}>
                                                    {file.name}
                                                </span>
                                            </div>
                                            {file.isFile && <span css={tw`text-xs text-neutral-500`}>{formatBytes(file.size)}</span>}
                                        </div>
                                    ))
                                )}
                            </div>
                        )}
                    </div>

                    <div css={tw`mt-6 flex justify-end gap-3`}>
                        <Button
                            type="button"
                            color="grey"
                            onClick={() => setLocalBrowserVisible(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            color="green"
                            onClick={() => {
                                setFieldValue('destinationPath', localDirectory);
                                setFormValues((prev) => ({ ...prev, destinationPath: localDirectory }));
                                setLocalBrowserVisible(false);
                            }}
                        >
                            Select Current Folder
                        </Button>
                    </div>
                </div>
            </Modal>

            {/* Remote Browser Modal */}
            <Modal
                visible={remoteBrowserVisible}
                onDismissed={() => setRemoteBrowserVisible(false)}
                top={false}
            >
                <div css={tw`p-6 bg-neutral-800 rounded-lg text-neutral-200`}>
                    <div css={tw`flex justify-between items-center mb-4`}>
                        <h3 css={tw`text-lg font-bold`}>Remote Directory Browser (Simulated)</h3>
                        <button onClick={() => setRemoteBrowserVisible(false)} css={tw`text-neutral-400 hover:text-white`}>
                            <FontAwesomeIcon icon={faTimes} />
                        </button>
                    </div>

                    <div css={tw`flex items-center gap-2 mb-4 bg-neutral-900 p-2 rounded text-sm text-neutral-400 font-mono`}>
                        <span>Remote Directory:</span>
                        <span css={tw`text-neutral-200`}>{remoteDirectory}</span>
                    </div>

                    <div css={tw`bg-neutral-900 rounded border border-neutral-700 max-h-[300px] overflow-y-auto`}>
                        <div css={tw`divide-y divide-neutral-800`}>
                            {remoteDirectory !== '/' && (
                                <div
                                    onClick={handleRemoteParentClick}
                                    css={tw`p-3 hover:bg-neutral-800 cursor-pointer flex items-center gap-2 text-neutral-400 text-sm`}
                                >
                                    <FontAwesomeIcon icon={faFolder} />
                                    <span>.. (Parent Directory)</span>
                                </div>
                            )}
                            {remoteFiles.length === 0 ? (
                                <div css={tw`p-6 text-center text-neutral-500 text-sm`}>Empty directory</div>
                            ) : (
                                remoteFiles.map((file) => (
                                    <div
                                        key={file.name}
                                        onClick={() => {
                                            if (!file.isFile) {
                                                handleRemoteDirClick(file.name);
                                            } else {
                                                const path = remoteDirectory === '/' ? `/${file.name}` : `${remoteDirectory.replace(/\/$/, '')}/${file.name}`;
                                                setFieldValue('sourcePath', path);
                                                setFormValues((prev) => ({ ...prev, sourcePath: path }));
                                                setRemoteBrowserVisible(false);
                                            }
                                        }}
                                        css={tw`p-3 hover:bg-neutral-800 cursor-pointer flex items-center justify-between text-sm`}
                                    >
                                        <div css={tw`flex items-center gap-3`}>
                                            <FontAwesomeIcon
                                                icon={file.isFile ? faFile : faFolder}
                                                css={file.isFile ? tw`text-neutral-400` : tw`text-yellow-500`}
                                            />
                                            <span css={file.isFile ? tw`text-neutral-300` : tw`font-medium text-neutral-200`}>
                                                {file.name}
                                            </span>
                                        </div>
                                        {file.isFile && file.size && (
                                            <span css={tw`text-xs text-neutral-500`}>{formatBytes(file.size)}</span>
                                        )}
                                    </div>
                                ))
                            )}
                        </div>
                    </div>

                    <div css={tw`mt-6 flex justify-end gap-3`}>
                        <Button
                            type="button"
                            color="grey"
                            onClick={() => setRemoteBrowserVisible(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            color="green"
                            onClick={() => {
                                setFieldValue('sourcePath', remoteDirectory);
                                setFormValues((prev) => ({ ...prev, sourcePath: remoteDirectory }));
                                setRemoteBrowserVisible(false);
                            }}
                        >
                            Select Current Folder
                        </Button>
                    </div>
                </div>
            </Modal>

            {/* Custom Rename Profile Modal */}
            <Modal
                visible={isRenamingProfile}
                onDismissed={() => setIsRenamingProfile(false)}
                top={true}
            >
                <div css={tw`p-6 bg-neutral-800 rounded-lg text-neutral-200`}>
                    <h3 css={tw`text-lg font-bold mb-4`}>Rename Profile</h3>
                    <FormikFieldWrapper>
                        <Label>New Name</Label>
                        <Input
                            value={renameProfileName}
                            onChange={(e) => setRenameProfileName(e.target.value)}
                        />
                    </FormikFieldWrapper>
                    <div css={tw`mt-6 flex justify-end gap-3`}>
                        <Button
                            type="button"
                            color="grey"
                            onClick={() => setIsRenamingProfile(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            color="primary"
                            onClick={confirmRenameProfile}
                        >
                            Save Changes
                        </Button>
                    </div>
                </div>
            </Modal>
        </ServerContentBlock>
    );
};
