import { Flags } from "../../IpManagement/WhiteListContent/WhiteListCountry/WhiteListCountryData";
import countries from 'i18n-iso-countries';
import { Link } from 'react-router-dom';
import { Clock, Shield, ShieldOff } from 'lucide-react';
import { CheckboxField } from "../../../Components/Fields/CheckboxField";

countries.registerLocale(require("i18n-iso-countries/langs/en.json"));

export const BruteForceData = ({ rowData, handleSelectItem, selectedItems, widget }) => {
    const isSelected = selectedItems.includes(rowData.ip);
    const isPermanent = rowData.lock_type === 'permanent';
    const isTemporary = rowData.lock_type === 'temporary';

    const getCountryName = (countryCode) => {
        const countryName = countries.getName(countryCode, 'en');
        return countryName || countryCode;
    };

    const formatDuration = (seconds) => {
        if (!seconds || seconds === 0) return '—';
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remainingSeconds = seconds % 60;
        if (hours > 0) return `${hours}h ${minutes}m`;
        if (minutes > 0) return `${minutes}m ${remainingSeconds}s`;
        return `${remainingSeconds}s`;
    };

    const getStatusConfig = () => {
        if (isPermanent) {
            return { icon: <Shield size={14} />, color: '!text-red-600', bgColor: '!bg-red-50', borderColor: '!border-red-200', label: 'Permanently Blocked', badgeClass: '!bg-red-100 !text-red-800 !border-red-200' };
        }
        if (isTemporary) {
            return { icon: <Clock size={14} />, color: '!text-amber-600', bgColor: '!bg-amber-50', borderColor: '!border-amber-200', label: 'Temporarily Blocked', badgeClass: '!bg-amber-100 !text-amber-800 !border-amber-200' };
        }
        return { icon: <ShieldOff size={14} />, color: '!text-green-600', bgColor: '!bg-green-50', borderColor: '!border-green-200', label: 'Active (Unblocked)', badgeClass: '!bg-green-100 !text-green-800 !border-green-200' };
    };

    const statusConfig = getStatusConfig();

    return (
        <>
            {!widget && (
                <td className="!p-3">
                    <div className="!flex !items-center">
                        <CheckboxField checked={isSelected} onChange={() => handleSelectItem(rowData.ip)} />
                    </div>
                </td>
            )}

            <td className="!p-3 !text-sm !text-gray-900 !font-medium">{rowData.ip || '—'}</td>

            <td className="!p-3">
                <div className="!flex !items-center !space-x-3">
                    <Flags countryCode={rowData.country_code} size="lg" />
                    <div className="!flex !flex-col">
                        <span className="!font-medium !text-gray-900">
                            {getCountryName(rowData.country_code)}
                        </span>
                        <span className="!text-xs !text-gray-500 !font-mono">
                            {rowData.country_code}
                        </span>
                    </div>
                </div>
            </td>

            <td className="!p-3">
                <div className={`!inline-flex !items-center !space-x-2 !px-3 !py-2 !rounded-lg !border ${statusConfig.bgColor} ${statusConfig.borderColor}`}>
                    <span className={statusConfig.color}>
                        {statusConfig.icon}
                    </span>
                    <div className="!flex !flex-col">
                        <span className={`!text-xs !font-medium ${statusConfig.color}`}>
                            {statusConfig.label}
                        </span>
                        {isTemporary && (
                            <span className="!text-xs !text-gray-500">
                                Expires in {rowData.time_remaining || formatDuration(rowData.lock_duration)}
                            </span>
                        )}
                    </div>
                </div>
            </td>

            <td className="!p-3 !text-sm !text-gray-700">{rowData.attempt_count || 0}</td>
            <td className="!p-3 !text-sm !text-gray-700">{rowData.reason || '—'}</td>
            <td className="!p-3 !text-sm !text-gray-700">{rowData.last_attempt_time || 'No recent attempts'}</td>
            <td className="!p-3 !text-sm !text-gray-700">{formatDuration(rowData.lock_duration)}</td>

            <td className="!p-3 !text-sm">
                <Link
                    to={`/dashboard/login-defender/ip-details?ip=${encodeURIComponent(rowData.ip)}`}
                    className="!inline-flex !items-center !px-3 !py-2 !text-sm !font-medium !text-white !bg-[#007980] hover:!bg-opacity-90 !rounded-lg !transition-colors !duration-200"
                >
                    View Details
                </Link>
            </td>
        </>
    );
};
