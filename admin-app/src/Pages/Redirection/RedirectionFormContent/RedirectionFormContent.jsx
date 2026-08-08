import { Controller } from 'react-hook-form';
import ToggleSwitch from '../../../Components/ToggleSwitcher/ToggleSwitch';
import React, { useState, useRef, memo } from 'react';
import { createPortal } from 'react-dom';
import { Spinner } from '../../../Components/Spinner/Spinner';
import { GetPostTypes } from '../../../Components/GetPostTypes/GetPostTypes'

const trimUrl = (url, maxLength = 25) => {
    if (!url || url.length <= maxLength) return url;
    const urlWithoutProtocol = url.replace(/^https?:\/\//, '');
    if (urlWithoutProtocol.length <= maxLength) return urlWithoutProtocol;
    return urlWithoutProtocol.substring(0, maxLength - 3) + '...';
};

const UrlTooltip = memo(({ url, maxLength = 25 }) => {
    const [tooltipPos, setTooltipPos] = useState(null);
    const triggerRef = useRef(null);
    const trimmedUrl = trimUrl(url, maxLength);
    const shouldTrim = url && url.length > maxLength;

    const handleEnter = () => {
        if (!url || !triggerRef.current) return;
        const rect = triggerRef.current.getBoundingClientRect();
        setTooltipPos({ left: rect.left, top: rect.bottom + 6 });
    };

    const handleLeave = () => setTooltipPos(null);

    return (
        <div
            ref={triggerRef}
            className="!inline-block"
            onMouseEnter={handleEnter}
            onMouseLeave={handleLeave}
        >
            <span className="!bg-gray-100 !text-gray-600 !px-3 !py-2 !text-sm !whitespace-nowrap !border-r !border-gray-300 !rounded-l-md !cursor-help !block">
                {trimmedUrl}
            </span>
            {tooltipPos && createPortal(
                <div
                    className="!fixed !z-[10000] !bg-gray-800 !text-white !text-xs !rounded !px-2 !py-1 !shadow-xl !border !border-gray-700 !max-w-[90vw] !break-all !pointer-events-none"
                    style={{ left: tooltipPos.left, top: tooltipPos.top }}
                >
                    {url}
                </div>,
                document.body
            )}
        </div>
    );
});

const MultiSelectCheckbox = memo(({ options, selected, onChange, isDisabled }) => {
    const [isOpen, setIsOpen] = useState(false);

    return (
        <div className="!relative">
            <div className="!w-full !px-3 !py-2 !border !border-gray-300 !rounded-md focus:!outline-none focus:!ring-1 focus:!ring-[#007980] !bg-white !cursor-pointer"
                onClick={() => { if (!isDisabled) setIsOpen(!isOpen); }}
            >
                <div className="!flex !gap-2 !overflow-x-auto !whitespace-nowrap" style={{ scrollbarWidth: 'thin' }}>
                    {selected.length > 0 ?
                        selected.map(role => (
                            <span key={role} className="!bg-[#007980] !text-white !px-2 !py-1 !rounded-md !text-sm !flex-shrink-0">
                                {options[role]}
                            </span>
                        )) :
                        <span className="!text-gray-500">Select Options</span>
                    }
                </div>
            </div>
            {isOpen && !isDisabled && (
                <div className="!absolute !z-10 !w-full !mt-1 !bg-white !border !border-gray-300 !rounded-md !shadow-lg !max-h-60 !overflow-y-auto custom-scroll-bar">
                    {Object.entries(options).map(([key, value]) => (
                        <div key={key} className="!flex !items-center !px-3 !py-2 hover:!bg-gray-100">
                            <input
                                type="checkbox"
                                id={`role-${key}`}
                                checked={selected.includes(key)}
                                onChange={() => onChange(key)}
                                className="!w-5 !h-5 !accent-[#007980]"
                            />
                            <label htmlFor={`role-${key}`} className="!ml-2 !text-sm !text-gray-700">
                                {value}
                            </label>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
});

// Block source URLs that look like full URLs (https://...), include dots, or contain special characters.
// Allowed: letters, numbers, hyphens, underscores, slashes. Returns true if valid, otherwise an
// error message string for react-hook-form.
const validateSourceSlug = (value) => {
    if (!value) return true;
    const trimmed = value.trim();
    if (/^https?:\/\//i.test(trimmed) || /^\/\//.test(trimmed)) {
        return "Don't include the full URL. Enter just the path (e.g., dashboard or /products/sale).";
    }
    if (/:\/\//.test(trimmed)) {
        return "Don't include the protocol. Enter only the path part of the URL.";
    }
    if (trimmed.includes('.')) {
        return "Dots (.) are not allowed. Don't include domain extensions like .com or .org — enter only the clean path (e.g., dashboard or /products/sale).";
    }
    if (/[^a-zA-Z0-9_\-\/]/.test(trimmed)) {
        return "Special characters are not allowed. Use only letters, numbers, hyphens (-), underscores (_), and slashes (/).";
    }
    return true;
};

//"apply_conditions": false,
export const RedirectionFormContent = memo(({ control, errors, formValues, userRoles, setValue, handleRoleSelection, showAdvancedOptions, setShowAdvancedOptions, showInclusionRules, setShowInclusionRules, redirectCodes, matchTypeOptions, handleSelectionChange, initialPostType, initialPostId, initialCustomUrl, disableSourceUrl = false }) => {
    /* global tailwatch_ajax */
    const baseUrl = typeof tailwatch_ajax !== 'undefined' ? tailwatch_ajax.base_url : 'http://localhost/';

    return (
        <div className="!space-y-6">
            <div className="!grid !grid-cols-1 !gap-6 !items-start">
                <div className="!space-y-2 !bg-[#07C07E1A] !p-6 !rounded-md">
                    <label className="!block !text-sm !font-medium !text-gray-700">Redirect from (a specific URL):</label>
                    {/* <p className="!text-xs !text-gray-600 !break-all !font-mono">{baseUrl}</p> */}
                    <Controller name="sourceUrl" control={control} rules={{ required: "Source URL is required", validate: validateSourceSlug }}
                        render={({ field }) => (
                            <div>
                                <div className={`!flex !items-center !w-full !border ${errors.sourceUrl ? '!border-red-500' : '!border-gray-300'} !rounded-md focus-within:!outline-none focus-within:!ring-1 focus-within:!ring-[#007980] ${disableSourceUrl ? '!bg-gray-100 !cursor-not-allowed' : '!bg-white'}`}>
                                    <UrlTooltip url={baseUrl} maxLength={25} />
                                    <input
                                        value={field.value || ''}
                                        onChange={(e) => field.onChange(e.target.value)}
                                        onBlur={field.onBlur}
                                        type="text"
                                        placeholder="dashboard"
                                        disabled={disableSourceUrl}
                                        className={`!flex-1 !px-2 !py-2 !border-0 focus:!outline-none focus:!ring-0 !rounded-r-md ${disableSourceUrl ? '!bg-gray-100 !cursor-not-allowed !text-gray-500' : ''}`}
                                    />
                                </div>
                                {/* {!disableSourceUrl && (
                                    <p className="!text-xs !text-gray-500 !mt-1.5">
                                        Use only letters, numbers, hyphens (<span className="!font-mono">-</span>), underscores (<span className="!font-mono">_</span>), and slashes (<span className="!font-mono">/</span>) — e.g., <span className="!font-mono">dashboard</span> or <span className="!font-mono">/products/sale</span>. No <span className="!font-mono">https://</span>, dots, or special characters.
                                    </p>
                                )} */}
                                {errors.sourceUrl && <p className="!text-red-500 !text-sm !mt-1">{errors.sourceUrl.message}</p>}
                            </div>
                        )}
                    />
                </div>
                <div className="!space-y-2 !bg-[#07C07E1A] !p-6 !rounded-md">
                    <label className="!block !text-sm !font-medium !text-gray-700">to (a specific URL):</label>
                    <GetPostTypes onSelectionChange={handleSelectionChange} initialPostType={initialPostType} initialPostId={initialPostId} initialCustomUrl={initialCustomUrl} />
                </div>
            </div>
            <div className="!space-y-2">
                <label className="!block !text-sm !font-medium !text-gray-700">Source URL</label>
                <div className="!grid !grid-cols-1 md:!grid-cols-2 lg:!grid-cols-3 !gap-3">
                    <div className="!flex !items-center">
                        <Controller name="ignoreCase" control={control} render={({ field: { value, onChange } }) => (
                            <>
                                <input type="checkbox" id="lower-upper" checked={value} onChange={(e) => onChange(e.target.checked)} className="!w-5 !h-5 !accent-[#007980]" />
                                <label htmlFor="lower-upper" className="!ml-2 !text-sm !text-gray-700">Ignore lower/upper case</label>
                            </>
                        )}
                        />
                    </div>
                    <div className="!flex !items-center">
                        <Controller name="ignoreParameters" control={control} render={({ field: { value, onChange } }) => (
                            <>
                                <input type="checkbox" id="parameters" checked={value} onChange={(e) => onChange(e.target.checked)} className="!w-5 !h-5 !accent-[#007980]" />
                                <label htmlFor="parameters" className="!ml-2 !text-sm !text-gray-700">Ignore parameters</label>
                            </>
                        )}
                        />
                    </div>
                </div>
            </div>

            <div className="!flex !items-center !justify-between !mt-4">
                <div className="!flex !items-center">
                    <span className="!text-sm !text-gray-500">with the default settings</span>
                    <button
                        type="button"
                        className="!ml-2 !text-[#007980] hover:!underline !text-sm"
                        onClick={() => setShowAdvancedOptions(!showAdvancedOptions)}
                    >
                        {showAdvancedOptions ? 'Hide advanced options' : 'Show advanced options'}
                    </button>
                </div>
            </div>

            {showAdvancedOptions && (
                <div className="!bg-white !p-5 !rounded-lg !shadow-md !border !border-gray-200 !mt-4">
                    <div className="!flex !items-center !justify-center !mb-4">
                        <div className="!flex !items-center !justify-center !w-10 !h-10 !bg-[#007980] !rounded-full">
                            <svg className="!w-6 !h-6 !text-white" fill="currentColor" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                                <path fillRule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clipRule="evenodd"></path>
                            </svg>
                        </div>
                        <h3 className="!ml-3 !text-lg !font-medium !text-gray-900">Advanced options</h3>
                    </div>

                    <div className="!space-y-6">

                        <div className="!space-y-2">
                            <label className="!block !text-sm !font-medium !text-gray-700">Redirect HTTP code:</label>
                            <Controller name="statusCode" control={control}
                                render={({ field }) => (
                                    <select value={field.value || '301'}
                                        onChange={(e) => field.onChange(e.target.value)}
                                        onBlur={field.onBlur}
                                        className="!w-full !px-3 !py-2 !border !border-gray-300 !rounded-md focus:!outline-none focus:!ring-1 focus:!ring-[#007980]"  >
                                        {redirectCodes.map(code => (
                                            <option key={code.value} value={code.value}>{code.label}</option>
                                        ))}
                                    </select>
                                )}
                            />
                        </div>

                        {/* <div className="space-y-2">
                            <label className="block text-sm font-medium text-gray-700">Match Type:</label>
                            <Controller name="matchType" control={control} render={({ field }) => (
                                <select value={field.value || 'exact'} onChange={(e) => field.onChange(e.target.value)} onBlur={field.onBlur} className="!w-full !px-3 !py-2 !border !border-gray-300 !rounded-md focus:outline-none focus:ring-1 focus:ring-[#007980]"  >
                                    {matchTypeOptions.map(match => (
                                        <option key={match.value} value={match.value}>{match.label}</option>
                                    ))}
                                </select>
                            )}
                            />
                        </div> */}

                        <div className="!space-y-2">
                            <div className="!flex !items-center !justify-between">
                                <label className="!block !text-sm !font-medium !text-gray-700">inclusion & exclusion rules</label>
                                <div className="!flex !items-center">
                                    <ToggleSwitch checked={showInclusionRules} onChange={() => setShowInclusionRules(!showInclusionRules)} />
                                </div>
                            </div>
                        </div>

                        {showInclusionRules && (
                            <div className="!space-y-4 !p-4 !bg-gray-50 !rounded-lg !shadow-md">
                                <h3 className="!text-sm !font-medium !text-gray-700 !mr-4">If AND is select then all conditions must be true if OR then any one must be true</h3>
                                <Controller name="conditionLogic" control={control}
                                    render={({ field }) => (
                                        <select value={field.value || 'AND'} disabled={!showInclusionRules} onChange={(e) => field.onChange(e.target.value)} onBlur={field.onBlur} className="!w-[20%] !px-3 !py-1 !border !border-gray-300 !rounded-md focus:!outline-none focus:!ring-1 focus:!ring-[#007980]" >
                                            <option>AND</option>
                                            <option>OR</option>
                                        </select>
                                    )}
                                />
                                <div className="!text-sm !text-gray-600 !font-medium">Only redirect if one of the following is true:</div>

                                <div className="!grid !grid-cols-12 !gap-4 !items-center">
                                    <div className="!col-span-8 !flex !items-center !space-x-3 !bg-gray-100 !p-3 !rounded-md !shadow-sm">
                                        <input type="checkbox"
                                            checked={formValues.conditions[0].enabled}
                                            onChange={(e) => {
                                                if (!showInclusionRules) return;
                                                const newConditions = [...formValues.conditions];
                                                newConditions[0].enabled = e.target.checked;
                                                setValue('conditions', newConditions);
                                            }}
                                            disabled={!showInclusionRules}
                                            className="!w-5 !h-5 !accent-teal-600 !rounded" />
                                        <span className="!text-sm !text-gray-700">User who clicks on the link is</span>
                                    </div>
                                    <div className="!col-span-4">
                                        <select value={formValues.conditions[0].value}
                                            onChange={(e) => {
                                                if (!showInclusionRules) return
                                                const newConditions = [...formValues.conditions];
                                                newConditions[0].value = e.target.value;
                                                setValue('conditions', newConditions);

                                            }}
                                            disabled={!showInclusionRules}
                                            className="!w-full !px-3 !py-2 !border !border-gray-300 !rounded-md focus:!outline-none focus:!ring-1 focus:!ring-[#007980]"
                                        >
                                            <option value="logged_in">Logged in</option>
                                            <option value="not_logged_in">Not logged in</option>
                                        </select>
                                    </div>
                                </div>

                                <div className="!grid !grid-cols-12 !gap-4 !items-center">
                                    <div className="!col-span-8 !flex !items-center !space-x-3 !bg-gray-100 !p-3 !rounded-md !shadow-sm">
                                        <input type="checkbox" checked={formValues.conditions[1].enabled}
                                            onChange={(e) => {
                                                if (!showInclusionRules) return;
                                                const newConditions = [...formValues.conditions]; newConditions[1].enabled = e.target.checked; setValue('conditions', newConditions);
                                            }}
                                            disabled={!showInclusionRules}

                                            className="!w-5 !h-5 !accent-teal-600 !rounded"
                                        />
                                        <span className="!text-sm !text-gray-700">User who clicks on the link</span>
                                        <span className="!text-sm !text-gray-700">WordPress user role</span>
                                        <select value={formValues.conditions[1].operator}
                                            onChange={(e) => {
                                                if (!showInclusionRules) return;
                                                const newConditions = [...formValues.conditions];
                                                newConditions[1].operator = e.target.value;
                                                setValue('conditions', newConditions);
                                            }}
                                            disabled={!showInclusionRules}
                                            className="!px-3 !py-1 !border !border-gray-300 !rounded-md focus:!outline-none focus:!ring-1 focus:!ring-[#007980]"
                                        >
                                            <option value="has">Has</option>
                                            <option value="doesnt_have">Does not have</option>
                                        </select>
                                    </div>
                                    <div className="!col-span-4">
                                        {userRoles && (
                                            <MultiSelectCheckbox options={userRoles} selected={formValues.conditions[1].value} onChange={handleRoleSelection} isDisabled={!showInclusionRules} />
                                        )}
                                    </div>
                                </div>

                                <div className="!grid !grid-cols-12 !gap-4 !items-center">
                                    <div className="!col-span-8 !flex !items-center !space-x-3 !bg-gray-100 !p-3 !rounded-md !shadow-sm">
                                        <input type="checkbox" checked={formValues.conditions[2].enabled}
                                            onChange={(e) => {
                                                if (!showInclusionRules) return;

                                                const newConditions = [...formValues.conditions];
                                                newConditions[2].enabled = e.target.checked;
                                                setValue('conditions', newConditions);
                                            }}
                                            disabled={!showInclusionRules}
                                            className="!w-5 !h-5 !accent-teal-600 !rounded"
                                        />
                                        <span className="!text-sm !text-gray-700">User's referrer link</span>
                                    </div>
                                    <div className="!col-span-4">
                                        <input type="text" value={formValues.conditions[2].value} onChange={(e) => {
                                            if (!showInclusionRules) return;

                                            const newConditions = [...formValues.conditions];
                                            newConditions[2].value = e.target.value;
                                            setValue('conditions', newConditions);
                                        }}
                                            disabled={!showInclusionRules}
                                            placeholder="https://www.example.com" className="!w-full !px-3 !py-2 !border !border-gray-300 !rounded-md focus:!outline-none focus:!ring-1 focus:!ring-[#007980]"
                                        />
                                    </div>
                                </div>

                                <div className="!grid !grid-cols-12 !gap-4 !items-center">
                                    <div className="!col-span-8 !flex !items-center !space-x-3 !bg-gray-100 !p-3 !rounded-md !shadow-sm">
                                        <input type="checkbox"
                                            checked={formValues.conditions[3].enabled}
                                            onChange={(e) => {
                                                if (!showInclusionRules) return;

                                                const newConditions = [...formValues.conditions];
                                                newConditions[3].enabled = e.target.checked;
                                                setValue('conditions', newConditions);
                                            }}
                                            disabled={!showInclusionRules}
                                            className="!w-5 !h-5 !accent-teal-600 !rounded"
                                        />
                                        <span className="!text-sm !text-gray-700">User's IP</span>
                                    </div>
                                    <div className="!col-span-4">
                                        <input
                                            type="text"
                                            value={formValues.conditions[3].value.join(',')}
                                            onChange={(e) => {
                                                if (!showInclusionRules) return;

                                                const newConditions = [...formValues.conditions];
                                                newConditions[3].value = e.target.value.split(',').map(ip => ip.trim());
                                                setValue('conditions', newConditions);
                                            }}
                                            disabled={!showInclusionRules}

                                            placeholder="178.243.58.24,184.545.65.23"
                                            className="!w-full !px-3 !py-2 !border !border-gray-300 !rounded-md focus:!outline-none focus:!ring-1 focus:!ring-[#007980]"
                                        />
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );
});
