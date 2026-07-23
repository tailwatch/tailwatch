import { Edit, Shield } from 'lucide-react';
import IconButton from '../../../../Components/Buttons/IconButton';
import { CheckboxField } from '../../../../Components/Fields/CheckboxField';
import countries from 'i18n-iso-countries';
import { Flags } from '../../CountryList/CountryListModal/CountryLIstData';

// Register English locale for country names
countries.registerLocale(require("i18n-iso-countries/langs/en.json"));
export const WhiteListIpData = ({ rowData, selectedItems, handleSelectItem, setEditingIpData, setShowModal,widget }) => {
    const isSelected = selectedItems.includes(rowData.ip_range);
    const getCountryName = (countryCode) => {
        const countryName = countries.getName(countryCode, 'en');
        return countryName || countryCode; // Fallback to code if name not found
    };
    return (
        <>
        {!widget && (
            <td className="!p-3 !w-16">
                <div className="!flex !items-center">
                    <CheckboxField checked={isSelected} onChange={() => handleSelectItem(rowData.ip_range)} />
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

            <td className="!p-3">
                <div className="!flex !items-center !space-x-2">
                    <span className="!px-2 !py-1 !bg-gray-100 !rounded !text-sm !font-mono !text-gray-800">
                        {rowData.ip_range}
                    </span>
                </div>
            </td>

            <td className="!p-3 !text-sm !text-gray-600">
                <div className="!flex !items-center !space-x-1">
                    <Shield size={14} className="!text-amber-500" />
                    <span className="!font-medium">{rowData.exemption ? String(rowData.exemption).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : '—'}</span>
                </div>
            </td>

            <td className="!p-3">
                <IconButton tooltip="Edit" icon={Edit} onClick={() => { setEditingIpData(rowData); setShowModal(true); }} bgColor="bg-gray-200" hoverBgColor="hover:bg-gray-100" textColor="text-gray-600 hover:text-green-500" roundedFull={true} className="!p-2 !transition !rounded-[5px] !duration-200 hover:!shadow-sm" />
            </td>
        </>
    );
};