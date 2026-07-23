import { useState, useMemo } from "react";
import { countries } from 'countries-list';
import PopUpModal from '../../../../Components/Modal/PopUpModal';
import { blockCountries, updateCountries, addwhitelistCountries, updatewhitelistCountries } from "../../IpManagementServices/IpManagementServices";
import { CountryModalContent } from "./CountryModalContent";
import BlockPagePreviewModal from "../../BlockPagePreviewModal/BlockPagePreviewModal";

const CountryListModal = ({ onClose, getBlockCountriesData, isEditing = false, initialData = null, isWhiteList = false }) => {
    const convertSecondsToDuration = (seconds) => {
        if (seconds % (7 * 24 * 60 * 60) === 0) {
            return { duration: (seconds / (7 * 24 * 60 * 60)).toString(), unit: 'weeks' };
        } else if (seconds % (24 * 60 * 60) === 0) {
            return { duration: (seconds / (24 * 60 * 60)).toString(), unit: 'days' };
        } else if (seconds % (60 * 60) === 0) {
            return { duration: (seconds / (60 * 60)).toString(), unit: 'hours' };
        } else if (seconds % 60 === 0) {
            return { duration: (seconds / 60).toString(), unit: 'minutes' };
        } else {
            return { duration: Math.floor(seconds / (60 * 60)).toString(), unit: 'hours' };
        }
    };

    // Initialize state with prefilled values if editing
    const [selectedCountries, setSelectedCountries] = useState(
        isEditing && initialData?.country_code ? [initialData.country_code] : []
    );
    const [blockType, setBlockType] = useState(
        isEditing && initialData?.block_type ? initialData?.block_type : 'temporary'
    );

    const initialDuration = isEditing && initialData?.block_duration
        ? convertSecondsToDuration(initialData.block_duration)
        : { duration: '24', unit: 'hours' };

    const [blockDuration, setBlockDuration] = useState(initialDuration?.duration);
    const [durationType, setDurationType] = useState(initialDuration?.unit);
    const [exemptionType, setExemptionType] = useState(initialData?.exemption || 'login_defender');
    const [blockScope, setBlockScope] = useState(
        isEditing && initialData?.scope ? initialData.scope : 'login_forms'
    );
    const [reason, setReason] = useState(
        isEditing && initialData?.reason ? initialData.reason : ''
    );
    // Pre-fill the Target Page section when editing a country rule with a
    // specific page already saved. Backend returns block_page (post ID or
    // custom URL) plus block_page_post_type so the picker can restore it.
    const hasInitialBlockPage =
        isEditing && initialData?.block_page != null && initialData?.block_page !== '';
    const [blockPage, setBlockPage] = useState(hasInitialBlockPage ? initialData.block_page : null);
    const [targetMode, setTargetMode] = useState(hasInitialBlockPage ? 'specific' : 'default');
    const [searchTerm, setSearchTerm] = useState('');
    const [isLoading, setLoading] = useState(false);
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

    const processedCountries = useMemo(() => {
        // Map countries-list continent codes to the region labels used by the
        // region quick-select pills (see REGIONS in CountryModalContent):
        // Asia, Africa, Europe, Oceania, Antarctic, Americas.
        const continentToRegion = { AF: 'Africa', AN: 'Antarctic', AS: 'Asia', EU: 'Europe', NA: 'Americas', OC: 'Oceania', SA: 'Americas' };
        return Object.entries(countries)
            .map(([code, country]) => ({
                code,
                name: country.name,
                region: continentToRegion[country.continent] || 'Other',
                subregion: continentToRegion[country.continent] || 'Other',
                capital: country.capital || 'N/A',
            }))
            .sort((a, b) => a.name.localeCompare(b.name));
    }, []);

    const filteredCountries = useMemo(() => {
        return processedCountries.filter(country =>
            country.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
            country.code.toLowerCase().includes(searchTerm.toLowerCase()) ||
            country.region.toLowerCase().includes(searchTerm.toLowerCase()) ||
            country.capital.toLowerCase().includes(searchTerm.toLowerCase())
        );
    }, [processedCountries, searchTerm]);

    const toggleCountrySelection = (countryCode) => {
        setSelectedCountries(prev => prev.includes(countryCode) ? prev.filter(code => code !== countryCode) : [...prev, countryCode]
        );
    };

    const convertDurationToSeconds = (duration, unit) => {
        const durationValue = parseInt(duration) || 0;
        switch (unit) {
            case 'minutes':
                return durationValue * 60;
            case 'hours':
                return durationValue * 60 * 60;
            case 'days':
                return durationValue * 60 * 60 * 24;
            case 'weeks':
                return durationValue * 60 * 60 * 24 * 7;
            default:
                return durationValue * 60 * 60;
        }
    };

    const handleSubmit = async () => {
    setLoading(true);
    try {
        if (isWhiteList) {
            const payload = { 
                country_codes: selectedCountries, 
                exemption: exemptionType 
            };

            if (isEditing) {
                await updatewhitelistCountries({ setLoading, onClose, payload, getBlockCountriesData });
            } else {
                await addwhitelistCountries({ setLoading, onClose, payload, getBlockCountriesData });
            }
        } else {
            let payload = { 
                country_codes: selectedCountries, 
                block_type: blockType, 
                reason: reason || "Your ip block due to violations." 
            };

            if (blockType === "temporary") {
                payload.block_duration = convertDurationToSeconds(blockDuration, durationType);
                payload.scope = blockScope;
                payload.block_page = blockScope === 'entire_site' && targetMode === 'specific' ? blockPage : null;
            } else if (blockType === "permanent") {
                payload.block_duration = "0";
                payload.scope = blockScope;
                payload.block_page = blockScope === 'entire_site' && targetMode === 'specific' ? blockPage : null;
            }
            // if blockType === "unblocked", we don't add duration/scope

            if (isEditing) {
                await updateCountries({ setLoading, onClose, payload, getBlockCountriesData });
            } else {
                await blockCountries({ setLoading, onClose, payload, getBlockCountriesData });
            }
        }
    } finally {
        setLoading(false);
    }
};


    const clearSelection = () => {
        setSelectedCountries([]);
    };

    const selectByRegion = (region) => {
        const regionCodes = processedCountries.filter(country => country.region === region).map(country => country.code);

        const allRegionCountriesSelected = regionCodes.every(code =>
            selectedCountries.includes(code)
        );

        if (allRegionCountriesSelected) {
            setSelectedCountries(prev => prev.filter(code => !regionCodes.includes(code)));
        } else {
            setSelectedCountries(prev => [...new Set([...prev, ...regionCodes])]);
        }
    };

    const isRegionSelected = (region) => {
        const regionCodes = processedCountries.filter(country => country.region === region).map(country => country.code);

        return regionCodes.length > 0 && regionCodes.every(code =>
            selectedCountries.includes(code)
        );
    };
    const stats = useMemo(() => {
        const totalCountries = processedCountries.length;
        return { total: totalCountries };
    }, [processedCountries]);

    return (
        <>
            <PopUpModal title={isEditing ? "Edit Countries" : "Add Block Countries"} onClose={onClose} onSave={handleSubmit} saveButtonText="Apply Country Blocks" cancelButtonText="Cancel" disabled={selectedCountries.length === 0} isLoading={isLoading} showExpandIcon={true} width="w-full max-w-6xl" height="max-h-[95vh]" >
                <CountryModalContent stats={stats} selectedCountries={selectedCountries} selectByRegion={selectByRegion} isRegionSelected={isRegionSelected} clearSelection={clearSelection} exemptionType={exemptionType} setExemptionType={setExemptionType}
                    processedCountries={processedCountries} toggleCountrySelection={toggleCountrySelection} searchTerm={searchTerm} setSearchTerm={setSearchTerm} filteredCountries={filteredCountries} blockType={blockType} setBlockType={setBlockType} isEditing={isEditing} blockDuration={blockDuration}
                    setBlockDuration={setBlockDuration} durationType={durationType} setDurationType={setDurationType} blockScopeOptions={blockScopeOptions} blockScope={blockScope} setBlockScope={setBlockScope} reason={reason} setReason={setReason} isWhiteList={isWhiteList}
                    onBlockPageChange={setBlockPage} onPreviewClick={() => setShowPreview(true)} targetMode={targetMode} setTargetMode={setTargetMode}
                    initialPostId={initialData?.block_page_post_type && initialData.block_page_post_type !== 'custom' ? initialData.block_page : ''}
                    initialPostType={initialData?.block_page_post_type === 'custom' ? '' : (initialData?.block_page_post_type || '')}
                    initialCustomUrl={initialData?.block_page_post_type === 'custom' ? String(initialData?.block_page || '') : ''}
                />
            </PopUpModal>
            {showPreview && (
                <BlockPagePreviewModal
                    onClose={() => setShowPreview(false)}
                    payload={{ scope: 'default', context: 'country' }}
                />
            )}
        </>
    );
};

export default CountryListModal;
