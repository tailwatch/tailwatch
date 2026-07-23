import { Edit, Clock, Shield, ShieldOff, AlertCircle } from 'lucide-react';
import ToggleSwitch from '../../../../Components/ToggleSwitcher/ToggleSwitch';
import IconButton from '../../../../Components/Buttons/IconButton';
import countries from 'i18n-iso-countries';
import { Tooltip } from '../../../../Components/ToolTip/Tooltip';
import { CheckboxField } from '../../../../Components/Fields/CheckboxField';
import { CountryFlag, Flags } from '../../../../Components/CountryFlag/CountryFlag';

// Register English locale for country names
countries.registerLocale(require("i18n-iso-countries/langs/en.json"));

// Country flags render as Unicode emoji via the shared CountryFlag component
// (backed by a bundled OFL flags webfont so they show on Windows too). Re-exported
// so existing `import { Flags } from '...CountryLIstData'` call sites work.
export { CountryFlag, Flags };

export const CountryListData = ({ rowData, selectedItems, handleSelectItem, handleToggleUnblock, setEditingCountryData, setShowModal }) => {
  const isSelected = selectedItems.includes(rowData.country_code);
  const isBlocked = rowData.block_type === 'permanent' || rowData.block_type === 'temporary';
  const isPermanent = rowData.block_type === 'permanent';
  const isTemporary = rowData.block_type === 'temporary';

  const getCountryName = (countryCode) => {
    const countryName = countries.getName(countryCode, 'en');
    return countryName || countryCode;
  };

  const formatDuration = (duration) => {
    const minutes = Math.round(duration / 60);
    const hours = Math.floor(minutes / 60);
    const remainingMinutes = minutes % 60;
    if (hours > 0) return `${hours}h ${remainingMinutes}m`;
    return `${minutes}m`;
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
      <td className="!px-4 !py-3">
        <div className="!flex !items-center">
          <CheckboxField checked={isSelected} onChange={() => handleSelectItem(rowData.country_code)} />
        </div>
      </td>

      <td className="!px-4 !py-3">
        <div className="!flex !items-center !space-x-3">
          <Flags countryCode={rowData.country_code} size="lg" />
          <div className="!flex !flex-col">
            <span className="!font-medium !text-gray-900">
              {getCountryName(rowData.country_code)}
            </span>
            <span className="!text-xs !text-gray-500">
              {rowData.country_code}
            </span>
          </div>
        </div>
      </td>

      <td className="!px-4 !py-3">
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

      <td className="!px-4 !py-3 !text-sm !text-gray-600">
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

      <td className="!px-4 !py-3 !text-sm !text-gray-600">
        {rowData.block_start_time ? (
          <div className="!flex !flex-col">
            <span className="!font-medium">{rowData.block_start_time.split(' ')[0]}</span>
            <span className="!text-xs !text-gray-400">{rowData.block_start_time.split(' ')[1]}</span>
          </div>
        ) : (
          <span className="!text-gray-400">-</span>
        )}
      </td>

      <td className="!px-4 !py-3 !text-sm !text-gray-600">
        <div className="!max-w-xs">
          <span className={`${rowData.reason ? '!text-gray-700' : '!text-gray-400 !italic'}`}>
            {rowData.reason || 'No reason specified'}
          </span>
        </div>
      </td>

      <td className="!px-4 !py-3 !text-sm !text-gray-600">
        <div className="!max-w-xs">
          <span className={`${rowData.scope ? '!text-gray-700' : '!text-gray-400 !italic'}`}>
            {rowData.scope ? String(rowData.scope).replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()) : 'permanent'}
          </span>
        </div>
      </td>

      <td className="!px-4 !py-3">
        <div className="!flex !items-center !justify-center !space-x-2">
          <div className="!flex !items-center !space-x-2">
            <Tooltip message={!isBlocked ? 'Edit the option' : 'Country is Blocked'}>
              <ToggleSwitch onChange={() => handleToggleUnblock(rowData.country_code)} checked={isBlocked} disabled={!isBlocked} />
            </Tooltip>
          </div>
        </div>
      </td>

      <td className="!px-4 !py-3">
        <IconButton tooltip="Edit" icon={Edit} onClick={() => { setEditingCountryData(rowData); setShowModal(true); }} bgColor="bg-gray-200" hoverBgColor="hover:bg-gray-100" textColor="text-gray-600 hover:text-green-500" roundedFull={true} className="!p-2 !transition !rounded-[5px] !duration-200 hover:!shadow-sm" />
      </td>
    </>
  );
};
