import React, { useRef } from 'react';
import { Link } from 'react-router-dom';
import { Download, Power, Trash2 } from 'lucide-react'
import ActionButton from '../../../Components/Buttons/ActionButton';
import { useNavigate } from 'react-router-dom';
import DropdownMenu from '../../../Components/DropdownMenu/DropdownMenu';
import { activatePluginTheme, deletePluginTheme } from '../UpdateServices/UpdateServices';
import { alertService } from '../../../Components/AlertService/AlertService';

export const ThemeUpdateData = ({ rowData, onRefetch }) => {
    const navigate = useNavigate();
    const dropdownRef = useRef(null);

    if (!rowData) return null;

    const formatVersion = (version) => {
        if (!version) return version;
        const parts = version.split('.');

        if (parts.length >= 3 && parts[2].length > 2) {
            parts[2] = parts[2].substring(0, 2);
        }
        return parts.slice(0, 3).join('.');
    };

    const getCleanSlug = () => {
        if (rowData.theme) {
            return rowData.theme;
        }
        return rowData.slug || '';
    };

    // Get slug for navigation URL
    const getNavigationSlug = () => {
        if (rowData.theme) {
            return rowData.theme;
        }
        return rowData.slug || '';
    };

    const handleActivate = async () => {
        try {
            alertService.showLoading('Theme Activate', 'Please wait while we activate your theme…');
            await activatePluginTheme('wptw_activate_theme', 'theme', getCleanSlug());
            alertService.closeLoading();
            if (onRefetch) onRefetch();
        } catch (error) {
            alertService.closeLoading();
            console.error('Failed to activate theme:', error);
        }
    };

    const handleDelete = async () => {
        const confirmed = await alertService.confirm(
            `Are you sure you want to permanently delete "${rowData.name}"? This action cannot be undone.`,
            'Delete Theme?',
            'Delete',
            'Cancel'
        );

        if (confirmed) {
            try {
                alertService.showLoading('Deleting Theme', 'Please wait while we delete your theme…');
                await deletePluginTheme('wptw_delete_theme', 'theme', getCleanSlug());
                alertService.closeLoading();
                if (onRefetch) onRefetch();
            } catch (error) {
                alertService.closeLoading();
                console.error('Failed to delete theme:', error);
            }
        }
    };

    const getDropdownMenuItems = () => {
        const items = [];

        if (!rowData.is_active) {
            items.push({
                label: 'Activate',
                icon: <Power size={16} />,
                onClick: handleActivate,
                disabled: false
            });
        }

        items.push({ 
            label: 'Delete', 
            icon: <Trash2 size={16} />, 
            onClick: handleDelete, 
            disabled: rowData.is_active,
            tooltipMessage: !rowData.can_delete ? rowData.deletion_blocked_reason || "Active theme can't be deleted" : undefined
        });

        return items;
    };

    const menuItems = getDropdownMenuItems();

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
                            {rowData.is_active ? (
                                <span className="text-xs font-medium text-green-700 bg-green-50 px-2 py-0.5 rounded-full border border-green-200">
                                    Activated
                                </span>
                            ) : (
                                <span className="text-xs font-medium text-slate-500 bg-slate-100 px-2 py-0.5 rounded-full border border-slate-300">
                                    Deactivated
                                </span>
                            )}
                        </div>
                    </div>
                </div>
            </td>

            <td className="px-6 py-5 whitespace-nowrap">
                <div className="flex items-center space-x-2">
                    <span className="text-sm text-slate-700 font-mono bg-slate-100 px-3 py-1 rounded-md border border-slate-200 shadow-sm">
                        {formatVersion(rowData.version || rowData?.update_version)}
                    </span>
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
                            navigate(`/dashboard/updates/details/${baseSlug}${textDomain}&theme`);
                        }}
                        defaultText="View Details"
                    />
                    {menuItems.length > 0 && (
                        <div ref={dropdownRef}>
                            <DropdownMenu
                                id={`theme-actions-${rowData.slug}`}
                                buttonLabel=""
                                menuItems={menuItems}
                                parentRef={dropdownRef}
                            />
                        </div>
                    )}
                </div>
            </td>
        </>
    );
};
