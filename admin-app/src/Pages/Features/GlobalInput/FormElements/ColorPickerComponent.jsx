import React, { useState, useRef, useEffect, useCallback } from 'react';
import { createPortal } from 'react-dom';
import { FaLock, FaInfoCircle } from 'react-icons/fa';
import { Crown, ChevronDown, Check, Pipette } from 'lucide-react';

const POPOVER_WIDTH = 300;
const POPOVER_GAP = 8;
const POPOVER_ESTIMATED_HEIGHT = 360;
const VIEWPORT_MARGIN = 8;

const PRESET_COLORS = [
    '#000000', '#374151', '#6B7280', '#9CA3AF', '#D1D5DB', '#F3F4F6', '#FFFFFF',
    '#EF4444', '#F97316', '#F59E0B', '#EAB308', '#84CC16', '#22C55E', '#10B981',
    '#14B8A6', '#06B6D4', '#0EA5E9', '#3B82F6', '#6366F1', '#8B5CF6', '#A855F7',
    '#D946EF', '#EC4899', '#F43F5E', '#667EEA', '#764BA2', '#007980', '#85CBCF',
];

const isValidHex = (val) => /^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/.test(val);

const expandShortHex = (val) => {
    if (!val) return val;
    const m = val.match(/^#([0-9A-Fa-f]{3})$/);
    if (!m) return val.toUpperCase();
    const [r, g, b] = m[1].split('');
    return `#${r}${r}${g}${g}${b}${b}`.toUpperCase();
};

const CHECKERED_BG = {
    backgroundImage:
        'linear-gradient(45deg, #e5e7eb 25%, transparent 25%), linear-gradient(-45deg, #e5e7eb 25%, transparent 25%), linear-gradient(45deg, transparent 75%, #e5e7eb 75%), linear-gradient(-45deg, transparent 75%, #e5e7eb 75%)',
    backgroundSize: '8px 8px',
    backgroundPosition: '0 0, 0 4px, 4px -4px, -4px 0px',
};

const ColorPickerComponent = ({ id, label, description, planRequired, placeholder, selectedValue, disabled, display, handleInputChange }) => {
    const currentValue = (selectedValue && isValidHex(selectedValue))
        ? expandShortHex(selectedValue)
        : (placeholder && isValidHex(placeholder) ? expandShortHex(placeholder) : '#000000');

    const [open, setOpen] = useState(false);
    const [hexInput, setHexInput] = useState(currentValue);
    const [pos, setPos] = useState({ top: 0, left: 0 });
    const popoverRef = useRef(null);
    const triggerRef = useRef(null);
    const nativeInputRef = useRef(null);

    useEffect(() => {
        setHexInput(currentValue);
    }, [currentValue]);

    // Compute popover viewport coords; flip above the trigger when there's
    // not enough room below, and clamp horizontally to the viewport.
    const updatePosition = useCallback(() => {
        if (!triggerRef.current || typeof window === 'undefined') return;
        const rect = triggerRef.current.getBoundingClientRect();
        const vw = window.innerWidth || document.documentElement.clientWidth;
        const vh = window.innerHeight || document.documentElement.clientHeight;

        const spaceBelow = vh - rect.bottom;
        const spaceAbove = rect.top;
        const openUp = spaceBelow < POPOVER_ESTIMATED_HEIGHT && spaceAbove > spaceBelow;

        const top = openUp
            ? Math.max(VIEWPORT_MARGIN, rect.top - POPOVER_GAP - POPOVER_ESTIMATED_HEIGHT)
            : Math.min(vh - VIEWPORT_MARGIN - POPOVER_ESTIMATED_HEIGHT, rect.bottom + POPOVER_GAP);

        const rawLeft = rect.left;
        const left = Math.max(
            VIEWPORT_MARGIN,
            Math.min(rawLeft, vw - POPOVER_WIDTH - VIEWPORT_MARGIN)
        );

        setPos({ top, left });
    }, []);

    // Compute position the moment we open + on every scroll/resize while open.
    useEffect(() => {
        if (!open) return;
        updatePosition();
        const onScroll = () => updatePosition();
        const onResize = () => updatePosition();
        // Capture so nested scrollable ancestors fire too (accordions, modals, sidebars).
        window.addEventListener('scroll', onScroll, true);
        window.addEventListener('resize', onResize);
        return () => {
            window.removeEventListener('scroll', onScroll, true);
            window.removeEventListener('resize', onResize);
        };
    }, [open, updatePosition]);

    // Close on outside click + ESC. popoverRef points to the portaled node,
    // so contains() still works on the real DOM tree.
    useEffect(() => {
        if (!open) return;
        const onClick = (e) => {
            if (
                popoverRef.current && !popoverRef.current.contains(e.target) &&
                triggerRef.current && !triggerRef.current.contains(e.target)
            ) {
                setOpen(false);
            }
        };
        const onKey = (e) => { if (e.key === 'Escape') setOpen(false); };
        document.addEventListener('mousedown', onClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onClick);
            document.removeEventListener('keydown', onKey);
        };
    }, [open]);

    const commit = (val) => {
        if (disabled) return;
        const normalized = expandShortHex(val).toUpperCase();
        handleInputChange({ target: { value: normalized } });
    };

    const handleHexInputChange = (e) => {
        let v = e.target.value;
        if (v && !v.startsWith('#')) v = '#' + v;
        v = v.replace(/[^#0-9A-Fa-f]/g, '').slice(0, 7);
        setHexInput(v.toUpperCase());
    };

    const handleHexBlur = () => {
        if (isValidHex(hexInput)) {
            commit(hexInput);
        } else {
            setHexInput(currentValue);
        }
    };

    const handleHexKeyDown = (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            handleHexBlur();
        }
    };

    const wrapperClass = display === 'flex' ? '!gap-2 !pt-2 !flex !items-center' : '!p-4 !rounded-lg';

    return (
        <div className={wrapperClass}>
            <div className="!w-full">
                {/* Label row */}
                <div className="!flex !items-center !mb-1">
                    <label htmlFor={id} className={`form-label !text-black !font-semibold ${display === 'flex' ? '!text-sm' : '!text-lg'}`}>
                        {label}
                    </label>

                    {disabled && (
                        <span className="!inline-flex !items-center !gap-1.5 !ml-2 !px-2.5 !py-0.5 !rounded-full !bg-amber-50 !border !border-amber-200 !text-amber-700 !text-xs !font-semibold !shadow-sm">
                            <Crown className="!w-3.5 !h-3.5 !text-amber-500" />
                            <span>{planRequired || 'Pro'}</span>
                        </span>
                    )}

                    {display === 'flex' && description && (
                        <div className="!ml-2 !relative !group">
                            <span className="!absolute !bottom-full !mb-2 !left-1/2 !transform !-translate-x-1/2 !w-44 !bg-gray-800 !text-white !text-xs !rounded !py-1 !px-2 !opacity-0 group-hover:!opacity-100 !transition-opacity !duration-200 !pointer-events-none !z-10">
                                {description}
                            </span>
                            <FaInfoCircle className="!text-gray-500 !cursor-pointer" />
                        </div>
                    )}
                </div>

                {/* Trigger — styled like the other input fields (border, rounded, same padding/width) */}
                <div className="!relative !inline-block">
                    <button
                        ref={triggerRef}
                        type="button"
                        disabled={disabled}
                        onClick={(e) => { e.stopPropagation(); if (!disabled) setOpen((o) => !o); }}
                        className={`!group !flex !items-center !gap-2 !border !rounded !text-sm !text-black !px-2 !py-1 !w-full sm:!w-64 !transition-colors ${
                            disabled
                                ? '!bg-gray-100 !border-gray-300 !cursor-not-allowed !opacity-70'
                                : '!bg-white !border-gray-300 hover:!border-[#007980] focus:!outline-none focus:!border-[#007980]'
                        }`}
                    >
                        {/* Swatch */}
                        <span className="!relative !h-6 !w-6 !rounded !overflow-hidden !ring-1 !ring-black/10 !flex-shrink-0" style={CHECKERED_BG}>
                            <span className="!absolute !inset-0" style={{ backgroundColor: currentValue }} />
                        </span>

                        {/* Hex text */}
                        <span className="!flex-1 !text-left !font-mono !uppercase !tracking-wide !text-sm !text-gray-800">
                            {currentValue}
                        </span>

                        {disabled ? (
                            <FaLock className="!w-3 !h-3 !text-amber-500" />
                        ) : (
                            <ChevronDown className={`!w-4 !h-4 !text-gray-400 !transition-transform ${open ? '!rotate-180' : ''}`} />
                        )}
                    </button>

                    {/* Popover — portaled to document.body so it escapes any
                        parent overflow / stacking context (accordions, modals,
                        scroll containers, etc.). */}
                    {open && !disabled && typeof document !== 'undefined' && createPortal(
                        <div
                            ref={popoverRef}
                            style={{
                                position: 'fixed',
                                top: pos.top,
                                left: pos.left,
                                width: POPOVER_WIDTH,
                                zIndex: 2147483000,
                            }}
                            className="!rounded-xl !border !border-gray-200 !bg-white !shadow-2xl !overflow-hidden"
                            onClick={(e) => e.stopPropagation()}
                        >
                            {/* Header strip — click anywhere to open native picker */}
                            <div className="!relative !h-24 !w-full">
                                <div className="!absolute !inset-0" style={{ backgroundColor: currentValue }} />
                                <input
                                    ref={nativeInputRef}
                                    type="color"
                                    value={currentValue}
                                    onChange={(e) => {
                                        const v = e.target.value.toUpperCase();
                                        setHexInput(v);
                                        commit(v);
                                    }}
                                    className="!absolute !inset-0 !w-full !h-full !opacity-0 !cursor-pointer"
                                    aria-label="Pick a color"
                                />
                                <div className="!pointer-events-none !absolute !bottom-2 !right-2 !inline-flex !items-center !gap-1 !rounded-md !bg-black/40 !px-2 !py-1 !text-[10px] !font-semibold !uppercase !tracking-wider !text-white !backdrop-blur-sm">
                                    <Pipette className="!w-3 !h-3" />
                                    Pick
                                </div>
                            </div>

                            <div className="!p-3 !space-y-3">
                                {/* Hex input row */}
                                <div>
                                    <label className="!block !text-[10px] !font-bold !text-gray-500 !uppercase !tracking-wider !mb-1.5">Hex</label>
                                    <div className="!flex !items-center !gap-2">
                                        <span className="!h-7 !w-7 !rounded !border !border-gray-200 !flex-shrink-0" style={{ backgroundColor: currentValue }} />
                                        <input
                                            type="text"
                                            value={hexInput}
                                            onChange={handleHexInputChange}
                                            onBlur={handleHexBlur}
                                            onKeyDown={handleHexKeyDown}
                                            placeholder={placeholder || '#000000'}
                                            maxLength={7}
                                            className="!flex-1 !px-2.5 !py-1.5 !border !border-gray-300 !rounded !text-sm !font-mono !uppercase !text-gray-800 focus:!outline-none focus:!ring-2 focus:!ring-[#007980]/40 focus:!border-[#007980] !bg-white"
                                        />
                                    </div>
                                </div>

                                {/* Preset palette */}
                                <div>
                                    <label className="!block !text-[10px] !font-bold !text-gray-500 !uppercase !tracking-wider !mb-1.5">Presets</label>
                                    <div className="!grid !grid-cols-7 !gap-1.5">
                                        {PRESET_COLORS.map((preset) => {
                                            const isSelected = preset.toUpperCase() === currentValue.toUpperCase();
                                            return (
                                                <button
                                                    key={preset}
                                                    type="button"
                                                    onClick={() => { setHexInput(preset); commit(preset); }}
                                                    className={`!relative !h-7 !w-7 !rounded !transition-transform hover:!scale-110 hover:!shadow-md focus:!outline-none ${
                                                        isSelected ? '!ring-2 !ring-[#007980] !ring-offset-1' : '!ring-1 !ring-black/10'
                                                    }`}
                                                    style={{ backgroundColor: preset }}
                                                    title={preset}
                                                    aria-label={`Choose ${preset}`}
                                                >
                                                    {isSelected && (
                                                        <Check className="!absolute !inset-0 !m-auto !w-3.5 !h-3.5 !text-white" style={{ filter: 'drop-shadow(0 1px 1px rgba(0,0,0,0.5))' }} />
                                                    )}
                                                </button>
                                            );
                                        })}
                                    </div>
                                </div>
                            </div>
                        </div>,
                        document.body
                    )}
                </div>

                {display !== 'flex' && description && (
                    <p className="!text-black !text-sm !mt-2">{description}</p>
                )}
            </div>
        </div>
    );
};

export default ColorPickerComponent;
