import React, { useState } from 'react';
import {
    AlertTriangle, CheckCircle, Ban, Lock, Activity, Info, AlertCircle, Eye, X
} from 'lucide-react';
import Table from '../../../../Components/Table/Table';

/* ---------- Event-type config (icon, label, color tone) ---------- */
const EVENT_CONFIGS = {
    successful_login: {
        icon: CheckCircle,
        label: 'Successful Login',
        iconText: 'text-emerald-600',
        iconBg: 'bg-emerald-50',
        chip: 'bg-emerald-50 text-emerald-700 border-emerald-200',
    },
    block: {
        icon: Ban,
        label: 'Security Block',
        iconText: 'text-red-600',
        iconBg: 'bg-red-50',
        chip: 'bg-red-50 text-red-700 border-red-200',
    },
    attempt: {
        icon: AlertTriangle,
        label: 'Failed Login Attempt',
        iconText: 'text-amber-600',
        iconBg: 'bg-amber-50',
        chip: 'bg-amber-50 text-amber-700 border-amber-200',
    },
    permanent_block: {
        icon: Lock,
        label: 'Permanent Block',
        iconText: 'text-red-700',
        iconBg: 'bg-red-50',
        chip: 'bg-red-100 text-red-800 border-red-300',
    },
};

const getEventConfig = (eventType) => EVENT_CONFIGS[eventType] || {
    icon: Info,
    // Title-case unknown event types so "reset" → "Reset",
    // "password_change" → "Password Change", etc.
    label: eventType ? titleCase(eventType) : 'Event',
    iconText: 'text-gray-600',
    iconBg: 'bg-gray-50',
    chip: 'bg-gray-50 text-gray-700 border-gray-200',
};

/* ---------- Formatters for the dedicated columns ---------- */
const titleCase = (str) =>
    String(str || '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());

const formatDuration = (seconds) => {
    if (!seconds || isNaN(seconds)) return null;
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    const parts = [];
    if (h) parts.push(`${h}h`);
    if (m) parts.push(`${m}m`);
    if (s && !h) parts.push(`${s}s`);
    return parts.join(' ') || null;
};

const formatTimestamp = (timestamp) => {
    if (!timestamp) return { time: '—', date: '—' };
    try {
        const date = new Date(timestamp);
        if (isNaN(date.getTime())) return { time: '—', date: '—' };
        return {
            time: date.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
            date: date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }),
        };
    } catch {
        return { time: '—', date: '—' };
    }
};

/* Keys that are surfaced as their own column, so they shouldn't show up again
   in the catch-all "Extra Details" column. */
const RESERVED_DETAIL_KEYS = new Set([
    'attempt_type', 'attempt_count', 'remaining_attempts',
    'username', 'user_exists', 'user_id',
    'reason',
]);

const renderUserCell = (details) => {
    const username = details?.username;
    if (!username) return <span className="text-gray-400">—</span>;
    return <span className="text-sm font-medium text-gray-900">{username}</span>;
};

const renderAttemptCell = (details) => {
    const count = details?.attempt_count;
    const remaining = details?.remaining_attempts;
    if (count == null && remaining == null) return <span className="text-gray-400">—</span>;
    // Render as "N of TOTAL" where TOTAL is attempt_count + remaining_attempts.
    // Falls back gracefully if either side is missing.
    const total = (count != null && remaining != null) ? Number(count) + Number(remaining) : null;
    return (
        <div className="flex flex-col">
            {total != null ? (
                <>
                    <span className="text-sm font-semibold text-gray-900">{count} of {total} attempts</span>
                    {remaining != null && (
                        <span className="text-xs text-gray-500">{remaining} remaining</span>
                    )}
                </>
            ) : count != null ? (
                <span className="text-sm font-semibold text-gray-900">{count} {Number(count) === 1 ? 'attempt' : 'attempts'}</span>
            ) : (
                <span className="text-xs text-gray-500">{remaining} remaining</span>
            )}
        </div>
    );
};

// Pairs of escalation-style fields that should render as a single combined
// cell in the modal — same "X of Y" format as the Attempts column.
const ESCALATION_PAIRS = [
    { totalKey: 'escalation_threshold', remainingKey: 'escalation_remaining', label: 'Escalations' },
];

/* Returns the list of "extra" keys (everything in `details` that isn't already
   shown in a dedicated column). Reused by the cell button + the modal body.
   Escalation threshold/remaining pairs are auto-merged into one entry. */
const getExtraEntries = (details) => {
    if (!details) return [];
    const merged = [];
    const skipKeys = new Set();

    // Merge escalation pairs first so their source keys are skipped below.
    ESCALATION_PAIRS.forEach(({ totalKey, remainingKey, label }) => {
        const total = details[totalKey];
        const remaining = details[remainingKey];
        if (total != null && remaining != null) {
            const current = Number(total) - Number(remaining);
            merged.push({
                key: `${totalKey}__merged`,
                label,
                value: `${current} of ${total}`,
            });
            skipKeys.add(totalKey);
            skipKeys.add(remainingKey);
        }
    });

    Object.keys(details).forEach((key) => {
        if (RESERVED_DETAIL_KEYS.has(key) || skipKeys.has(key)) return;
        if (details[key] == null || details[key] === '') return;
        let value = details[key];
        if (key === 'lock_duration') {
            value = formatDuration(Number(value)) || `${value}s`;
        } else if (typeof value === 'boolean') {
            value = value ? 'Yes' : 'No';
        } else if (typeof value === 'string' && /^[a-z][a-z_]*$/.test(value)) {
            // enum-like strings (e.g. "temporary", "entire_site") → "Temporary", "Entire Site"
            value = titleCase(value);
        }
        merged.push({ key, label: titleCase(key), value: String(value) });
    });

    return merged;
};

/* Modal that renders all extra-detail fields for one event in a clean grid.
   Same look-and-feel as the in-table cells used to have, just unpinned from
   the column so wide content doesn't squeeze the table. */
const EventDetailModal = ({ event, onClose }) => {
    const [showModal, setShowModal] = useState(false);
    React.useEffect(() => { setShowModal(true); }, []);

    if (!event) return null;
    const config = getEventConfig(event.event_type);
    const time = formatTimestamp(event.timestamp);
    const Icon = config.icon;
    const details = event.details || {};
    const extra = getExtraEntries(details);
    const reason = details.reason;

    const handleClose = () => {
        setShowModal(false);
        setTimeout(() => onClose?.(), 200);
    };

    return (
        <div className="fixed top-0 left-0 w-full h-full bg-gray-900/70 backdrop-blur-sm flex justify-center items-center z-[1000] p-4">
            <div
                className={`bg-white rounded-xl shadow-2xl transform transition-all duration-200 w-full max-w-2xl max-h-[90vh] flex flex-col
                    ${showModal ? 'scale-100 opacity-100' : 'scale-95 opacity-0'}`}
                style={{ border: '2px solid rgba(0, 121, 128, 0.25)' }}
            >
                {/* Header */}
                <div className="flex items-center justify-between gap-3 px-5 py-4 border-b border-gray-200">
                    <div className="flex items-center gap-3 min-w-0">
                        <div className={`w-10 h-10 rounded-lg ${config.iconBg} flex items-center justify-center flex-shrink-0`}>
                            <Icon className={`w-5 h-5 ${config.iconText}`} />
                        </div>
                        <div className="min-w-0">
                            <h3 className="text-base font-semibold text-gray-900 truncate">{config.label}</h3>
                            <div className="flex items-center gap-2 mt-0.5 flex-wrap">
                                <span className={`inline-flex items-center text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded border ${config.chip}`}>
                                    {event.event_type}
                                </span>
                                <span className="text-xs text-gray-500">{time.date} · {time.time}</span>
                            </div>
                        </div>
                    </div>
                    <button
                        type="button"
                        onClick={handleClose}
                        className="w-8 h-8 rounded-full flex items-center justify-center text-gray-400 hover:bg-gray-100 hover:text-gray-700 transition-colors border-0 bg-transparent cursor-pointer flex-shrink-0"
                    >
                        <X size={20} />
                    </button>
                </div>

                {/* Body — grid of label/value pairs */}
                <div className="flex-1 overflow-y-auto p-5">
                    {extra.length === 0 && !reason ? (
                        <div className="text-center py-8 text-sm text-gray-500">
                            No extra details recorded for this event.
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {extra.length > 0 && (
                                <div>
                                    <h4 className="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-2">
                                        Event Data
                                    </h4>
                                    <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                        {extra.map(({ key, label, value }) => (
                                            <div key={key} className="bg-gray-50 border border-gray-200 rounded-lg px-3 py-2">
                                                <div className="text-[10px] font-semibold text-gray-500 uppercase tracking-wider mb-0.5">
                                                    {label}
                                                </div>
                                                <div className="text-sm font-medium text-gray-900 break-words" title={value}>
                                                    {value}
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                </div>
                            )}

                            {reason && (
                                <div>
                                    <h4 className="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-2">
                                        Reason
                                    </h4>
                                    <div className="bg-amber-50 border border-amber-200 rounded-lg px-3 py-2 flex items-start gap-2">
                                        <AlertCircle className="w-4 h-4 text-amber-600 flex-shrink-0 mt-0.5" />
                                        <span className="text-sm text-amber-900">{reason}</span>
                                    </div>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="px-5 py-3 border-t border-gray-200 flex justify-end">
                    <button
                        type="button"
                        onClick={handleClose}
                        className="px-4 py-2 text-sm font-semibold text-white bg-[#007980] rounded-lg hover:bg-[#005f64] transition-colors border-0 cursor-pointer"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    );
};

const renderExtraCell = (details, onView) => {
    if (!details) return <span className="text-gray-400">—</span>;
    const extra = getExtraEntries(details);
    const hasReason = details.reason && String(details.reason).trim() !== '';
    // Button is reachable whenever there's anything worth showing — extra fields
    // OR a reason. That way the modal is the single home for full event detail.
    if (extra.length === 0 && !hasReason) return <span className="text-gray-400">—</span>;

    return (
        <button
            type="button"
            onClick={onView}
            className="inline-flex items-center gap-1.5 px-2.5 py-1 text-xs font-semibold text-[#007980] bg-white border border-[#007980]/40 rounded-md hover:bg-[#007980]/5 hover:border-[#007980] transition-colors cursor-pointer whitespace-nowrap"
            title="View full event details"
        >
            <Eye className="w-3.5 h-3.5" />
            View Details
        </button>
    );
};

/* ---------- Main component ---------- */
const HistoryData = ({ ipData }) => {
    const events = Array.isArray(ipData) ? ipData : [];
    const [selectedEvent, setSelectedEvent] = useState(null);

    const columns = ['Event', 'Date & Time', 'User', 'Attempts', 'Extra Details'];

    const renderRow = (event) => {
        const config = getEventConfig(event.event_type);
        const time = formatTimestamp(event.timestamp);
        const Icon = config.icon;
        const details = event.details || {};
        const attemptType = details.attempt_type;

        return (
            <>
                {/* Event — icon + label + raw type chip */}
                <td className="p-3 align-top">
                    <div className="flex items-start gap-2.5 min-w-0">
                        <div className={`w-8 h-8 rounded-md ${config.iconBg} flex items-center justify-center flex-shrink-0`}>
                            <Icon className={`w-4 h-4 ${config.iconText}`} />
                        </div>
                        <div className="min-w-0">
                            <div className="text-sm font-semibold text-gray-900">{config.label}</div>
                            <div className="flex items-center gap-1.5 mt-0.5 flex-wrap">
                                <span className={`inline-flex items-center text-[10px] font-semibold uppercase tracking-wider px-1.5 py-0.5 rounded border ${config.chip}`}>
                                    {event.event_type}
                                </span>
                                {attemptType && (
                                    <span className="text-[10px] font-semibold text-gray-600 bg-gray-100 border border-gray-200 rounded px-1.5 py-0.5 uppercase tracking-wider">
                                        {attemptType}
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>
                </td>

                {/* Date & Time */}
                <td className="p-3 align-top whitespace-nowrap">
                    <div className="flex flex-col">
                        <span className="text-sm font-medium text-gray-900">{time.time}</span>
                        <span className="text-xs text-gray-500">{time.date}</span>
                    </div>
                </td>

                {/* User */}
                <td className="p-3 align-top">
                    {renderUserCell(details)}
                </td>

                {/* Attempts */}
                <td className="p-3 align-top whitespace-nowrap">
                    {renderAttemptCell(details)}
                </td>

                {/* Extra Details — button opens a modal with the full payload (incl. reason) */}
                <td className="p-3 align-top">
                    {renderExtraCell(details, () => setSelectedEvent(event))}
                </td>
            </>
        );
    };

    return (
        <div className="mt-6">
            {/* Section header */}
            <div className="flex items-center justify-between mb-3 flex-wrap gap-2">
                <div className="flex items-center gap-2">
                    <Activity className="w-5 h-5 text-[#007980]" />
                    <h2 className="text-base font-semibold text-gray-900">Security Event Log</h2>
                    <span className="text-xs text-gray-500 hidden sm:inline">— recent activity for this IP</span>
                </div>
                <span className="inline-flex items-center text-xs font-semibold text-gray-700 bg-white border border-gray-200 rounded-full px-3 py-1">
                    {events.length} {events.length === 1 ? 'event' : 'events'}
                </span>
            </div>

            <Table
                columns={columns}
                data={events}
                renderRow={renderRow}
                noDataMessage="No security events recorded for this IP yet."
            />

            {selectedEvent && (
                <EventDetailModal
                    event={selectedEvent}
                    onClose={() => setSelectedEvent(null)}
                />
            )}
        </div>
    );
};

export default HistoryData;
