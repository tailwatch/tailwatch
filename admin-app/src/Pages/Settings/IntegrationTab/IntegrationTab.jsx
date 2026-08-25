import React, { useEffect, useState } from 'react'
import { getIntegrationData, updateIntegrationData, deleteIntegrationData, updateGeoLiteDatabase } from './IntegrationTabServices/IntegrationTabServices'
import { FiCheckCircle, FiXCircle, FiDatabase, FiKey, FiRefreshCw, FiEdit2, FiX, FiSave, FiExternalLink, FiUserPlus, FiLogIn, FiSettings, FiTrash2 } from 'react-icons/fi'
import { toast } from 'react-toastify'

const IntegrationTab = () => {
    const [licenseKey, setLicenseKey] = useState('');
    const [editKey, setEditKey] = useState('');
    const [isEditing, setIsEditing] = useState(false);
    const [loading, setLoading] = useState(false);
    const [initialLoading, setInitialLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [removing, setRemoving] = useState(false);
    const [updating, setUpdating] = useState(false);
    const [error, setError] = useState('');
    const [maxmindData, setMaxmindData] = useState(null);

    const applyIntegrationData = (data) => {
        const maxmind = data?.integration_data?.maxmind ?? null;
        setMaxmindData(maxmind);
        setLicenseKey(maxmind?.license_key || '');
    }

    const fetchIntegrationInfo = async (isRefresh = false) => {
        if (isRefresh) setRefreshing(true);
        try {
            const data = await getIntegrationData();
            applyIntegrationData(data);
        } catch (err) {
            // Non-fatal: leave the current view in place.
        } finally {
            setInitialLoading(false);
            setRefreshing(false);
        }
    }

    const handleRemove = async () => {
        setRemoving(true);
        try {
            const result = await deleteIntegrationData('maxmind');
            if (result?.success) {
                applyIntegrationData(result.data);
                setIsEditing(false);
                setEditKey('');
                setError('');
                toast.success('MaxMind integration removed');
            } else {
                toast.error('Failed to remove integration. Please try again.');
            }
        } finally {
            setRemoving(false);
        }
    }

    const handleCheckUpdates = async () => {
        setUpdating(true);
        try {
            const result = await updateGeoLiteDatabase();
            if (result && result.code === 200) {
                toast.success(result.message || 'Database checked for updates.');
                await fetchIntegrationInfo(true);
            } else {
                toast.error((result && result.message) || 'Failed to check for updates.');
            }
        } catch (err) {
            toast.error('Network error while checking for updates.');
        } finally {
            setUpdating(false);
        }
    }

    useEffect(() => {
        fetchIntegrationInfo();
    }, [])

    const validateLicenseKey = (key) => {
        const pattern = /^[A-Za-z0-9_]+_mmk$/;
        return pattern.test(key);
    }

    const handleEdit = () => {
        setEditKey(licenseKey);
        setIsEditing(true);
        setError('');
    }

    const handleCancelEdit = () => {
        setIsEditing(false);
        setEditKey('');
        setError('');
    }

    const handleSubmit = async (e) => {
        e.preventDefault();
        setError('');

        if (!editKey.trim()) {
            setError('Please enter a license key');
            return;
        }

        if (!validateLicenseKey(editKey)) {
            setError('Invalid key format. License key must end with "_mmk"');
            return;
        }

        setLoading(true);

        const payload = {
            integration_data: {
                maxmind: {
                    license_key: editKey.trim()
                }
            }
        };

        try {
            const integrationData = await updateIntegrationData(payload);
            if (integrationData?.success) {
                setIsEditing(false);
                fetchIntegrationInfo(true);
            } else {
                setError('Failed to update integration. Please try again.');
            }
        } catch (err) {
            setError('Failed to update integration. Please try again.');
        } finally {
            setLoading(false);
        }
    };

    const formatFileSize = (bytes) => {
        if (!bytes) return '- -';
        const mb = bytes / (1024 * 1024);
        return `${mb.toFixed(2)} MB`;
    }

    const maskKey = (key) => {
        if (!key) return '- -';
        return key.length > 10 ? key.slice(0, 6) + '••••••' + key.slice(-4) : key;
    }

    if (initialLoading) {
        return (
            <div className="p-3 flex items-center justify-center min-h-[400px]">
                <div className="text-center">
                    <div className="inline-block animate-spin rounded-full h-12 w-12 border-b-2 border-[#007980]"></div>
                    <p className="mt-4 text-gray-600">Loading integration settings...</p>
                </div>
            </div>
        );
    }

    return (
        <div className="p-3">

            {/* Info banner — shown when MaxMind is not connected */}
            {(!maxmindData?.is_connected) && (
                <div className="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 mb-6">
                    <FiXCircle className="text-amber-500 text-lg mt-0.5 shrink-0" />
                    <div>
                        <p className="text-sm font-semibold text-amber-800">MaxMind License Key Required</p>
                        <p className="text-xs text-amber-700 mt-0.5 leading-relaxed">
                            Connect a valid MaxMind license key below to enable GeoIP geolocation &mdash; used to show the country of an event in your Login Defender logs. The database is downloaded only when you save your key or click &ldquo;Check for updates&rdquo;.
                        </p>
                    </div>
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 items-start">

            <div className="relative bg-white border border-gray-200 rounded-xl overflow-hidden w-full">
                {refreshing && (
                    <div className="absolute inset-0 bg-white bg-opacity-70 flex items-center justify-center z-10 rounded-xl">
                        <div className="flex flex-col items-center gap-2">
                            <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-[#007980]"></div>
                            <p className="text-sm text-gray-500">Verifying connection...</p>
                        </div>
                    </div>
                )}

                {/* Card Header */}
                <div className="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                    <div className="flex items-center gap-2">
                        <FiDatabase className="text-[#007980] text-lg" />
                        <h3 className="text-base font-semibold text-gray-800">MaxMind GeoIP</h3>
                    </div>
                    {maxmindData && (
                        maxmindData.is_connected ? (
                            <span className="flex items-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-100 px-3 py-1 rounded-full">
                                <FiCheckCircle className="text-sm" /> Connected
                            </span>
                        ) : (
                            <span className="flex items-center gap-1.5 text-xs font-semibold text-red-600 bg-red-50 border border-red-100 px-3 py-1 rounded-full">
                                <FiXCircle className="text-sm" /> Not Connected
                            </span>
                        )
                    )}
                </div>

                {/* Info Grid */}
                <div className="p-5">
                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">

                        {/* License Key Row — full width with edit */}
                        <div className="sm:col-span-2 bg-gray-50 rounded-lg px-4 py-3">
                            <div className="flex items-center justify-between mb-1">
                                <p className="text-xs text-gray-500 flex items-center gap-1"><FiKey className="text-xs" /> License Key</p>
                                {!isEditing ? (
                                    <div className="flex items-center gap-3">
                                        <button onClick={handleEdit} className="flex items-center gap-1 text-xs text-[#007980] hover:underline font-medium">
                                            <FiEdit2 className="text-xs" /> Edit
                                        </button>
                                        {licenseKey && (
                                            <button
                                                onClick={handleRemove}
                                                disabled={removing}
                                                className="flex items-center gap-1 text-xs text-red-600 hover:underline font-medium disabled:opacity-50 disabled:cursor-not-allowed"
                                            >
                                                <FiTrash2 className="text-xs" /> {removing ? 'Removing...' : 'Remove'}
                                            </button>
                                        )}
                                    </div>
                                ) : (
                                    <button onClick={handleCancelEdit} className="flex items-center gap-1 text-xs text-gray-400 hover:text-gray-600 font-medium">
                                        <FiX className="text-xs" /> Cancel
                                    </button>
                                )}
                            </div>
                            {!isEditing ? (
                                <p className="text-sm font-semibold text-gray-800">{maskKey(licenseKey)}</p>
                            ) : (
                                <form onSubmit={handleSubmit}>
                                    <div className="flex items-center gap-2 mt-1">
                                        <input
                                            type="text"
                                            value={editKey}
                                            onChange={(e) => { setEditKey(e.target.value); setError(''); }}
                                            placeholder="Enter new license key"
                                            className={`flex-1 px-3 py-1.5 text-sm border rounded-md focus:outline-none focus:ring-1 ${error ? 'border-red-500 focus:ring-red-500' : 'border-gray-300 focus:ring-[#007980]'}`}
                                        />
                                        <button type="submit" disabled={loading} className="flex items-center gap-1.5 px-3 py-1.5 bg-[#007980] text-white text-sm rounded-md hover:bg-teal-700 disabled:bg-gray-400 transition-colors">
                                            <FiSave className="text-xs" /> {loading ? 'Saving...' : 'Save'}
                                        </button>
                                    </div>
                                    {error && <p className="mt-1.5 text-xs text-red-600">{error}</p>}
                                    <p className="mt-1 text-xs text-gray-400">Format: xxxxxxxxx_mmk</p>
                                </form>
                            )}
                        </div>

                        {/* Database File */}
                        <div className="bg-gray-50 rounded-lg px-4 py-3">
                            <p className="text-xs text-gray-500 mb-0.5">Maxmind File</p>
                            {maxmindData?.db_file_exists === undefined || maxmindData?.db_file_exists === null ? (
                                <p className="text-sm font-semibold text-gray-400">- -</p>
                            ) : (
                                <p className={`text-sm font-semibold ${maxmindData.db_file_exists ? 'text-emerald-600' : 'text-red-500'}`}>
                                    {maxmindData.db_file_exists ? 'Available' : 'Not Found'}
                                </p>
                            )}
                        </div>

                        {/* Database Size */}
                        <div className="bg-gray-50 rounded-lg px-4 py-3">
                            <p className="text-xs text-gray-500 mb-0.5">Database Size</p>
                            <p className={`text-sm font-semibold ${maxmindData?.db_file_size ? 'text-gray-800' : 'text-gray-400'}`}>
                                {maxmindData?.db_file_size ? formatFileSize(maxmindData.db_file_size) : '- -'}
                            </p>
                        </div>

                        {/* Last Updated */}
                        <div className="sm:col-span-2 bg-gray-50 rounded-lg px-4 py-3">
                            <p className="text-xs text-gray-500 mb-0.5">Last Updated</p>
                            <p className={`text-sm font-semibold ${maxmindData?.db_file_last_updated ? 'text-gray-800' : 'text-gray-400'}`}>
                                {maxmindData?.db_file_last_updated || '- -'}
                            </p>
                        </div>

                    </div>

                    {/* Manual update — user-initiated only (no background/scheduled update) */}
                    {maxmindData?.db_file_exists && (
                        <div className="mt-4 flex items-center justify-between gap-3 flex-wrap">
                            <p className="text-xs text-gray-500">
                                MaxMind revises GeoLite2 periodically. Click to fetch the latest copy; it is replaced only if a newer version exists.
                            </p>
                            <button
                                onClick={handleCheckUpdates}
                                disabled={updating}
                                className="flex items-center gap-1.5 px-3 py-1.5 text-sm text-[#007980] border border-[#007980] rounded-md hover:bg-[#007980] hover:text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                            >
                                <FiRefreshCw className={`text-xs ${updating ? 'animate-spin' : ''}`} />
                                {updating ? 'Checking...' : 'Check for updates'}
                            </button>
                        </div>
                    )}

                    {/* Message Banner */}
                    {maxmindData?.message && (
                        <div className="mt-4 flex items-start gap-2 bg-amber-50 border border-amber-100 rounded-lg px-4 py-3">
                            <FiXCircle className="text-amber-500 mt-0.5 shrink-0" />
                            <p className="text-sm text-amber-700">{maxmindData.message}</p>
                        </div>
                    )}
                </div>

            </div>

            {/* How to get your MaxMind License Key */}
            <div className="bg-white border border-gray-200 rounded-xl overflow-hidden w-full">
                <div className="flex items-center gap-2 px-5 py-4 border-b border-gray-100">
                    <FiKey className="text-[#007980] text-lg" />
                    <h3 className="text-base font-semibold text-gray-800">How to Get Your MaxMind License Key</h3>
                </div>
                <div className="p-5 space-y-4">
                    {[
                        {
                            icon: <FiExternalLink className="text-[#007980]" />,
                            title: "Visit MaxMind",
                            description: "Go to the MaxMind website to get started with GeoIP services.",
                            action: { label: "maxmind.com", href: "https://www.maxmind.com" },
                        },
                        {
                            icon: <FiUserPlus className="text-[#007980]" />,
                            title: "Create a Free Account",
                            description: "You can sign up to get a free license key for a GeoLite2 database.",
                            action: { label: "Create Account", href: "https://www.maxmind.com/en/create-account" },
                        },
                        {
                            icon: <FiLogIn className="text-[#007980]" />,
                            title: "Log In to Your Account",
                            description: "Already have an account? Sign in to your MaxMind portal to manage your license keys.",
                        },
                        {
                            icon: <FiSettings className="text-[#007980]" />,
                            title: "Generate a License Key",
                            description: 'In your account dashboard, navigate to "Manage License Keys" and click "Generate new license key". Copy the key and paste it above.',
                        },
                    ].map((step, index) => (
                        <div key={index} className="flex items-start gap-4">
                            <div className="flex flex-col items-center shrink-0">
                                <div className="w-8 h-8 rounded-full bg-teal-50 border border-teal-100 flex items-center justify-center text-sm font-bold text-[#007980]">
                                    {index + 1}
                                </div>
                                {index < 3 && <div className="w-px h-full min-h-[24px] bg-gray-200 mt-1" />}
                            </div>
                            <div className="pb-4 flex-1">
                                <div className="flex items-center gap-2 mb-0.5">
                                    {step.icon}
                                    <p className="text-sm font-semibold text-gray-800">{step.title}</p>
                                </div>
                                <p className="text-xs text-gray-500 leading-relaxed">{step.description}</p>
                                {step.action && (
                                    <a
                                        href={step.action.href}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="inline-flex items-center gap-1 mt-1.5 text-xs font-medium text-[#007980] hover:underline"
                                    >
                                        <FiExternalLink className="text-xs" />
                                        {step.action.label}
                                    </a>
                                )}
                            </div>
                        </div>
                    ))}
                </div>
            </div>

            </div>{/* end grid wrapper */}
        </div>
    )
}

export default IntegrationTab
