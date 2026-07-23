import React, { useState, useEffect } from 'react';
import PopUpModal from '../../../Components/Modal/PopUpModal';
import InputField from '../../../Components/Fields/InputField';
import { AlertTriangle, Info, Eye } from 'lucide-react';
import RadioButtons from '../../../Components/RadioButtons/RadioButtons';
import { addToBlackList, updateBlackList, addIpToWhiteList, updateIpWhiteList } from '../IpManagementServices/IpManagementServices';
import { GetPostTypes } from '../../../Components/GetPostTypes/GetPostTypes';
import BlockPagePreviewModal from '../BlockPagePreviewModal/BlockPagePreviewModal';

const IpManagementModal = ({ onClose, getData, isEditing = false, initialData = null, isWhitelist = false }) => {
    const [ipValidationErrors, setIpValidationErrors] = useState([]);
    const [formData, setFormData] = useState({ ip_ranges: '', block_type: 'temporary', block_duration: '24', durationUnit: 'Hours', scope: 'login_forms', reason: '', exemption: 'login_defender' });
    const [isLoading, setLoading] = useState(false);
    const [blockPage, setBlockPage] = useState(null);
    const [targetMode, setTargetMode] = useState('default');
    const [showPreview, setShowPreview] = useState(false);

    const blockScopeOptions = [
        {
            value: 'login_forms',
            title: 'Login Forms Only',
            description: 'Block access to login/registration forms'
        },
        {
            value: 'entire_site',
            title: 'Entire Site',
            description: 'Block access to the entire website'
        }
    ];
    const exemptionOptions = [
        {
            value: 'login_defender',
            label: 'Login Defender'
        }
    ];

    const validateIPAddresses = (ipString) => {
        if (!ipString || ipString.trim() === '') {
            return { isValid: true, errors: [] };
        }

        const ipList = ipString.split(',').map(ip => ip.trim()).filter(ip => ip !== '');
        const errors = [];

        // IPv4 regex patterns
        const ipv4Pattern = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/;
        const cidrPattern = /^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\/(?:[0-9]|[1-2][0-9]|3[0-2])$/;

        ipList.forEach((ip, index) => {
            if (ip === '') return; // Skip empty entries        
            if (ipv4Pattern.test(ip)) {
                return;
            }
            if (cidrPattern.test(ip)) {
                return; // Valid CIDR
            }
            errors.push(`"${ip}" is not a valid IP address or range`);
        });

        return { isValid: errors.length === 0, errors: errors };
    };
    // Function to convert seconds to duration and unit
    const convertSecondsToDuration = (seconds) => {
        if (seconds >= 86400 && seconds % 86400 === 0) {
            return { duration: seconds / 86400, unit: 'Days' };
        } else if (seconds >= 3600 && seconds % 3600 === 0) {
            return { duration: seconds / 3600, unit: 'Hours' };
        } else {
            return { duration: seconds / 60, unit: 'Minutes' };
        }
    };

    useEffect(() => {
        if (initialData) {
            const ipRangesString = Array.isArray(initialData.ip_ranges) ? initialData.ip_ranges.join(', ') : initialData.ip_range || '';

            if (isWhitelist) {
                // For whitelist, only set IP ranges and exemption
                setFormData({
                    ip_ranges: ipRangesString, exemption: initialData.exemption || 'web_shield',
                    // Keep other fields with defaults
                    block_type: 'temporary',
                    block_duration: '24',
                    durationUnit: 'Hours',
                    scope: 'login_forms',
                    reason: ''
                });
            } else {
                // Original blacklist logic
                if (initialData.block_type === 'permanent') {
                    setFormData({ ip_ranges: ipRangesString, block_type: initialData.block_type, block_duration: '', durationUnit: 'Hours', scope: initialData.scope || 'login_forms', reason: initialData.reason || '', exemption: 'login_defender' });
                } else {
                    const { duration, unit } = convertSecondsToDuration(initialData.block_duration);
                    setFormData({ ip_ranges: ipRangesString, block_type: initialData.block_type, block_duration: duration.toString(), durationUnit: unit, scope: initialData.scope || 'login_forms', reason: initialData.reason || '', exemption: 'login_defender' });
                }
            }

            // Pre-fill the Target Page section when editing an existing rule.
            // Backend now returns block_page (post ID OR custom URL) AND
            // block_page_post_type so we can restore the exact pick.
            if (!isWhitelist && initialData.block_page != null && initialData.block_page !== '') {
                setTargetMode('specific');
                setBlockPage(initialData.block_page);
            } else {
                setTargetMode('default');
                setBlockPage(null);
            }
        } else {
            // Default values
            setFormData({ ip_ranges: '', block_type: 'temporary', block_duration: '24', durationUnit: 'Hours', scope: 'login_forms', reason: '', exemption: 'login_defender' });
            setTargetMode('default');
            setBlockPage(null);
        }
    }, [initialData, isWhitelist]);

    const handleInputChange = (field, value) => {
        setFormData(prev => ({ ...prev, [field]: value, }));

        // Validate IP addresses if the field is ip_ranges
        if (field === 'ip_ranges') {
            const validation = validateIPAddresses(value);
            setIpValidationErrors(validation.errors);
        }
    };

    const convertDurationToSeconds = (duration, unit) => {
        const durationValue = parseInt(duration) || 0;
        switch (unit) {
            case 'Minutes':
                return durationValue * 60;
            case 'Hours':
                return durationValue * 60 * 60;
            case 'Days':
                return durationValue * 60 * 60 * 24;
            default:
                return durationValue * 60 * 60;
        }
    };

    // Parse the input string into an array of IP addresses/ranges
    const parseIpRanges = (inputString) => {
        return inputString.split(',').map(ip => ip.trim()).filter(ip => ip.length > 0);
    };

     const isFormValid = () => {        
        if (!formData.ip_ranges.trim()) {
            return false;
        }                
        if (ipValidationErrors.length > 0) {
            return false;
        }
        
        return true;
    };

    const handleSubmit = async () => {
    if (!isFormValid()) {
        return;
    }

    try {
        setLoading(true);
        const ipRangesArray = parseIpRanges(formData.ip_ranges);

        if (isWhitelist) {
            const payload = { 
                ip_ranges: ipRangesArray, 
                exemption: formData.exemption 
            };

            if (isEditing) {
                await updateIpWhiteList({ onClose, setLoading, payload, getData });
            } else {
                await addIpToWhiteList({ onClose, setLoading, payload, getData });
            }
        } else {
            // BlackList
            let payload = { 
                ip_ranges: ipRangesArray, 
                block_type: formData.block_type, 
                reason: formData.reason 
            };

            if (formData.block_type === "temporary") {
                payload.block_duration = convertDurationToSeconds(formData.block_duration, formData.durationUnit);
                payload.scope = formData.scope;
                payload.block_page = formData.scope === 'entire_site' && targetMode === 'specific' ? blockPage : null;
            } else if (formData.block_type === "permanent") {
                payload.block_duration = "0";
                payload.scope = formData.scope;
                payload.block_page = formData.scope === 'entire_site' && targetMode === 'specific' ? blockPage : null;
            }
            // if block_type === "unblocked" → no block_duration, no scope

            if (isEditing) {
                await updateBlackList({ onClose, setLoading, payload, getData });
            } else {
                await addToBlackList({ onClose, setLoading, payload, getData });
            }
        }
    } catch (error) {
        console.error("Error processing IP:", error);
        setLoading(false);
    }
};


    const modalContent = (
        <div className="space-y-4 px-2">
            <div className="p-3 mb-2 bg-yellow-50 border border-yellow-200 rounded-md flex items-start gap-2">
                <div className="text-yellow-600 mt-0.5">
                    <AlertTriangle size={16} />
                </div>
                <div className="text-sm text-yellow-800">
                    <p className="font-medium">Warning</p>
                    <p>
                        {isWhitelist ? 'This will immediately whitelist the specified IP addresses/ranges. Make sure this is correct.' : 'This will immediately block access from the specified IP addresses/ranges. Make sure this is correct.'}
                    </p>
                </div>
            </div>

            <div>
                <InputField  required label="IP Addresses & Ranges" type="text" value={formData.ip_ranges} onChange={(e) => handleInputChange('ip_ranges', e.target.value)} placeholder="192.168.1.1, 10.0.0.0/24, 203.0.113.5" disabled={isEditing} error={ipValidationErrors.length > 0}  />
                {ipValidationErrors.length > 0 && (
                    <div className="mt-2 p-3 bg-red-50 border border-red-200 rounded-md">
                        <div className="text-sm text-red-800">
                            <p className="font-medium mb-1">Invalid IP addresses found:</p>
                            <ul className="space-y-1">
                                {ipValidationErrors.map((error, index) => (
                                    <li key={index} className="text-xs">• {error}</li>
                                ))}
                            </ul>
                        </div>
                    </div>
                )}
                <div className="mt-2 p-3 bg-blue-50 border border-blue-200 rounded-md">
                    <div className="flex items-start gap-2">
                        <div className="text-blue-600 mt-0.5">
                            <Info size={16} />
                        </div>
                        <div className="text-sm text-blue-800">
                            <p className="font-medium mb-1">How to format IP addresses and ranges:</p>
                            <ul className="space-y-1 text-xs">
                                <li>• <strong>Single IP:</strong> 192.168.1.1</li>
                                <li>• <strong>IP Range:</strong> 192.168.1.0/24</li>
                                <li>• <strong>Multiple entries:</strong> Separate with commas</li>
                                <li>• <strong>Example:</strong> 192.168.1.1, 10.0.0.0/24, 203.0.113.5</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            {isWhitelist ? (
                // Whitelist specific fields
                <div>
                    <label className="block text-sm font-medium text-black mb-2">Exemption Type</label>
                    <div className="flex flex-col sm:flex-row gap-3 w-full">
                        {exemptionOptions.map((option, index) => (
                            <RadioButtons
                                key={option.value}
                                id="exemption"
                                index={index}
                                value={option.value}
                                selectedValue={formData.exemption}
                                handleChange={(e) => handleInputChange('exemption', e.target.value)}
                                title={option.label}
                                description="Exempt these IP addresses from Login Defender rules"
                            />
                        ))}
                    </div>
                </div>
            ) : (
                // Original blacklist fields
                <>
                    <div>
                        <label className="block text-sm font-medium text-black mb-2">Block Type & Duration</label>
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <select required value={formData.block_type} onChange={(e) => handleInputChange('block_type', e.target.value)} className="!px-3 !py-2 !border !border-gray-300 !rounded-md !focus:outline-none focus:ring-1 focus:ring-[#007980] focus:border-transparent" >
                                {isEditing ? <option value="unblocked">Unblock</option> : ''}
                                <option value="temporary">Temporary</option>
                                <option value="permanent">Permanent</option>
                            </select>
                            <div className="flex border border-gray-300 rounded-md overflow-hidden focus-within:ring-1 focus-within:ring-[#007980] focus-within:border-transparent">
                                <input
                                    type="number"
                                    value={formData.block_duration}
                                    onChange={(e) => {
                                        const value = parseInt(e.target.value, 10);
                                        if (value >= 1 || e.target.value === '') {
                                            handleInputChange('block_duration', e.target.value);
                                        }
                                    }}
                                    min="1"
                                    className="flex-1 px-3 py-2 border-0 focus:outline-none"
                                    disabled={formData.block_type === 'permanent'}
                                />
                                <select
                                    value={formData.durationUnit}
                                    onChange={(e) => handleInputChange('durationUnit', e.target.value)}
                                    className="px-3 py-2 border-0 border-l border-gray-300 bg-gray-50 focus:outline-none cursor-pointer"
                                    disabled={formData.block_type === 'permanent'}
                                >
                                    <option value="Minutes">Minutes</option>
                                    <option value="Hours">Hours</option>
                                    <option value="Days">Days</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-black mb-2">Block Scope</label>
                        <div className="flex flex-col sm:flex-row gap-3 w-full">
                            {blockScopeOptions.map((option, index) => (
                                <RadioButtons key={option.value} id="blockScope" index={index} value={option.value} selectedValue={formData.scope} handleChange={(e) => handleInputChange('scope', e.target.value)} title={option.title} description={option.description} />
                            ))}
                        </div>
                        <p className="text-xs text-gray-500 mt-1">Determine where this IP block should be applied</p>
                        {formData.scope === 'entire_site' && (
                            <div className="mt-3">
                                <label className="block text-sm font-medium text-black mb-2">Target Page</label>
                                <div className="!inline-flex !items-center !p-0.5 !bg-gray-100 !rounded-lg !border !border-gray-200 !mb-3">
                                    <button
                                        type="button"
                                        onClick={() => { setTargetMode('default'); setBlockPage(null); }}
                                        className={`!px-3 !py-1.5 !text-xs !font-semibold !rounded-md !transition-all !border-0 !cursor-pointer
                                            ${targetMode === 'default' ? '!bg-white !text-[#007980] !shadow-sm' : '!bg-transparent !text-gray-500 hover:!text-gray-700'}`}
                                    >
                                        Default
                                    </button>
                                    <button
                                        type="button"
                                        onClick={() => setTargetMode('specific')}
                                        className={`!px-3 !py-1.5 !text-xs !font-semibold !rounded-md !transition-all !border-0 !cursor-pointer
                                            ${targetMode === 'specific' ? '!bg-white !text-[#007980] !shadow-sm' : '!bg-transparent !text-gray-500 hover:!text-gray-700'}`}
                                    >
                                        Specific Page
                                    </button>
                                </div>

                                {targetMode === 'default' ? (
                                    <div className="!p-3 !bg-gradient-to-r !from-[#007980]/5 !to-[#85cbcf]/15 !border !border-[#007980]/25 !rounded-lg !flex !items-center !justify-between !gap-3 !flex-wrap sm:!flex-nowrap">
                                        <div className="!flex !items-center !gap-3 !min-w-0">
                                            <div className="!w-9 !h-9 !rounded-full !bg-[#007980]/10 !flex !items-center !justify-center !flex-shrink-0">
                                                <Eye className="!w-4 !h-4 !text-[#007980]" />
                                            </div>
                                            <div className="!min-w-0">
                                                <p className="!text-sm !font-semibold !text-gray-800">Default Block Page</p>
                                                <p className="!text-xs !text-gray-500">The built-in page blocked visitors will see</p>
                                            </div>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => setShowPreview(true)}
                                            className="!inline-flex !items-center !gap-1.5 !px-3 !py-1.5 !text-xs !font-semibold !text-white !bg-[#007980] !rounded-md hover:!bg-[#005f64] !transition-colors !whitespace-nowrap !border-0 !cursor-pointer !shadow-sm"
                                        >
                                            <Eye className="!w-3.5 !h-3.5" />
                                            Click to Preview
                                        </button>
                                    </div>
                                ) : (
                                    <GetPostTypes
                                        onSelectionChange={(data) => {
                                            if (data.isCustom && data.customUrl) {
                                                setBlockPage(data.customUrl);
                                            } else if (data.postId) {
                                                setBlockPage(data.postId);
                                            } else {
                                                setBlockPage(null);
                                            }
                                        }}
                                        initialPostId={initialData?.block_page_post_type && initialData.block_page_post_type !== 'custom' ? initialData.block_page : ''}
                                        initialPostType={initialData?.block_page_post_type === 'custom' ? '' : (initialData?.block_page_post_type || '')}
                                        initialCustomUrl={initialData?.block_page_post_type === 'custom' ? String(initialData.block_page || '') : ''}
                                    />
                                )}
                            </div>
                        )}
                    </div>

                    <div>
                        <label className="block text-sm font-medium text-black mb-2">Reason (Optional)</label>
                        <textarea value={formData.reason} onChange={(e) => handleInputChange('reason', e.target.value)} placeholder="Administrative notes about this block" rows="3"
                            className="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-[#007980] focus:border-transparent resize-none"
                        />
                    </div>
                </>
            )}
        </div>
    );

    const getModalTitle = () => {
        if (isWhitelist) {
            return isEditing ? 'Edit IP Whitelist' : 'Add to Whitelist';
        }
        return isEditing ? 'Edit IP Block' : 'Block IP Addresses';
    };

    const getSaveButtonText = () => {
        if (isWhitelist) {
            return isEditing ? 'Update Whitelist' : 'Add to Whitelist';
        }
        return 'Block IPs';
    };

    return (
        <>
            <PopUpModal showExpandIcon={true} title={getModalTitle()} onClose={onClose} onSave={handleSubmit} isLoading={isLoading} disabled={!formData.ip_ranges.trim()} saveButtonText={getSaveButtonText()} cancelButtonText="Cancel" width="w-full max-w-lg sm:max-w-xl md:max-w-2xl" >
                {modalContent}
            </PopUpModal>
            {showPreview && (
                <BlockPagePreviewModal
                    onClose={() => setShowPreview(false)}
                    payload={{ scope: 'default', context: 'ip' }}
                />
            )}
        </>
    );
};

export default IpManagementModal;
