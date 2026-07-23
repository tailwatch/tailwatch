import React from 'react';
import {
    Globe, MapPin, AlertTriangle, ShieldAlert, Shield, Lock, Activity, Clock,
    Monitor as MonitorIcon, User, ShieldCheck
} from 'lucide-react';
import { GrFirefox } from 'react-icons/gr';
import { FaEdge, FaSafari, FaChrome } from 'react-icons/fa';
import { Flags } from '../../../IpManagement/WhiteListContent/WhiteListCountry/WhiteListCountryData';
import countries from 'i18n-iso-countries';

countries.registerLocale(require('i18n-iso-countries/langs/en.json'));

/* ---------- Helpers ---------- */
const getBrowserInfo = (userAgent) => {
    if (!userAgent || typeof userAgent !== 'string') return { name: 'Unknown', icon: MonitorIcon };
    if (userAgent.includes('Edg/')) return { name: 'Microsoft Edge', icon: FaEdge };
    if (userAgent.includes('Chrome/') && !userAgent.includes('Edg/')) return { name: 'Google Chrome', icon: FaChrome };
    if (userAgent.includes('Firefox/')) return { name: 'Mozilla Firefox', icon: GrFirefox };
    if (userAgent.includes('Safari/') && !userAgent.includes('Chrome/')) return { name: 'Safari', icon: FaSafari };
    return { name: 'Unknown', icon: MonitorIcon };
};

const formatTimestamp = (timestamp) => {
    if (!timestamp) return null;
    try {
        const d = new Date(timestamp);
        if (isNaN(d.getTime())) return null;
        return {
            time: d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit', second: '2-digit' }),
            date: d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }),
        };
    } catch {
        return null;
    }
};

const formatDuration = (seconds) => {
    if (!seconds || seconds <= 0) return null;
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    const s = seconds % 60;
    const parts = [];
    if (h) parts.push(`${h}h`);
    if (m) parts.push(`${m}m`);
    if (s && !h) parts.push(`${s}s`);
    return parts.join(' ');
};

const getRiskLevel = (activity) => {
    if (!activity) return { label: 'Unknown', tone: 'gray' };
    if (activity.is_permanently_blocked) return { label: 'Critical · Permanently Blocked', tone: 'red' };
    const lockType = activity.lock_type || 'none';
    if (lockType !== 'none') return { label: 'High · Currently Blocked', tone: 'red' };
    const attempts = activity.attempt_count || 0;
    const threshold = activity.failed_attempt_threshold || 5;
    const ratio = attempts / threshold;
    if (ratio >= 0.8) return { label: 'High · Near Block Threshold', tone: 'red' };
    if (ratio >= 0.5) return { label: 'Medium · Elevated Activity', tone: 'amber' };
    if (attempts > 0) return { label: 'Low · Minor Activity', tone: 'blue' };
    return { label: 'Safe · No Recent Threats', tone: 'green' };
};

const TONE_STYLES = {
    red:   { bg: 'bg-red-50',     text: 'text-red-700',     border: 'border-red-200',     dot: 'bg-red-500' },
    amber: { bg: 'bg-amber-50',   text: 'text-amber-700',   border: 'border-amber-200',   dot: 'bg-amber-500' },
    blue:  { bg: 'bg-blue-50',    text: 'text-blue-700',    border: 'border-blue-200',    dot: 'bg-blue-500' },
    green: { bg: 'bg-emerald-50', text: 'text-emerald-700', border: 'border-emerald-200', dot: 'bg-emerald-500' },
    gray:  { bg: 'bg-gray-50',    text: 'text-gray-700',    border: 'border-gray-200',    dot: 'bg-gray-400' },
};

/* ---------- Atomic UI pieces ---------- */
const StatTile = ({ icon: Icon, label, value, sub, tone = 'gray' }) => {
    const styles = TONE_STYLES[tone];
    return (
        <div className="!bg-white !border !border-gray-200 !rounded-lg !p-4 !flex !items-start !gap-3 !min-w-0">
            <div className={`!w-10 !h-10 !rounded-lg ${styles.bg} ${styles.border} !border !flex !items-center !justify-center !flex-shrink-0`}>
                <Icon className={`!w-5 !h-5 ${styles.text}`} />
            </div>
            <div className="!min-w-0 !flex-1">
                <div className="!text-[10px] !font-semibold !text-gray-500 !uppercase !tracking-wider !mb-1">{label}</div>
                <div className="!text-base !font-semibold !text-gray-900 !truncate" title={String(value)}>{value}</div>
                {sub && <div className="!text-xs !text-gray-500 !mt-0.5 !truncate" title={String(sub)}>{sub}</div>}
            </div>
        </div>
    );
};

/* ---------- Main component ---------- */
const CurrentActivityData = ({ currentActivity }) => {
    const activity = currentActivity || {};
    const countryCode = activity.country_code;
    const countryName = activity.country || (countryCode ? countries.getName(countryCode, 'en') : null);
    const browser = getBrowserInfo(activity.browser || activity.user_agent);
    const BrowserIcon = browser.icon;

    const attempts = activity.attempt_count ?? 0;
    const threshold = activity.failed_attempt_threshold ?? 0;
    const remaining = activity.remaining_attempts ?? null;
    const tempBlocks = activity.temp_block_count ?? 0;
    const rateViolations = activity.rate_limit_violations ?? 0;
    const lockType = activity.lock_type || 'none';
    const lockDuration = formatDuration(activity.lock_duration);
    // Backend now ships current_activity.time_remaining as a pre-formatted human
    // string (e.g. "23 hours, 59 minutes" or "Permanent"). Prefer it; fall back
    // to the client-side formatted lockDuration when the field is missing.
    const timeRemaining = activity.time_remaining || null;
    const ipType = (activity.ip_address && String(activity.ip_address).includes(':')) ? 'IPv6' : 'IPv4';

    const risk = getRiskLevel(activity);
    const riskStyles = TONE_STYLES[risk.tone];

    const timestamps = Array.isArray(activity.attempt_timestamps) ? activity.attempt_timestamps : [];
    const lastAttempt = timestamps.length > 0 ? formatTimestamp(timestamps[timestamps.length - 1]) : null;
    const lastRateLimit = formatTimestamp(activity.last_rate_limit_time);

    // Lock tone for the System Response tile
    const lockTone =
        lockType === 'permanent' ? 'red'
        : lockType !== 'none' ? 'amber'
        : 'green';

    return (
        <div className="!space-y-6">
            {/* Hero card — IP, location, risk */}
            <div className="!bg-white !border !border-gray-200 !rounded-xl !overflow-hidden !shadow-sm">
                <div className="!flex !items-start !justify-between !gap-4 !p-5 !flex-wrap">
                    <div className="!flex !items-start !gap-4 !min-w-0">
                        <div className="!w-12 !h-12 !rounded-lg !bg-[#007980]/10 !flex !items-center !justify-center !flex-shrink-0">
                            <Globe className="!w-6 !h-6 !text-[#007980]" />
                        </div>
                        <div className="!min-w-0">
                            <div className="!flex !items-center !gap-2 !mb-1 !flex-wrap">
                                <h2 className="!text-lg !font-semibold !text-gray-900 !font-mono !truncate" title={activity.ip_address}>
                                    {activity.ip_address || 'Unknown IP'}
                                </h2>
                                <span className="!inline-flex !items-center !text-[10px] !font-semibold !text-gray-700 !bg-gray-100 !border !border-gray-200 !rounded-full !px-2 !py-0.5 !uppercase !tracking-wider">
                                    {ipType}
                                </span>
                            </div>
                            <div className="!flex !items-center !gap-3 !text-sm !text-gray-600 !flex-wrap">
                                {countryCode && <Flags countryCode={countryCode} size="sm" />}
                                <span className="!inline-flex !items-center !gap-1.5">
                                    <MapPin className="!w-3.5 !h-3.5 !text-gray-400" />
                                    {countryName || 'Unknown location'}
                                    {countryCode && <span className="!text-gray-400">· {countryCode}</span>}
                                </span>
                                {activity.username && (
                                    <span className="!inline-flex !items-center !gap-1.5">
                                        <User className="!w-3.5 !h-3.5 !text-gray-400" />
                                        {activity.username}
                                    </span>
                                )}
                            </div>
                        </div>
                    </div>

                    {/* Risk badge */}
                    <div className={`!inline-flex !items-center !gap-2 !px-3 !py-2 !rounded-lg !border ${riskStyles.bg} ${riskStyles.border}`}>
                        <span className={`!w-2 !h-2 !rounded-full ${riskStyles.dot}`} />
                        <span className={`!text-xs !font-semibold ${riskStyles.text}`}>{risk.label}</span>
                    </div>
                </div>
            </div>

            {/* Stats grid */}
            <div className="!grid !grid-cols-1 sm:!grid-cols-2 lg:!grid-cols-4 !gap-4">
                <StatTile
                    icon={AlertTriangle}
                    label="Failed Attempts"
                    value={attempts}
                    sub={threshold > 0 ? `Threshold ${threshold}` : (remaining != null ? `${remaining} remaining` : null)}
                    tone={attempts >= (threshold || Infinity) ? 'red' : attempts > 0 ? 'amber' : 'gray'}
                />
                <StatTile
                    icon={ShieldAlert}
                    label="Rate Violations"
                    value={rateViolations}
                    sub={lastRateLimit ? `Last: ${lastRateLimit.date} · ${lastRateLimit.time}` : 'No violations'}
                    tone={rateViolations > 0 ? 'amber' : 'gray'}
                />
                <StatTile
                    icon={Lock}
                    label="Temporary Blocks"
                    value={tempBlocks}
                    sub={tempBlocks > 0 ? 'Past escalations' : 'Never blocked'}
                    tone={tempBlocks > 0 ? 'amber' : 'gray'}
                />
                <StatTile
                    icon={lockType === 'none' ? ShieldCheck : Shield}
                    label="Lock Status"
                    value={lockType === 'none' ? 'Not Locked' : (lockType === 'permanent' ? 'Permanent Lock' : 'Temporary Lock')}
                    // Permanent locks have no countdown — render dashes so users
                    // don't see misleading "Expires in …" text for a forever lock.
                    sub={
                        lockType === 'permanent'
                            ? '---'
                            : (lockType !== 'none' && timeRemaining)
                                ? `Expires in ${timeRemaining}`
                                : (lockDuration ? `Duration: ${lockDuration}` : null)
                    }
                    tone={lockTone}
                />
            </div>

            {/* Detail row — Client + last attempt */}
            <div className="!grid !grid-cols-1 lg:!grid-cols-2 !gap-4">
                <div className="!bg-white !border !border-gray-200 !rounded-lg !p-4 !flex !items-start !gap-3">
                    <div className="!w-10 !h-10 !rounded-lg !bg-gray-50 !border !border-gray-200 !flex !items-center !justify-center !flex-shrink-0">
                        <BrowserIcon className="!w-5 !h-5 !text-gray-600" />
                    </div>
                    <div className="!min-w-0 !flex-1">
                        <div className="!text-[10px] !font-semibold !text-gray-500 !uppercase !tracking-wider !mb-1">Client / Browser</div>
                        <div className="!text-sm !font-semibold !text-gray-900">{browser.name}</div>
                        {activity.user_agent && (
                            <div className="!text-xs !text-gray-500 !mt-1 !truncate" title={activity.user_agent}>{activity.user_agent}</div>
                        )}
                    </div>
                </div>

                <div className="!bg-white !border !border-gray-200 !rounded-lg !p-4 !flex !items-start !gap-3">
                    <div className="!w-10 !h-10 !rounded-lg !bg-gray-50 !border !border-gray-200 !flex !items-center !justify-center !flex-shrink-0">
                        <Clock className="!w-5 !h-5 !text-gray-600" />
                    </div>
                    <div className="!min-w-0 !flex-1">
                        <div className="!text-[10px] !font-semibold !text-gray-500 !uppercase !tracking-wider !mb-1">Last Attempt</div>
                        {lastAttempt ? (
                            <>
                                <div className="!text-sm !font-semibold !text-gray-900">{lastAttempt.time}</div>
                                <div className="!text-xs !text-gray-500 !mt-0.5">{lastAttempt.date}</div>
                            </>
                        ) : (
                            <div className="!text-sm !text-gray-500">No recent attempts</div>
                        )}
                    </div>
                </div>
            </div>

            {/* Attack timeline — compact horizontal strip */}
            {timestamps.length > 0 && (
                <div className="!bg-white !border !border-gray-200 !rounded-lg !p-4">
                    <div className="!flex !items-center !justify-between !mb-3 !flex-wrap !gap-2">
                        <div className="!flex !items-center !gap-2">
                            <Activity className="!w-4 !h-4 !text-[#007980]" />
                            <h3 className="!text-sm !font-semibold !text-gray-800">Attempt Timeline</h3>
                        </div>
                        <span className="!text-xs !font-semibold !text-gray-600 !bg-gray-100 !rounded-full !px-2.5 !py-0.5">
                            {timestamps.length} {timestamps.length === 1 ? 'attempt' : 'attempts'}
                        </span>
                    </div>
                    <div className="!flex !gap-2 !overflow-x-auto custom-scroll-bar !pb-2">
                        {timestamps.map((ts, i) => {
                            const t = formatTimestamp(ts);
                            return (
                                <div key={i} className="!flex-shrink-0 !min-w-[120px] !border !border-gray-200 !rounded-md !p-2 !bg-gray-50">
                                    <div className="!text-[10px] !font-semibold !text-gray-500 !uppercase !tracking-wider !mb-0.5">#{i + 1}</div>
                                    <div className="!text-sm !font-semibold !text-gray-900">{t?.time || '—'}</div>
                                    <div className="!text-xs !text-gray-500 !mt-0.5">{t?.date || ''}</div>
                                </div>
                            );
                        })}
                    </div>
                </div>
            )}
        </div>
    );
};

export default CurrentActivityData;
