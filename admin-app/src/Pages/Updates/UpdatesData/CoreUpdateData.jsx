import React from 'react';
import { Link } from 'react-router-dom';
import { CircleCheckBig, Download } from 'lucide-react'
import ActionButton from '../../../Components/Buttons/ActionButton';
import { useNavigate } from 'react-router-dom';

export const CoreUpdateData = ({ rowData }) => {
    const navigate = useNavigate();

    if (!rowData) return null;

    const formatVersion = (version) => {
        if (!version) return version;
        const parts = version.split('.');

        if (parts.length >= 3 && parts[2].length > 2) {
            parts[2] = parts[2].substring(0, 2);
        }
        return parts.slice(0, 3).join('.');
    };

    // Get slug for navigation URL
    const getNavigationSlug = () => {
        return rowData.slug || '';
    };

    return (
        <>
            <td className="px-6 py-5">
                <div className="flex items-start space-x-3">
                    <div className="flex-shrink-0 mt-1">
                        {rowData.update_available ? (
                            <div className="w-2 h-2 bg-orange-600 rounded-full animate-pulse"></div>
                        ) : (
                            <div className="w-2 h-2 bg-[#007980] rounded-full"></div>
                        )}
                    </div>

                    <div className="flex flex-col flex-1">
                        <span className="text-sm font-semibold text-slate-900 hover:text-slate-700 transition-colors">
                            {rowData.name}
                        </span>
                        <div className="flex items-center mt-1 space-x-2">
                            {rowData.update_available && (
                                <span className="text-xs font-medium text-[#48bcc2] bg-[#48bcc2]/10 px-2 py-0.5 rounded-full border border-[#48bcc2]/20">
                                    Update Available
                                </span>
                            )}
                        </div>
                    </div>
                </div>
            </td>

            <td className="px-6 py-5 whitespace-nowrap">
                <div className="flex items-center space-x-2">
                    <span className="text-sm text-slate-700 font-mono bg-slate-100 px-3 py-1 rounded-md border border-slate-200 shadow-sm">
                        {formatVersion(rowData?.current_version || rowData?.update_version)}
                    </span>
                    {!rowData.update_available && (
                        <div className="w-4 h-4 text-[#007980]">
                            <CircleCheckBig size={16} />
                        </div>
                    )}
                </div>
            </td>

            <td className="px-6 py-5 whitespace-nowrap">
                <div className="flex items-center space-x-2">
                    {rowData.update_version ? (
                        <>
                            <span className={`text-sm font-mono px-3 py-1 rounded-md border shadow-sm ${rowData.version !== rowData.update_version
                                ? 'text-blue-700 bg-blue-50 border-blue-200'
                                : 'text-slate-700 bg-slate-100 border-slate-200'
                                }`}>
                                {formatVersion(rowData.update_version)}
                            </span>
                         
                        </>
                    ) : (
                        <span className="text-sm text-slate-400 italic">No updates</span>
                    )}
                </div>
            </td>

            <td className="px-6 py-5 whitespace-nowrap">
                <div className="flex items-center gap-2">
                    <ActionButton
                        onClick={() => {
                            const baseSlug = encodeURIComponent(getNavigationSlug());
                            const textDomain = rowData.text_domain ? `&${encodeURIComponent(rowData.text_domain)}` : '';
                            navigate(`/dashboard/updates/details/${baseSlug}${textDomain}&coreUpdates`);
                        }}
                        defaultText="View Details"
                    />
                </div>
            </td>
        </>
    );
};
