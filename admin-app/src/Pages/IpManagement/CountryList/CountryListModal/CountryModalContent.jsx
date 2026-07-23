import React, { memo, useEffect, useMemo, useRef, useState } from 'react';
import { Search, Globe, Clock, Check, Users, Eye } from 'lucide-react';
import { useVirtualizer } from '@tanstack/react-virtual';
import RadioButtons from "../../../../Components/RadioButtons/RadioButtons";
import { GetPostTypes } from "../../../../Components/GetPostTypes/GetPostTypes";
import { CountryFlag } from "../../../../Components/CountryFlag/CountryFlag";

const REGIONS = ['Asia', 'Africa', 'Europe', 'Oceania', 'Antarctic', 'Americas'];

// Pixel height per virtualized row slot in the country grid.
// The tile is ~60px tall; the remaining ~26px is the vertical gap so rows
// visibly breathe (tiles aligned to the top of their slot).
const ROW_HEIGHT = 86;
const GRID_VIEWPORT_PX = 384; // ~max-h-96 — fixed so the virtualizer has a known viewport

/**
 * Memoized tile — re-renders only when its own props change.
 * Without this, every selection click re-renders all visible tiles instead of
 * just the toggled one.
 */
const CountryTile = memo(function CountryTile({ country, isSelected, onToggle }) {
    return (
        <button
            type="button"
            onClick={onToggle}
            className={`!relative !p-2 sm:!p-3 !rounded-lg !border !text-left !transition-colors !cursor-pointer !bg-white !w-full
                ${isSelected
                    ? '!border-[#007980] !bg-[#007980]/5'
                    : '!border-gray-200 hover:!border-[#007980]'
                }`}
        >
            {isSelected && <Check className="!absolute !top-1.5 !right-1.5 !w-3.5 !h-3.5 !text-[#007980]" />}
            <div className={`!font-semibold !text-xs !leading-tight !pr-4 !flex !items-center !gap-1.5 ${isSelected ? '!text-[#007980]' : '!text-gray-800'}`}>
                <CountryFlag countryCode={country.code} size="sm" />
                <span className="!truncate">{country.name}</span>
            </div>
            <div className="!text-[10px] !text-gray-500 !mt-0.5">{country.region}</div>
        </button>
    );
});

/** Pick a column count for the country grid based on the viewport width. */
const getColCount = () => {
    if (typeof window === 'undefined') return 6;
    const w = window.innerWidth;
    if (w < 640) return 2;
    if (w < 768) return 3;
    if (w < 1024) return 4;
    if (w < 1280) return 5;
    return 6;
};

export const CountryModalContent = ({ stats, selectedCountries, selectByRegion, isRegionSelected, clearSelection, processedCountries, toggleCountrySelection, searchTerm, setSearchTerm, filteredCountries, blockType, setBlockType, isEditing, blockDuration,
    setBlockDuration, durationType, setDurationType, blockScopeOptions, blockScope, setBlockScope, reason, setReason, setExemptionType, exemptionType, isWhiteList, onBlockPageChange, onPreviewClick, targetMode, setTargetMode, initialPostId = '', initialPostType = '', initialCustomUrl = '' }) => {

    // Selected items float to the top — plain set lookup keeps the sort O(n).
    const selectedSet = useMemo(
        () => (selectedCountries instanceof Set ? selectedCountries : new Set(selectedCountries || [])),
        [selectedCountries]
    );

    const orderedCountries = useMemo(() => {
        if (selectedSet.size === 0) return filteredCountries;
        const sel = [];
        const rest = [];
        for (const c of filteredCountries) {
            if (selectedSet.has(c.code)) sel.push(c);
            else rest.push(c);
        }
        return sel.concat(rest);
    }, [filteredCountries, selectedSet]);

    // Responsive column count. Updated on window resize so the grid reflows
    // correctly across breakpoints and the virtualizer recomputes row counts.
    const [cols, setCols] = useState(getColCount);
    useEffect(() => {
        const onResize = () => setCols(getColCount());
        window.addEventListener('resize', onResize);
        return () => window.removeEventListener('resize', onResize);
    }, []);

    // Group flat country list into rows of `cols` items so we can virtualize by row.
    const rows = useMemo(() => {
        const out = [];
        for (let i = 0; i < orderedCountries.length; i += cols) {
            out.push(orderedCountries.slice(i, i + cols));
        }
        return out;
    }, [orderedCountries, cols]);

    const scrollParentRef = useRef(null);
    const rowVirtualizer = useVirtualizer({
        count: rows.length,
        getScrollElement: () => scrollParentRef.current,
        estimateSize: () => ROW_HEIGHT,
        overscan: 4,
    });

    return (
        <div className="!space-y-4 sm:!space-y-6">

            {/* Country selection grid — now also contains region quick-select + search */}
            {!isEditing && (
                <div className="!bg-gray-50 !rounded-xl !p-3 sm:!p-4 !border !border-gray-200">
                    {/* Header */}
                    <div className="!flex !items-center !justify-between !flex-wrap !gap-2 !mb-3">
                        <h3 className="!text-base sm:!text-lg !font-bold !flex !items-center !gap-2 !text-gray-800">
                            <Globe className="!w-5 !h-5 !text-[#007980]" />
                            Select Countries to Block
                            <span className="!text-xs !font-semibold !text-gray-500 !bg-white !border !border-gray-200 !rounded-full !px-2 !py-0.5">
                                {selectedCountries.length} selected
                            </span>
                        </h3>
                        {selectedCountries.length > 0 && (
                            <button
                                type="button"
                                onClick={clearSelection}
                                className="!text-xs !font-semibold !text-gray-600 hover:!text-red-600 !bg-white !border !border-gray-200 hover:!border-red-200 !px-3 !py-1.5 !rounded-md !transition-colors !cursor-pointer"
                            >
                                Clear selection
                            </button>
                        )}
                    </div>

                    {/* Region quick-select — minimal pills, brand fill when active */}
                    <div className="!flex !flex-wrap !gap-1.5 !mb-3">
                        {REGIONS.map((region) => {
                            const selected = isRegionSelected(region);
                            return (
                                <button
                                    key={region}
                                    type="button"
                                    disabled={isEditing}
                                    onClick={() => selectByRegion(region)}
                                    className={`!inline-flex !items-center !gap-1 !px-3 !py-1.5 !rounded-md !text-xs sm:!text-sm !font-semibold !border !transition-colors !cursor-pointer
                                        ${selected
                                            ? '!bg-[#007980] !text-white !border-[#007980]'
                                            : '!bg-white !text-gray-700 !border-gray-200 hover:!border-[#007980] hover:!text-[#007980]'
                                        }
                                        ${isEditing ? '!opacity-50 !cursor-not-allowed' : ''}`}
                                >
                                    {selected && <Check className="!w-3 !h-3" />}
                                    {region}
                                </button>
                            );
                        })}
                    </div>

                    {/* Search */}
                    <div className="!relative !w-full !mb-3">
                        <Search className="!absolute !left-3 !top-1/2 !-translate-y-1/2 !text-gray-400 !w-4 !h-4 !pointer-events-none" />
                        <input
                            type="text"
                            placeholder="Search by country name, code or region..."
                            value={searchTerm}
                            onChange={(e) => setSearchTerm(e.target.value)}
                            className="!w-full !pl-9 !pr-16 !py-2 !text-sm !border !border-gray-300 !rounded-md !bg-white !text-gray-800 !outline-none focus:!border-[#007980] !transition-colors"
                        />
                        {searchTerm && (
                            <span className="!absolute !right-3 !top-1/2 !-translate-y-1/2 !text-xs !text-gray-400">
                                {filteredCountries.length} found
                            </span>
                        )}
                    </div>

                    {/* Country grid — virtualized for performance. Only the
                        visible rows (~5–6) are mounted at any time, regardless
                        of how many countries match. */}
                    {orderedCountries.length === 0 ? (
                        <div className="!py-8 !text-center !text-sm !text-gray-500 !border !border-gray-200 !rounded-lg !bg-white">
                            No countries match this search.
                        </div>
                    ) : (
                        <div
                            ref={scrollParentRef}
                            className="!overflow-y-auto custom-scroll-bar !p-1 !border !border-gray-100 !rounded-lg !bg-white"
                            style={{ height: GRID_VIEWPORT_PX }}
                        >
                            <div
                                style={{
                                    height: `${rowVirtualizer.getTotalSize()}px`,
                                    width: '100%',
                                    position: 'relative',
                                }}
                            >
                                {rowVirtualizer.getVirtualItems().map((virtualRow) => {
                                    const rowCountries = rows[virtualRow.index] || [];
                                    return (
                                        <div
                                            key={virtualRow.key}
                                            style={{
                                                position: 'absolute',
                                                top: 0,
                                                left: 0,
                                                right: 0,
                                                transform: `translateY(${virtualRow.start}px)`,
                                                height: `${virtualRow.size}px`,
                                                display: 'grid',
                                                gridTemplateColumns: `repeat(${cols}, minmax(0, 1fr))`,
                                                columnGap: '0.5rem',
                                                alignItems: 'start',
                                            }}
                                        >
                                            {rowCountries.map((country) => (
                                                <CountryTile
                                                    key={country.code}
                                                    country={country}
                                                    isSelected={selectedSet.has(country.code)}
                                                    onToggle={() => toggleCountrySelection(country.code)}
                                                />
                                            ))}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>
            )}

            {/* Configuration */}
            {!isWhiteList ? (
                <>
                    <div className="!grid !grid-cols-1 lg:!grid-cols-2 !gap-4">
                        {/* Block Settings */}
                        <div className="!bg-white !border-2 !border-gray-200 !rounded-xl !p-4 sm:!p-5 !shadow-md">
                            <h4 className="!font-bold !text-base !mb-4 !flex !items-center !gap-2 !text-gray-800">
                                <Clock className="!w-5 !h-5 !text-[#007980]" />
                                Block Configuration
                            </h4>
                            <div className="!space-y-4">
                                <div>
                                    <label className="!block !text-sm !font-semibold !text-gray-700 !mb-2">Block Type</label>
                                    <select value={blockType} onChange={(e) => setBlockType(e.target.value)} className="!w-full !p-2.5 !text-sm !border !border-gray-300 !rounded-lg !bg-white !text-gray-700 focus:!outline-none focus:!ring-1 focus:!ring-[#007980] focus:!border-[#007980]">
                                        {isEditing && <option value="unblocked">Unblock</option>}
                                        <option value="temporary">Temporary Block</option>
                                        <option value="permanent">Permanent Block</option>
                                    </select>
                                </div>
                                {blockType === 'temporary' && (
                                    <div>
                                        <label className="!block !text-sm !font-semibold !text-gray-700 !mb-2">Duration</label>
                                        <div className="!flex !border !border-gray-300 !rounded-lg !overflow-hidden focus-within:!ring-1 focus-within:!ring-[#007980]">
                                            <input type="number" value={blockDuration} onChange={(e) => setBlockDuration(e.target.value)} className="!flex-1 !px-3 !py-2.5 !border-0 focus:!outline-none !text-sm !bg-white" min="1" />
                                            <select value={durationType} onChange={(e) => setDurationType(e.target.value)} className="!px-3 !py-2.5 !text-sm !border-0 !border-l !border-gray-300 !bg-gray-50 focus:!outline-none !cursor-pointer">
                                                <option value="minutes">Minutes</option>
                                                <option value="hours">Hours</option>
                                                <option value="days">Days</option>
                                                <option value="weeks">Weeks</option>
                                            </select>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>

                        {/* Block Scope */}
                        <div className="!bg-white !border-2 !border-gray-200 !rounded-xl !p-4 sm:!p-5 !shadow-md">
                            <h4 className="!font-bold !text-base !mb-4 !flex !items-center !gap-2 !text-gray-800">
                                <Users className="!w-5 !h-5 !text-green-600" />
                                Block Scope
                            </h4>
                            <div className="!flex !flex-col !gap-3">
                                {blockScopeOptions.map((option, index) => (
                                    <RadioButtons key={option.value} id="blockScope" index={index} value={option.value} selectedValue={blockScope} handleChange={(e) => setBlockScope(e.target.value)} title={option.title} description={option.description} />
                                ))}
                            </div>
                            {blockScope === 'entire_site' && (
                                <div className="!mt-4">
                                    <label className="!block !text-sm !font-semibold !text-gray-700 !mb-2">Target Page</label>
                                    <div className="!inline-flex !items-center !p-0.5 !bg-gray-100 !rounded-lg !border !border-gray-200 !mb-3">
                                        <button
                                            type="button"
                                            onClick={() => { setTargetMode?.('default'); onBlockPageChange?.(null); }}
                                            className={`!px-3 !py-1.5 !text-xs !font-semibold !rounded-md !transition-all !border-0 !cursor-pointer
                                                ${targetMode === 'default' ? '!bg-white !text-[#007980] !shadow-sm' : '!bg-transparent !text-gray-500 hover:!text-gray-700'}`}
                                        >
                                            Default
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setTargetMode?.('specific')}
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
                                                onClick={onPreviewClick}
                                                className="!inline-flex !items-center !gap-1.5 !px-3 !py-1.5 !text-xs !font-semibold !text-white !bg-[#007980] !rounded-md hover:!bg-[#005f64] !transition-colors !whitespace-nowrap !border-0 !cursor-pointer !shadow-sm"
                                            >
                                                <Eye className="!w-3.5 !h-3.5" />
                                                Click to Preview
                                            </button>
                                        </div>
                                    ) : (
                                        <GetPostTypes
                                            onSelectionChange={(data) => {
                                                if (!onBlockPageChange) return;
                                                if (data.isCustom && data.customUrl) {
                                                    onBlockPageChange(data.customUrl);
                                                } else if (data.postId) {
                                                    onBlockPageChange(data.postId);
                                                } else {
                                                    onBlockPageChange(null);
                                                }
                                            }}
                                            initialPostId={initialPostId}
                                            initialPostType={initialPostType}
                                            initialCustomUrl={initialCustomUrl}
                                        />
                                    )}
                                </div>
                            )}
                        </div>
                    </div>

                    {/* Reason */}
                    <div className="!bg-white !border-2 !border-gray-200 !rounded-xl !p-4 sm:!p-5 !shadow-md">
                        <label className="!block !text-sm sm:!text-base !font-bold !text-gray-800 !mb-3">
                            Reason for Blocking <span className="!font-normal !text-gray-400">(Optional)</span>
                        </label>
                        <textarea value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Enter administrative notes about this blocking action..." className="!w-full !p-3 !border !border-gray-300 !rounded-lg focus:!ring-2 focus:!ring-[#007980] focus:!border-[#007980] !h-20 !resize-none !text-sm !bg-white !text-gray-800 focus:!outline-none" />
                    </div>
                </>
            ) : (
                /* Exemption Type — Whitelist only */
                <div className="!bg-white !border-2 !border-gray-200 !rounded-xl !p-4 sm:!p-5 !shadow-md">
                    <label className="!block !text-sm !font-semibold !text-gray-700 !mb-3">Exemption Type</label>
                    <div className="!flex !flex-col sm:!flex-row !gap-3 !w-full">
                        <RadioButtons
                            id="exemption"
                            index={0}
                            value="login_defender"
                            selectedValue={exemptionType}
                            handleChange={(e) => setExemptionType(e.target.value)}
                            title="Login Defender"
                            description="Exempt these countries from Login Defender rules"
                        />
                    </div>
                </div>
            )}
        </div>
    );
};
