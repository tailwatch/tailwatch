import React, { useEffect, useMemo, useState } from 'react';
import { useParams } from 'react-router-dom';
import { CheckCircle, Info, AlertTriangle, XCircle, AlertOctagon, Search, X } from 'lucide-react';
import Header from '../../../Components/Header/Header';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton';
import { getHardeningAuditReportById } from '../AuditServices/AuditServices';

const STATUS_META = {
    secure: {
        label: 'Secure',
        Icon: CheckCircle,
        pill: 'bg-green-100 text-green-800 border-green-200',
        accent: 'border-l-green-500',
        iconColor: 'text-green-600',
    },
    notice: {
        label: 'Info',
        Icon: Info,
        pill: 'bg-blue-100 text-blue-800 border-blue-200',
        accent: 'border-l-blue-500',
        iconColor: 'text-blue-600',
    },
    warning: {
        label: 'Warning',
        Icon: AlertTriangle,
        pill: 'bg-yellow-100 text-yellow-800 border-yellow-200',
        accent: 'border-l-yellow-500',
        iconColor: 'text-yellow-600',
    },
    critical: {
        label: 'Critical',
        Icon: XCircle,
        pill: 'bg-red-100 text-red-800 border-red-200',
        accent: 'border-l-red-500',
        iconColor: 'text-red-600',
    },
    error: {
        label: 'Error',
        Icon: AlertOctagon,
        pill: 'bg-red-200 text-red-900 border-red-300',
        accent: 'border-l-red-700',
        iconColor: 'text-red-700',
    },
};

const SEVERITY_PILL = {
    critical: 'bg-red-100 text-red-800 border-red-200',
    warning: 'bg-yellow-100 text-yellow-800 border-yellow-200',
    notice: 'bg-blue-100 text-blue-800 border-blue-200',
    info: 'bg-gray-100 text-gray-700 border-gray-200',
};

const formatScanType = (type) => {
    if (!type) return '—';
    return type
        .split(/[-_\s]+/)
        .map((w) => w.charAt(0).toUpperCase() + w.slice(1))
        .join(' ');
};

const formatKeyLabel = (key) =>
    String(key)
        .replace(/[_-]+/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());

const formatDuration = (start, end) => {
    if (!start || !end || end < start) return null;
    const seconds = end - start;
    if (seconds < 60) return `${seconds}s`;
    const m = Math.floor(seconds / 60);
    const s = seconds % 60;
    return s > 0 ? `${m}m ${s}s` : `${m}m`;
};

const BoolBadge = ({ value }) => (
    <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border ${
        value
            ? 'bg-green-100 text-green-800 border-green-200'
            : 'bg-gray-100 text-gray-700 border-gray-200'
    }`}>
        {value ? 'Yes' : 'No'}
    </span>
);

const KeyValueRow = ({ k, v }) => (
    <div className="grid grid-cols-12 gap-3 py-1.5 border-b border-gray-100 last:border-0">
        <div className="col-span-4 text-xs font-medium text-gray-500">{formatKeyLabel(k)}</div>
        <div className="col-span-8 text-xs text-gray-800 break-all">
            {typeof v === 'boolean' ? <BoolBadge value={v} /> : v === null || v === undefined || v === '' ? '—' : String(v)}
        </div>
    </div>
);

const renderDetails = (details) => {
    if (details === null || details === undefined) return null;
    if (Array.isArray(details) && details.length === 0) return null;
    if (typeof details === 'object' && !Array.isArray(details) && Object.keys(details).length === 0) return null;

    // Issues array (e.g. php_ini_settings, file_permissions, ssl_https mixed)
    if (Array.isArray(details?.issues) && details.issues.length > 0) {
        const stringIssues = details.issues.filter((i) => typeof i === 'string');
        const objectIssues = details.issues.filter((i) => i && typeof i === 'object');
        return (
            <div className="space-y-3">
                {stringIssues.length > 0 && (
                    <ul className="list-disc pl-5 space-y-1">
                        {stringIssues.map((msg, idx) => (
                            <li key={`str-${idx}`} className="text-xs text-gray-700">{msg}</li>
                        ))}
                    </ul>
                )}
                {objectIssues.length > 0 && (
                    <div className="space-y-2">
                        {objectIssues.map((issue, idx) => {
                            const sev = (issue.severity || 'info').toLowerCase();
                            const sevClasses = SEVERITY_PILL[sev] || SEVERITY_PILL.info;
                            return (
                                <div key={`obj-${idx}`} className="bg-gray-50 border border-gray-200 rounded p-3 space-y-1">
                                    <div className="flex items-center gap-2 flex-wrap">
                                        {issue.directive && (
                                            <span className="font-mono text-xs bg-white border border-gray-200 px-2 py-0.5 rounded">{issue.directive}</span>
                                        )}
                                        {issue.path && (
                                            <span className="font-mono text-xs bg-white border border-gray-200 px-2 py-0.5 rounded break-all">{issue.path}</span>
                                        )}
                                        {issue.severity && (
                                            <span className={`inline-flex items-center border px-2 py-0.5 rounded-full text-[11px] font-semibold capitalize ${sevClasses}`}>
                                                {issue.severity}
                                            </span>
                                        )}
                                    </div>
                                    {issue.message && (
                                        <p className="text-xs text-gray-700">{issue.message}</p>
                                    )}
                                    {(issue.current || issue.recommended) && (
                                        <div className="text-xs text-gray-600 flex gap-4 flex-wrap">
                                            {issue.current && (
                                                <span><span className="text-gray-500">Current:</span> <span className="font-mono">{issue.current}</span></span>
                                            )}
                                            {issue.recommended && (
                                                <span><span className="text-gray-500">Recommended:</span> <span className="font-mono">{issue.recommended}</span></span>
                                            )}
                                        </div>
                                    )}
                                </div>
                            );
                        })}
                    </div>
                )}
                {/* Render any other keys alongside the issues block. */}
                {Object.entries(details).filter(([k]) => k !== 'issues').length > 0 && (
                    <div className="bg-gray-50 border border-gray-200 rounded p-3">
                        {Object.entries(details)
                            .filter(([k]) => k !== 'issues')
                            .map(([k, v]) => (
                                <KeyValueRow key={k} k={k} v={v} />
                            ))}
                    </div>
                )}
            </div>
        );
    }

    // Dangerous files
    if (Array.isArray(details?.files)) {
        return (
            <ul className="space-y-1">
                {details.files.map((f, idx) => (
                    <li key={idx} className="text-xs font-mono bg-gray-50 border border-gray-200 rounded px-2 py-1 break-all">{f}</li>
                ))}
            </ul>
        );
    }

    // Found users (admin_username)
    if (Array.isArray(details?.found_users)) {
        return (
            <div className="space-y-2">
                {details.found_users.map((u, idx) => (
                    <div key={idx} className="bg-gray-50 border border-gray-200 rounded p-3 flex items-center justify-between gap-3 flex-wrap">
                        <span className="font-mono text-xs">{u.username || '—'}</span>
                        <div className="flex flex-wrap gap-1">
                            {(u.roles || []).map((role, rIdx) => (
                                <span key={rIdx} className="inline-flex items-center bg-blue-100 text-blue-800 border border-blue-200 px-2 py-0.5 rounded-full text-[11px] font-semibold capitalize">{role}</span>
                            ))}
                        </div>
                    </div>
                ))}
            </div>
        );
    }

    // Plugin / theme updates (plugin_updates, theme_updates).
    // Backend shapes seen: { count, plugins:[...] }, { count, themes:[...] },
    // or a raw array of {name, current_version, available_version}.
    const looksLikeUpdateItem = (it) =>
        it && typeof it === 'object' && ('available_version' in it || 'current_version' in it);
    const updateList = Array.isArray(details?.plugins)
        ? { items: details.plugins, label: 'Plugin' }
        : Array.isArray(details?.themes)
        ? { items: details.themes, label: 'Theme' }
        : Array.isArray(details) && details.every(looksLikeUpdateItem)
        ? { items: details, label: 'Item' }
        : null;

    if (updateList && updateList.items.length > 0) {
        return (
            <div className="space-y-2">
                {typeof details.count === 'number' && (
                    <div className="text-xs text-gray-500">
                        {details.count} {updateList.label}{details.count === 1 ? '' : 's'} with available update{details.count === 1 ? '' : 's'}
                    </div>
                )}
                <div className="space-y-2">
                    {updateList.items.map((item, idx) => (
                        <div key={idx} className="bg-gray-50 border border-gray-200 rounded p-3">
                            <div className="text-xs font-semibold text-gray-800 break-all mb-2">
                                {item.name || '—'}
                            </div>
                            <div className="flex items-center gap-2 text-xs">
                                <span className="font-mono bg-white border border-gray-200 px-2 py-0.5 rounded text-gray-700">
                                    {item.current_version || '—'}
                                </span>
                                <span className="text-gray-400">→</span>
                                <span className="font-mono bg-white border border-blue-200 text-blue-700 px-2 py-0.5 rounded">
                                    {item.available_version || '—'}
                                </span>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        );
    }

    // Plain object — render as key/value rows.
    if (typeof details === 'object' && !Array.isArray(details)) {
        return (
            <div className="bg-gray-50 border border-gray-200 rounded p-3">
                {Object.entries(details).map(([k, v]) => (
                    <KeyValueRow key={k} k={k} v={v} />
                ))}
            </div>
        );
    }

    // Plain primitive
    return <p className="text-xs text-gray-700 break-all">{String(details)}</p>;
};

const CheckCard = ({ check }) => {
    const meta = STATUS_META[check.status] || STATUS_META.notice;
    const Icon = meta.Icon;
    const detailsContent = renderDetails(check.details);

    return (
        <div className={`bg-white rounded-lg border border-gray-200 border-l-4 ${meta.accent} shadow-sm`}>
            <div className="p-4">
                <div className="flex items-start gap-3 min-w-0">
                    <Icon className={`w-5 h-5 mt-0.5 flex-shrink-0 ${meta.iconColor}`} />
                    <div className="min-w-0">
                        <h4 className="text-sm font-semibold text-gray-900">{check.feature_label || formatKeyLabel(check.feature_key)}</h4>
                        {check.message && (
                            <p className="text-xs text-gray-600 mt-1">{check.message}</p>
                        )}
                    </div>
                </div>
                {detailsContent && (
                    <div className="mt-4 pt-3 border-t border-gray-100">
                        {detailsContent}
                    </div>
                )}
            </div>
        </div>
    );
};

const AuditReportDetails = () => {
    const { reportId } = useParams();
    const [report, setReport] = useState(null);
    const [loading, setLoading] = useState(true);
    const [statusFilter, setStatusFilter] = useState('all');
    const [searchTerm, setSearchTerm] = useState('');

    useEffect(() => {
        let cancelled = false;
        const load = async () => {
            setLoading(true);
            const data = await getHardeningAuditReportById(reportId);
            if (!cancelled) {
                // Backend wraps the report inside `data.report`. Fall back to data
                // if the wrapper ever gets removed.
                setReport(data?.report || data);
                setLoading(false);
            }
        };
        if (reportId) load();
        return () => {
            cancelled = true;
        };
    }, [reportId]);

    const checks = useMemo(() => {
        if (!report?.checks || typeof report.checks !== 'object') return [];
        return Object.values(report.checks);
    }, [report]);

    const filteredChecks = useMemo(() => {
        const term = searchTerm.trim().toLowerCase();
        return checks.filter((check) => {
            if (statusFilter !== 'all') {
                if (statusFilter === 'info') {
                    if (check.status !== 'notice' && check.status !== 'secure') return false;
                } else if (check.status !== statusFilter) {
                    return false;
                }
            }
            if (!term) return true;
            return (
                String(check.feature_label || '').toLowerCase().includes(term) ||
                String(check.feature_key || '').toLowerCase().includes(term) ||
                String(check.message || '').toLowerCase().includes(term)
            );
        });
    }, [checks, statusFilter, searchTerm]);

    const summary = report?.summary || {};
    const duration = formatDuration(report?.started_time, report?.completion_time);
    const filterTabs = [
        { key: 'critical', label: 'Critical', Icon: STATUS_META.critical.Icon, pill: STATUS_META.critical.pill },
        { key: 'error', label: 'Error', Icon: STATUS_META.error.Icon, pill: STATUS_META.error.pill },
        { key: 'warning', label: 'Warning', Icon: STATUS_META.warning.Icon, pill: STATUS_META.warning.pill },
        { key: 'info', label: 'Info', Icon: STATUS_META.notice.Icon, pill: STATUS_META.notice.pill },
    ];

    const tabCount = (key) => {
        if (key === 'info') {
            return (summary.notice ?? checks.filter((c) => c.status === 'notice').length)
                + (summary.secure ?? checks.filter((c) => c.status === 'secure').length);
        }
        return summary[key] ?? checks.filter((c) => c.status === key).length;
    };

    return (
        <div>
            <Header title="Hardening Audit Report" showBackIcon={true} />
            <div className="p-4 space-y-4">
                {loading ? (
                    <LoaderSkeleton count={0} />
                ) : !report ? (
                    <div className="bg-white rounded-md border border-gray-200 p-6 text-sm text-gray-700">
                        No report data available for ID <span className="font-mono">{reportId}</span>.
                    </div>
                ) : (
                    <>
                        {/* Summary card */}
                        <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-5">
                            <div className="flex items-center gap-2 flex-wrap">
                                <h2 className="text-base font-semibold text-gray-900">Audit Summary</h2>
                                <span className="inline-flex items-center bg-blue-100 text-blue-800 border border-blue-200 px-2.5 py-1 rounded-full text-xs font-semibold">
                                    {formatScanType(report.scan_type)}
                                </span>
                                <span className={`inline-flex items-center border px-2.5 py-1 rounded-full text-xs font-semibold ${
                                    report.is_completed
                                        ? 'bg-green-100 text-green-800 border-green-200'
                                        : 'bg-yellow-100 text-yellow-800 border-yellow-200'
                                }`}>
                                    {report.is_completed ? 'Completed' : 'In Progress'}
                                </span>
                            </div>
                            <div className="mt-2 text-xs text-gray-600 space-y-0.5">
                                <div>Report ID: <span className="font-mono text-gray-800">{report.id}</span></div>
                                {report.scan_time && (
                                    <div>Scanned at: <span className="text-gray-800">{report.scan_time}</span></div>
                                )}
                                {duration && (
                                    <div>Duration: <span className="text-gray-800">{duration}</span></div>
                                )}
                            </div>
                        </div>

                        {/* Filter bar */}
                        <div className="bg-white rounded-lg border border-gray-200 p-4 flex flex-wrap items-center gap-3 justify-between">
                            <div className="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    onClick={() => setStatusFilter('all')}
                                    className={`px-3 py-1 rounded-full text-xs font-semibold border transition-colors ${
                                        statusFilter === 'all'
                                            ? 'bg-[#007980] text-white border-[#007980]'
                                            : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                    }`}
                                >
                                    All ({checks.length})
                                </button>
                                {filterTabs.map((tab) => {
                                    const count = tabCount(tab.key);
                                    if (!count) return null;
                                    const active = statusFilter === tab.key;
                                    return (
                                        <button
                                            key={tab.key}
                                            type="button"
                                            onClick={() => setStatusFilter(tab.key)}
                                            className={`inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-semibold border transition-colors ${
                                                active ? tab.pill + ' ring-2 ring-offset-1 ring-current' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'
                                            }`}
                                        >
                                            <tab.Icon className="w-3.5 h-3.5" />
                                            {tab.label} ({count})
                                        </button>
                                    );
                                })}
                            </div>
                            <div className="relative w-full sm:w-80">
                                <Search className="absolute !left-3 !top-1/2 !-translate-y-1/2 !w-5 !h-5 !text-[#007980] pointer-events-none" />
                                <input
                                    type="text"
                                    value={searchTerm}
                                    onChange={(e) => setSearchTerm(e.target.value)}
                                    placeholder="Search checks..."
                                    className="!w-full !pl-11 !pr-10 !py-2.5 !bg-white !border-2 !border-gray-200 !rounded-lg focus:!ring-2 focus:!ring-[#007980]/30 focus:!border-[#007980] !shadow-sm !outline-none !text-sm !font-medium placeholder:!text-gray-400 !transition-colors"
                                />
                                {searchTerm && (
                                    <button
                                        type="button"
                                        onClick={() => setSearchTerm('')}
                                        aria-label="Clear search"
                                        className="!absolute !right-3 !top-1/2 !-translate-y-1/2 !text-gray-400 hover:!text-gray-700 !bg-transparent !border-0 !p-0 !shadow-none"
                                    >
                                        <X className="!w-4 !h-4" />
                                    </button>
                                )}
                            </div>
                        </div>

                        {/* Checks list */}
                        {filteredChecks.length === 0 ? (
                            <div className="bg-white rounded-md border border-gray-200 p-6 text-sm text-gray-600 text-center">
                                No checks match the current filter.
                            </div>
                        ) : (
                            <div className="space-y-3">
                                {filteredChecks.map((check) => (
                                    <CheckCard key={check.feature_key} check={check} />
                                ))}
                            </div>
                        )}
                    </>
                )}
            </div>
        </div>
    );
};

export default AuditReportDetails;
