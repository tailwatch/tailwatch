import React, { useState, useMemo, useRef, useEffect, useCallback } from 'react';
import {
    Palette, Monitor, Smartphone, Eye, Type, MessageSquare, ToggleRight,
    ChevronDown, Settings, AlertCircle, RefreshCcw, Loader2, Info
} from 'lucide-react';
import { useFormData } from '../../../../Components/Context/FormContext';
import { useDesignPreview } from '../../../../Components/Hooks/useDesignPreview/useDesignPreview';

const DEFAULT_PREVIEW_HEIGHT = 480;
const MAX_PREVIEW_HEIGHT = 1200;

/* ------------------------------------------------------------------ */
/* Section categorizer — fully schema-driven, no register names baked in */
/* ------------------------------------------------------------------ */
const SECTIONS = [
    {
        key: 'colors',
        title: 'Colors',
        description: 'Background and accent colors.',
        icon: Palette,
        match: (f) => f.type === 'color' || /color/i.test(f.register || ''),
    },
    {
        key: 'text',
        title: 'Headings & Labels',
        description: 'Short text shown on the page.',
        icon: Type,
        match: (f) =>
            ['input', 'email', 'number', 'password', 'copy'].includes(f.type) ||
            /heading|title|label/i.test(f.register || ''),
    },
    {
        key: 'messages',
        title: 'Messages',
        description: 'Longer descriptive copy.',
        icon: MessageSquare,
        match: (f) => f.type === 'textarea' || /message|description|body/i.test(f.register || ''),
    },
    {
        key: 'display',
        title: 'Display Options',
        description: 'Toggle elements on or off.',
        icon: ToggleRight,
        match: (f) =>
            f.type === 'checkbox' ||
            f.type === 'toggleSwitch' ||
            /show_|enable_|hide_/i.test(f.register || ''),
    },
    {
        key: 'options',
        title: 'Options',
        description: 'Pick between predefined values.',
        icon: Settings,
        match: (f) => f.type === 'select' || f.type === 'radio' || f.type === 'multiple_checkbox',
    },
];

const categorize = (fields) => {
    const buckets = SECTIONS.reduce((acc, s) => ({ ...acc, [s.key]: [] }), { _other: [] });
    fields.forEach((f) => {
        const section = SECTIONS.find((s) => s.match(f));
        if (section) buckets[section.key].push(f);
        else buckets._other.push(f);
    });
    return buckets;
};

/* ------------------------------------------------------------------ */
/* Main component                                                      */
/* ------------------------------------------------------------------ */
const DesignCanvas = ({ id, label, description, subOptions, onChange, renderField, preview }) => {
    const { formData } = useFormData();

    const previewAction = preview?.action || null;
    // Variants and devices both default to a sensible set when the schema
    // doesn't declare them, so the toolbar is consistent out of the box.
    // To hide either toggle, declare an empty array in the schema:
    //   "variants": []   or   "devices": []
    const declaredVariants = Array.isArray(preview?.variants)
        ? preview.variants
        : [
            { key: 'temporary', label: 'Temporary block' },
            { key: 'permanent', label: 'Permanent block' },
        ];
    const declaredDevices = Array.isArray(preview?.devices)
        ? preview.devices
        : ['desktop', 'mobile'];
    const baseHeight = Number(preview?.height) || DEFAULT_PREVIEW_HEIGHT;

    const [variant, setVariant] = useState(declaredVariants[0]?.key ?? null);
    const [device, setDevice] = useState(declaredDevices[0] || 'desktop');
    const [iframeHeight, setIframeHeight] = useState(baseHeight);
    const [openSections, setOpenSections] = useState(
        () => new Set(SECTIONS.map((s) => s.key).concat('_other'))
    );

    const iframeRef = useRef(null);

    /* ----- Build the draft payload ----- */
    // Every sub_option (visible OR hidden) contributes to the draft. The backend
    // may need hidden fields as fixed parameters.
    const allFields = useMemo(
        () => Object.keys(subOptions || {}).map((k) => subOptions[k]).filter(Boolean),
        [subOptions]
    );

    const draft = useMemo(() => {
        const out = {};
        allFields.forEach((f) => {
            const register = f.register;
            if (!register) return;
            const live = formData[f.id];
            if (live !== undefined && !live.options) {
                // Prefer in-progress edits, even when value is an empty string.
                out[register] = {
                    value: live.value ?? '',
                    selected: !!live.selected,
                };
            } else {
                out[register] = {
                    value: f.values?.option?.value ?? '',
                    selected: !!f.values?.option?.selected,
                };
            }
        });
        return out;
    }, [allFields, formData]);

    /* ----- Visible fields (for the Settings UI) -----
       Hidden fields stay in the draft but never render below.            */
    const visibleFields = useMemo(() => allFields.filter((f) => !f.hide), [allFields]);
    const buckets = useMemo(() => categorize(visibleFields), [visibleFields]);
    const totalVisible = visibleFields.length;

    /* ----- Preview hook ----- */
    const { html, loading, error, refetch, enabled } = useDesignPreview({
        action: previewAction,
        draft,
        variant,
        device,
    });

    /* ----- Auto-grow iframe height to fit content (same-origin sandbox) ----- */
    const measureIframe = useCallback(() => {
        const node = iframeRef.current;
        if (!node) return;
        try {
            const doc = node.contentDocument;
            if (!doc) return;
            const measured = Math.max(
                doc.body?.scrollHeight || 0,
                doc.documentElement?.scrollHeight || 0
            );
            if (!measured) return;
            const next = Math.min(Math.max(measured, baseHeight), MAX_PREVIEW_HEIGHT);
            setIframeHeight((prev) => (Math.abs(prev - next) > 2 ? next : prev));
        } catch {
            // Cross-origin or detached doc — fall back to base height.
        }
    }, [baseHeight]);

    const handleIframeLoad = useCallback(() => {
        // Two passes: immediate, then after one tick so late layout (web fonts, images)
        // gets a chance to settle before we lock the height.
        measureIframe();
        const t = setTimeout(measureIframe, 250);
        return () => clearTimeout(t);
    }, [measureIframe]);

    // Re-measure when html, device, or variant changes.
    useEffect(() => {
        if (!html) return;
        const t = setTimeout(measureIframe, 50);
        return () => clearTimeout(t);
    }, [html, device, variant, measureIframe]);

    /* ----- Section toggle ----- */
    const toggleSection = (key) => {
        setOpenSections((prev) => {
            const next = new Set(prev);
            if (next.has(key)) next.delete(key);
            else next.add(key);
            return next;
        });
    };

    /* ----- Fallback summary when no preview action is configured ----- */
    const renderFallbackPreview = () => (
        <div className="!bg-white !border !border-gray-200 !rounded-xl !p-6 !text-center">
            <div className="!w-12 !h-12 !mx-auto !mb-3 !rounded-full !bg-blue-50 !flex !items-center !justify-center">
                <Info className="!w-6 !h-6 !text-blue-500" />
            </div>
            <h4 className="!text-sm !font-semibold !text-gray-800 !mb-1">Live preview not configured</h4>
            <p className="!text-xs !text-gray-500 !max-w-md !mx-auto !mb-4">
                This feature doesn't have a preview endpoint wired up yet. You can still edit and save the settings below.
            </p>

            {visibleFields.length > 0 && (
                <div className="!mt-4 !text-left !inline-block !min-w-[60%] !max-w-full !bg-gray-50 !border !border-gray-200 !rounded-lg !overflow-hidden">
                    <div className="!px-3 !py-2 !text-[10px] !font-bold !text-gray-500 !uppercase !tracking-wider !bg-gray-100 !border-b !border-gray-200">
                        Current values
                    </div>
                    <div className="!divide-y !divide-gray-200">
                        {visibleFields.slice(0, 8).map((f) => {
                            const v = draft[f.register];
                            const display =
                                v?.value === '' || v?.value == null
                                    ? (v?.selected ? 'Enabled' : 'Disabled')
                                    : String(v.value);
                            return (
                                <div key={f.id} className="!flex !items-start !justify-between !gap-4 !px-3 !py-2 !text-xs">
                                    <span className="!text-gray-600 !font-medium !truncate">{f.label}</span>
                                    <span className="!text-gray-900 !font-mono !truncate !max-w-[60%]" title={display}>
                                        {display}
                                    </span>
                                </div>
                            );
                        })}
                        {visibleFields.length > 8 && (
                            <div className="!px-3 !py-2 !text-[11px] !text-gray-500 !italic">
                                +{visibleFields.length - 8} more setting{visibleFields.length - 8 === 1 ? '' : 's'}
                            </div>
                        )}
                    </div>
                </div>
            )}
        </div>
    );

    /* ----- Render ----- */
    return (
        <div className="!relative !bg-white !rounded-2xl !overflow-hidden !border !border-gray-200 !shadow-sm">
            {/* Header */}
            <div className="!flex !items-center !justify-between !px-5 !py-4 !border-b !border-gray-200 !bg-gradient-to-r !from-white !to-gray-50 !flex-wrap !gap-3">
                <div className="!flex !items-center !gap-3 !min-w-0">
                    <div className="!w-10 !h-10 !rounded-xl !bg-gradient-to-br !from-[#007980] !to-[#85cbcf] !flex !items-center !justify-center !shadow-md !flex-shrink-0">
                        <Palette className="!w-5 !h-5 !text-white" />
                    </div>
                    <div className="!min-w-0">
                        <h3 className="!text-base !font-semibold !text-gray-900 !truncate">{label || 'Design'}</h3>
                        <p className="!text-xs !text-gray-500 !truncate">
                            {description || `Customize how this page is rendered · ${totalVisible} setting${totalVisible === 1 ? '' : 's'}`}
                        </p>
                    </div>
                </div>
            </div>

            {/* Preview area */}
            <div className="!px-5 !pt-5 !pb-4 !bg-gray-50">
                <div className="!flex !items-center !justify-between !mb-3 !flex-wrap !gap-2">
                    <div className="!flex !items-center !gap-2 !min-w-0">
                        <Eye className="!w-4 !h-4 !text-[#007980]" />
                        <h4 className="!text-sm !font-semibold !text-gray-800">Live Preview</h4>
                        {enabled && (
                            <span className="!text-xs !text-gray-500 !hidden sm:!inline">— updates as you edit below</span>
                        )}
                    </div>

                    {enabled && (
                        <div className="!flex !items-center !gap-2 !flex-wrap">
                            {declaredVariants.length > 1 && (
                                <div className="!inline-flex !items-center !p-0.5 !bg-white !rounded-lg !border !border-gray-200 !shadow-sm">
                                    {declaredVariants.map((v) => (
                                        <button
                                            key={v.key}
                                            type="button"
                                            onClick={() => setVariant(v.key)}
                                            className={`!px-3 !py-1.5 !text-xs !font-semibold !rounded-md !border-0 !cursor-pointer !transition-all
                                                ${variant === v.key
                                                    ? '!bg-[#007980] !text-white !shadow-sm'
                                                    : '!bg-transparent !text-gray-600 hover:!text-gray-900'
                                                }`}
                                        >
                                            {v.label || v.key}
                                        </button>
                                    ))}
                                </div>
                            )}

                            {declaredDevices.length > 1 && (
                                <div className="!inline-flex !items-center !p-0.5 !bg-white !rounded-lg !border !border-gray-200 !shadow-sm">
                                    {declaredDevices.includes('desktop') && (
                                        <button
                                            type="button"
                                            onClick={() => setDevice('desktop')}
                                            title="Desktop view"
                                            className={`!inline-flex !items-center !justify-center !w-8 !h-7 !rounded-md !border-0 !cursor-pointer !transition-all
                                                ${device === 'desktop' ? '!bg-[#007980] !text-white !shadow-sm' : '!bg-transparent !text-gray-500'}`}
                                        >
                                            <Monitor className="!w-3.5 !h-3.5" />
                                        </button>
                                    )}
                                    {declaredDevices.includes('mobile') && (
                                        <button
                                            type="button"
                                            onClick={() => setDevice('mobile')}
                                            title="Mobile view"
                                            className={`!inline-flex !items-center !justify-center !w-8 !h-7 !rounded-md !border-0 !cursor-pointer !transition-all
                                                ${device === 'mobile' ? '!bg-[#007980] !text-white !shadow-sm' : '!bg-transparent !text-gray-500'}`}
                                        >
                                            <Smartphone className="!w-3.5 !h-3.5" />
                                        </button>
                                    )}
                                </div>
                            )}

                            <button
                                type="button"
                                onClick={refetch}
                                disabled={loading}
                                title="Reload preview"
                                className="!w-8 !h-8 !rounded-md !flex !items-center !justify-center !text-gray-500 hover:!bg-gray-100 hover:!text-gray-800 !transition-colors !border !border-gray-200 !bg-white !shadow-sm !cursor-pointer disabled:!opacity-50 disabled:!cursor-not-allowed"
                            >
                                <RefreshCcw className={`!w-3.5 !h-3.5 ${loading ? '!animate-spin' : ''}`} />
                            </button>
                        </div>
                    )}
                </div>

                {/* Error banner — preserves last good preview while showing the issue */}
                {enabled && error && (
                    <div className="!mb-3 !px-3 !py-2 !rounded-lg !bg-red-50 !border !border-red-200 !flex !items-start !gap-2">
                        <AlertCircle className="!w-4 !h-4 !text-red-500 !flex-shrink-0 !mt-0.5" />
                        <div className="!flex-1 !min-w-0">
                            <p className="!text-xs !font-semibold !text-red-800">Couldn't refresh preview</p>
                            <p className="!text-xs !text-red-700 !mt-0.5 !truncate">{error}</p>
                        </div>
                        <button
                            type="button"
                            onClick={refetch}
                            className="!text-xs !font-semibold !text-red-700 hover:!text-red-900 !bg-transparent !border-0 !cursor-pointer !whitespace-nowrap"
                        >
                            Retry
                        </button>
                    </div>
                )}

                {/* The preview surface */}
                {enabled ? (
                    <div className="!bg-white !border !border-gray-200 !rounded-xl !overflow-hidden !shadow-md">
                        <div className="!flex !items-center !gap-1.5 !px-3 !py-2 !bg-gray-100 !border-b !border-gray-200">
                            <span className="!w-2.5 !h-2.5 !rounded-full !bg-red-400" />
                            <span className="!w-2.5 !h-2.5 !rounded-full !bg-yellow-400" />
                            <span className="!w-2.5 !h-2.5 !rounded-full !bg-green-400" />
                            <span className="!ml-auto !text-[10px] !text-gray-400 !font-mono !truncate !max-w-[55%]">
                                {previewAction}
                            </span>
                        </div>

                        <div
                            className="!relative !bg-gray-50 !flex !justify-center !p-3 sm:!p-5 !transition-all !duration-300"
                            style={{ minHeight: baseHeight }}
                        >
                            {/* iframe wrapper — clamps width on mobile */}
                            <div
                                className="!relative !w-full !bg-white !rounded-lg !shadow-inner !border !border-gray-200 !overflow-hidden !transition-all !duration-300"
                                style={{ maxWidth: device === 'mobile' ? '420px' : '100%' }}
                            >
                                {/* Empty placeholder before first paint */}
                                {!html && !loading && !error && (
                                    <div className="!flex !items-center !justify-center !w-full" style={{ height: baseHeight }}>
                                        <p className="!text-sm !text-gray-400">Preview unavailable</p>
                                    </div>
                                )}

                                {html && (
                                    <iframe
                                        ref={iframeRef}
                                        title="design-preview"
                                        srcDoc={html}
                                        sandbox="allow-same-origin"
                                        onLoad={handleIframeLoad}
                                        className="!w-full !border-0 !block !bg-white"
                                        style={{ height: iframeHeight }}
                                    />
                                )}

                                {/* Loading overlay — sits on top of the last good iframe */}
                                {loading && (
                                    <div className="!absolute !inset-0 !flex !flex-col !items-center !justify-center !bg-white/70 !backdrop-blur-[1px] !z-10 !transition-opacity">
                                        <Loader2 className="!w-7 !h-7 !text-[#007980] !animate-spin !mb-2" />
                                        <p className="!text-xs !text-gray-600 !font-medium">{html ? 'Updating preview…' : 'Loading preview…'}</p>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                ) : (
                    renderFallbackPreview()
                )}
            </div>

            {/* Settings sections */}
            <div className="!p-5 !pt-3 !space-y-3 !bg-white">
                <div className="!flex !items-center !gap-2 !pt-2 !pb-1">
                    <Settings className="!w-4 !h-4 !text-gray-700" />
                    <h4 className="!text-sm !font-bold !text-gray-900 !uppercase !tracking-wider">Settings</h4>
                    <div className="!flex-1 !h-px !bg-gray-200" />
                </div>

                {SECTIONS.map((section) => {
                    const fields = buckets[section.key];
                    if (!fields || fields.length === 0) return null;
                    const isOpen = openSections.has(section.key);
                    const Icon = section.icon;
                    return (
                        <div key={section.key} className="!bg-gray-50 !border !border-gray-200 !rounded-xl !overflow-hidden">
                            <button
                                type="button"
                                onClick={() => toggleSection(section.key)}
                                className="!w-full !flex !items-center !justify-between !px-4 !py-3 !bg-white hover:!bg-gray-50 !cursor-pointer !border-0 !transition-colors !text-left"
                            >
                                <div className="!flex !items-center !gap-3 !min-w-0">
                                    <div className="!w-8 !h-8 !rounded-lg !bg-[#007980]/10 !flex !items-center !justify-center !flex-shrink-0">
                                        <Icon className="!w-4 !h-4 !text-[#007980]" />
                                    </div>
                                    <div className="!min-w-0">
                                        <div className="!flex !items-center !gap-2">
                                            <span className="!text-sm !font-semibold !text-gray-900">{section.title}</span>
                                            <span className="!text-[10px] !font-bold !text-gray-500 !bg-gray-100 !rounded-full !px-2 !py-0.5">
                                                {fields.length}
                                            </span>
                                        </div>
                                        <p className="!text-xs !text-gray-500 !truncate">{section.description}</p>
                                    </div>
                                </div>
                                <ChevronDown className={`!w-4 !h-4 !text-gray-400 !flex-shrink-0 !transition-transform ${isOpen ? '!rotate-180' : ''}`} />
                            </button>

                            {isOpen && (
                                <div className="!px-4 !py-4 !bg-white !border-t !border-gray-100 !space-y-1">
                                    {fields.map((f) => (
                                        <div key={f.id} className="design-canvas-field">
                                            {renderField?.(f)}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>
                    );
                })}

                {buckets._other.length > 0 && (
                    <div className="!bg-gray-50 !border !border-gray-200 !rounded-xl !overflow-hidden">
                        <button
                            type="button"
                            onClick={() => toggleSection('_other')}
                            className="!w-full !flex !items-center !justify-between !px-4 !py-3 !bg-white hover:!bg-gray-50 !cursor-pointer !border-0 !transition-colors !text-left"
                        >
                            <div className="!flex !items-center !gap-3 !min-w-0">
                                <div className="!w-8 !h-8 !rounded-lg !bg-[#007980]/10 !flex !items-center !justify-center !flex-shrink-0">
                                    <Settings className="!w-4 !h-4 !text-[#007980]" />
                                </div>
                                <div className="!min-w-0">
                                    <div className="!flex !items-center !gap-2">
                                        <span className="!text-sm !font-semibold !text-gray-900">More options</span>
                                        <span className="!text-[10px] !font-bold !text-gray-500 !bg-gray-100 !rounded-full !px-2 !py-0.5">
                                            {buckets._other.length}
                                        </span>
                                    </div>
                                    <p className="!text-xs !text-gray-500 !truncate">Additional configuration.</p>
                                </div>
                            </div>
                            <ChevronDown className={`!w-4 !h-4 !text-gray-400 !flex-shrink-0 !transition-transform ${openSections.has('_other') ? '!rotate-180' : ''}`} />
                        </button>

                        {openSections.has('_other') && (
                            <div className="!px-4 !py-4 !bg-white !border-t !border-gray-100 !space-y-1">
                                {buckets._other.map((f) => (
                                    <div key={f.id} className="design-canvas-field">
                                        {renderField?.(f)}
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
};

export default DesignCanvas;
