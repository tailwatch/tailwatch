import React, { useRef } from 'react';
import { Link } from 'react-router-dom';
import { Download, Power, PowerOff, Trash2 } from 'lucide-react'
import ActionButton from '../../../Components/Buttons/ActionButton';
import { useNavigate } from 'react-router-dom';
import DropdownMenu from '../../../Components/DropdownMenu/DropdownMenu';
import { CheckboxField } from '../../../Components/Fields/CheckboxField';
import { activatePluginTheme, deactivatePluginTheme, deletePluginTheme } from '../UpdateServices/UpdateServices';
import { alertService } from '../../../Components/AlertService/AlertService';
import { Tooltip } from '../../../Components/ToolTip/Tooltip';

export const PluginUpdateData = ({ rowData, onRefetch, isSelected, onSelectChange }) => {
    const navigate = useNavigate();
    const dropdownRef = useRef(null);

    if (!rowData) return null;

    const isProtected = rowData.is_protected === true;

    const handleCheckboxChange = () => {
        if (rowData.plugin && onSelectChange) {
            onSelectChange(rowData.plugin);
        }
    };

    const formatVersion = (version) => {
        if (!version) return version;
        const parts = version.split('.');

        if (parts.length >= 3 && parts[2].length > 2) {
            parts[2] = parts[2].substring(0, 2);
        }
        return parts.slice(0, 3).join('.');
    };

    const getCleanSlug = () => {
        if (rowData.plugin) {
            // Convert slug from "akismet\/akismet.php" to "akismet/akismet.php"
            return rowData.plugin.replace(/\\/g, '');
        }
        return rowData.slug || '';
    };

    // Get slug for navigation URL
    const getNavigationSlug = () => {
        return rowData.slug || '';
    };

    const handleActivate = async () => {
        // Check if activation is blocked
        if (!rowData.can_activate) {
            alertService.showError('Cannot Activate', rowData.activation_blocked_reason || 'Activation is not allowed');
            return;
        }

        try {
            alertService.showLoading('Activating...', 'Activating plugin. Please wait…');
            await activatePluginTheme('wptw_activate_plugin', 'plugin', getCleanSlug());
            alertService.closeLoading();
            if (onRefetch) onRefetch();
        } catch (error) {
            alertService.closeLoading();
            console.error('Failed to activate plugin:', error);
        }
    };

    const handleDeactivate = async () => {
        // Check if deactivation is blocked
        if (!rowData.can_deactivate) {
            alertService.showError('Cannot Deactivate', rowData.deactivation_blocked_reason || 'Deactivation is not allowed');
            return;
        }

        const confirmed = await alertService.deactivate(
            `Are you sure you want to deactivate "${rowData.name}"?`,
            'Deactivate Plugin?',
            'Deactivate',
            'Cancel'
        );

        if (confirmed) {
            try {
                alertService.showLoading('Deactivating...', 'Deactivating plugin. Please wait…');
                await deactivatePluginTheme('wptw_deactivate_plugin', 'plugin', getCleanSlug());
                alertService.closeLoading();
                if (onRefetch) onRefetch();
            } catch (error) {
                alertService.closeLoading();
                console.error('Failed to deactivate plugin:', error);
            }
        }
    };

    const handleDelete = async () => {
        // Check if deletion is blocked
        if (!rowData.can_delete) {
            alertService.showError('Cannot Delete', rowData.deletion_blocked_reason || 'Deletion is not allowed');
            return;
        }

        const confirmed = await alertService.confirm(
            `Are you sure you want to permanently delete "${rowData.name}"? This action cannot be undone.`,
            'Delete Plugin?',
            'Delete',
            'Cancel'
        );

        if (confirmed) {
            try {
                alertService.showLoading('Deleting...', 'Deleting plugin. Please wait…');
                await deletePluginTheme('wptw_delete_plugin', 'plugin', getCleanSlug());
                alertService.closeLoading();
                if (onRefetch) onRefetch();
            } catch (error) {
                alertService.closeLoading();
                console.error('Failed to delete plugin:', error);
            }
        }
    };

    const getDropdownMenuItems = () => {
        const items = [];

        // Plugin: Show Activate OR Deactivate based on status
        if (!rowData.is_active) {
            items.push({
                label: 'Activate',
                icon: <Power size={16} />,
                onClick: handleActivate,
                disabled: !rowData.can_activate
            });
        } else {
            items.push({
                label: 'Deactivate',
                icon: <PowerOff size={16} />,
                onClick: handleDeactivate,
                disabled: !rowData.can_deactivate || isProtected,
                tooltipMessage: isProtected ? 'This plugin is protected and cannot be deactivated from this dashboard' : undefined
            });
        }

        // Plugin: Delete
        items.push({
            label: 'Delete',
            icon: <Trash2 size={16} />,
            onClick: handleDelete,
            disabled: !rowData.can_delete || isProtected,
            tooltipMessage: isProtected
                ? 'This plugin is protected and cannot be deleted from this dashboard'
                : (!rowData.can_delete ? rowData.deletion_blocked_reason || 'This plugin cannot be deleted' : undefined)
        });

        return items;
    };

    const menuItems = getDropdownMenuItems();

    // Render dependency and restriction info for plugins
    const renderPluginInfo = () => {
        const infoItems = [];

        // Show activation blocked reason (skip "already active" message)
        if (!rowData.can_activate && rowData.activation_blocked_reason &&
            !rowData.activation_blocked_reason.toLowerCase().includes('already active')) {
            infoItems.push(rowData.activation_blocked_reason);
        }

        // Show deactivation blocked reason
        if (!rowData.can_deactivate && rowData.deactivation_blocked_reason) {
            infoItems.push(rowData.deactivation_blocked_reason);
        }

        // Show deletion blocked reason
        if (!rowData.can_delete && rowData.deletion_blocked_reason) {
            // infoItems.push(rowData.deletion_blocked_reason);
        }

        // Show required by (plugins that depend on this one)
        if (rowData.dependency_info?.required_by && rowData.dependency_info.required_by.length > 0) {
            const requiredByNames = rowData.dependency_info.required_by.map(dep => dep.name).join(', ');
            infoItems.push(`Required by: ${requiredByNames}`);
        }

        // Show requires (plugins this one depends on)
        if (rowData.dependency_info?.requires && rowData.dependency_info.requires.length > 0) {
            const requiresNames = rowData.dependency_info.requires.map(dep => dep.name).join(', ');
            infoItems.push(`Requires: ${requiresNames}`);
        }

        if (infoItems.length === 0) return null;

        return (
            <div className="mt-1.5 text-xs text-gray-600 space-y-0.5">
                {infoItems.map((text, index) => (
                    <div key={index}>{text}</div>
                ))}
            </div>
        );
    };

    return (
        <>
            <td className="px-3 py-4 whitespace-nowrap text-sm text-black">
                <CheckboxField checked={isSelected || false} onChange={handleCheckboxChange} />
            </td>

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
                        {renderPluginInfo()}
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
                    {isProtected ? (
                        <Tooltip message="This plugin is protected and details cannot be viewed from this dashboard" position="top">
                            <ActionButton
                                onClick={() => {}}
                                defaultText="View Details"
                                isDisabled={true}
                            />
                        </Tooltip>
                    ) : (
                        <ActionButton
                            onClick={() => {
                                const baseSlug = encodeURIComponent(rowData.plugin || getNavigationSlug());
                                const textDomain = rowData.text_domain ? `&${encodeURIComponent(rowData.text_domain)}` : '';
                                navigate(`/dashboard/updates/details/${baseSlug}${textDomain}&plugin`);
                            }}
                            defaultText="View Details"
                        />
                    )}
                    {menuItems.length > 0 && (
                        <div ref={dropdownRef}>
                            <DropdownMenu
                                id={`plugin-actions-${rowData.slug}`}
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
