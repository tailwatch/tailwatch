import React from 'react';
import { Edit, Clock, Shield, ShieldOff, AlertCircle } from 'lucide-react';
import ToggleSwitch from '../../../../Components/ToggleSwitcher/ToggleSwitch';
import IconButton from '../../../../Components/Buttons/IconButton';
import { Tooltip } from '../../../../Components/ToolTip/Tooltip';
import { CheckboxField } from '../../../../Components/Fields/CheckboxField';
import countries from 'i18n-iso-countries';
import { Flags } from '../../CountryList/CountryListModal/CountryLIstData';

// Register English locale for country names
countries.registerLocale(require("i18n-iso-countries/langs/en.json"));

export const BlackListData = ({ rowData, selectedItems, handleSelectItem, handleToggleUnblock, setEditingIpData, setShowModal,widget }) => {
  const isSelected = selectedItems.includes(rowData.ip_range);
  const isBlocked = rowData.block_type === 'permanent' || rowData.block_type === 'temporary';
  const isPermanent = rowData.block_type === 'permanent';
  const isTemporary = rowData.block_type === 'temporary';

  const formatDuration = (duration) => {
    const minutes = Math.round(duration / 60);
    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;

    if (hours > 0) {
      return `${hours}h ${remainingMinutes}m`;
    }
    return `${minutes}m`;
  };
  const getCountryName = (countryCode) => {
    const countryName = countries.getName(countryCode, 'en');
    return countryName || countryCode; // Fallback to code if name not found
  };

  const getStatusConfig = () => {
    if (isPermanent) {
      return { icon: <Shield size={14} />, color: '!text-red-600', bgColor: '!bg-red-50', borderColor: '!border-red-200', label: 'Permanently Blocked', badgeClass: '!bg-red-100 !text-red-800 !border-red-200' };
    }
    if (isTemporary) {
      return { icon: <Clock size={14} />, color: '!text-amber-600', bgColor: '!bg-amber-50', borderColor: '!border-amber-200', label: 'Temporarily Blocked', badgeClass: '!bg-amber-100 !text-amber-800 !border-amber-200' };
    }
    return { icon: <ShieldOff size={14} />, color: '!text-green-600', bgColor: '!bg-green-50', borderColor: '!border-green-200', label: 'Active (Unblocked)', badgeClass: '!bg-green-100 !text-green-800 !border-green-200' };
  };

  const statusConfig = getStatusConfig();

  return (
    <>
      {!widget && (<td className="!p-3">
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

      <td className="!p-3">
        <div className={`!inline-flex !items-center !space-x-2 !px-3 !py-2 !rounded-lg !border ${statusConfig.bgColor} ${statusConfig.borderColor}`}>
          <span className={statusConfig.color}>
            {statusConfig.icon}
          </span>
          <div className="!flex !flex-col">
            <span className={`!text-xs !font-medium ${statusConfig.color}`}>
              {statusConfig.label}
            </span>
            {isTemporary && (
              <span className="!text-xs !text-gray-500">
                Expires in {rowData.time_remaining || formatDuration(rowData.block_duration)}
              </span>
            )}
          </div>
        </div>
      </td>

      <td className="!p-3 !text-sm !text-gray-600">
        {isPermanent ? (
          <div className="!flex !items-center !space-x-1">
            <AlertCircle size={14} className="!text-red-500" />
            <span className="!font-medium !text-red-600">Permanent</span>
          </div>
        ) : isTemporary ? (
          <div className="!flex !items-center !space-x-1">
            <Clock size={14} className="!text-amber-500" />
            <span className="!font-medium">{formatDuration(rowData.block_duration)}</span>
          </div>
        ) : (
          <span className="!text-gray-400">-</span>
        )}
      </td>

      <td className="!p-3 !text-sm !text-gray-600">
        {rowData.block_start_time ? (
          <div className="!flex !flex-col">
            <span className="!font-medium">{rowData.block_start_time.split(' ')[0]}</span>
            <span className="!text-xs !text-gray-400">{rowData.block_start_time.split(' ')[1]}</span>
          </div>
        ) : (
          <span className="!text-gray-400">-</span>
        )}
      </td>

      {/* Reason */}
      <td className="!p-3 !text-sm !text-gray-600">
        <div className="!max-w-xs">
          <span className={`${rowData.reason ? '!text-gray-700' : '!text-gray-400 !italic'}`}>
            {rowData.reason || 'No reason specified'}
          </span>
        </div>
      </td>

      {/* Smart Action Column */}
      <td className="!p-3">
        <div className="!flex !space-x-2">
          <div className="!flex !items-center !space-x-2">
            <Tooltip message={!isBlocked ? "Ip is currently unblocked. To block it again edit it and enable blocking." : "Ip is Blocked"}>
              <ToggleSwitch onChange={() => handleToggleUnblock(rowData.ip_range)} checked={isBlocked} disabled={!isBlocked} />
            </Tooltip>
          </div>
        </div>
      </td>
      {/* Edit Actions */}
      <td className="!p-3">
        <Tooltip message="Edit">
        <IconButton icon={Edit} onClick={() => { setEditingIpData(rowData); setShowModal(true); }} bgColor="bg-gray-200" hoverBgColor="hover:bg-gray-100" textColor="text-gray-600 hover:text-green-500" roundedFull={true} className="!p-2 !transition !rounded-[5px] !duration-200 hover:!shadow-sm !transition !duration-200 hover:!shadow-sm" />
        </Tooltip>
      </td>
    </>
  );
};