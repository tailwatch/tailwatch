import React from 'react';
import { Edit, Shield } from 'lucide-react';
import IconButton from '../../../../Components/Buttons/IconButton';
import countries from 'i18n-iso-countries';
import { CheckboxField } from '../../../../Components/Fields/CheckboxField';
import { CountryFlag, Flags } from '../../../../Components/CountryFlag/CountryFlag';

// Register English locale for country names
countries.registerLocale(require("i18n-iso-countries/langs/en.json"));

// Country flags render as Unicode emoji via the shared CountryFlag component
// (backed by a bundled OFL flags webfont so they show on Windows too). Re-exported
// so existing `import { Flags } from '...WhiteListCountryData'` call sites work.
export { CountryFlag, Flags };

export const WhiteListCountryData = ({ rowData, selectedItems, handleSelectItem, setEditingCountryData, setShowModal,widget }) => {
    const isSelected = selectedItems.includes(rowData.country_code);

    const getCountryName = (countryCode) => {
        const countryName = countries.getName(countryCode, 'en');
        return countryName || countryCode;
    };

    return (
        <>
        {!widget && (
            <td className="!p-3 !w-16">
                <div className="!flex !items-center">
                    <CheckboxField checked={isSelected} onChange={() => handleSelectItem(rowData.country_code)}/>                    
                </div>
            </td>
        )}
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

            <td className="!p-3 !text-sm !text-gray-600">
                <div className="!flex !items-center !space-x-1">
                    <Shield size={14} className="!text-amber-500" />
                    <span className="!font-medium">{rowData.exemption ? String(rowData.exemption).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : '—'}</span>
                </div>
            </td>

            <td className="!p-3">
                <IconButton tooltip="Edit" icon={Edit} onClick={() => { setEditingCountryData(rowData); setShowModal(true); }} bgColor="bg-gray-200" hoverBgColor="hover:bg-gray-100" textColor="text-gray-600 hover:text-green-500" roundedFull={true} className="!p-2 !transition !rounded-[5px] !duration-200 hover:!shadow-sm" />
            </td>
        </>
    );
};