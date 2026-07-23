import React from 'react';
import { BellRing, BellOff } from 'lucide-react';
import { Tooltip } from '../../../Components/ToolTip/Tooltip';

const NotificationButton = ({
  isLoading,
  notificationEnabled = false,
  handleOpenModal,
  featureId,
  feature,
  forceDisabled = false,
  forceTooltip,
}) => {
  // Calculate enabled notifications count
  const getEnabledCount = () => {
    if (!feature || feature.is_active !== "1") return 0;

    try {
      const parsedValue = JSON.parse(feature.value || "{}");
      const options = parsedValue.options || {};
      const activeOptions = Object.keys(options).filter(
        (key) => options[key].values?.option?.selected &&
          options[key].push_notification !== undefined
      );

      return activeOptions.reduce((count, key) => {
        const field = options[key];
        return count + (field.push_notification ? 1 : 0);
      }, 0);
    } catch (error) {
      return 0;
    }
  };

  const enabledCount = getEnabledCount();
  const hasEnabledNotifications = enabledCount > 0;

  // forceDisabled overrides the regular notification-enabled gating (e.g. license not
  // connected, pro plugin not installed). When set, the button is locked regardless of
  // the underlying notificationEnabled flag.
  const isDisabled = forceDisabled || notificationEnabled === false;
  const showActive = !forceDisabled && notificationEnabled;

  const tooltipMessage = forceDisabled
    ? (forceTooltip || 'Connect to Tailwatch')
    : isDisabled
      ? "Enable Push Notification"
      : notificationEnabled
        ? `Manage mobile notifications${hasEnabledNotifications ? ` (${enabledCount} active)` : ''}`
        : "Set up mobile notifications";

  const handleClick = () => {
    if (isDisabled) return;
    handleOpenModal(featureId);
  };

  return (
    <Tooltip message={tooltipMessage} position="top" className='!bg-[#ec5023]'>
      <div onClick={handleClick} className={`relative group ${isDisabled ? 'cursor-not-allowed' : 'cursor-pointer'}`}>
        <button disabled={isDisabled || isLoading} className={`flex items-center justify-center p-2 rounded-lg transition-all duration-300 relative transform hover:scale-105 hover:shadow-lg
            ${isDisabled ? "opacity-50 cursor-not-allowed bg-gray-100" : "cursor-pointer"} ${showActive ? "bg-gradient-to-r from-[#85cbcf] to-[#6bb6c0] shadow-md hover:from-[#6bb6c0] hover:to-[#5a9ea8]" : "bg-gray-200 hover:bg-gray-300"}`} aria-label={tooltipMessage}
        >
          {isLoading ? (
            <div className="h-5 w-5 rounded-full bg-gray-200 animate-pulse"></div>
          ) : (
            <>
              {showActive ? (
                <BellRing className="h-5 w-5 text-white drop-shadow-sm" />
              ) : (
                <BellOff className="h-5 w-5 text-gray-600" />
              )}
            </>
          )}
        </button>

        {/* Enhanced Notification Count Badge */}
        {!isLoading && showActive && hasEnabledNotifications && (
          <div className="absolute -top-2 -right-2 bg-gradient-to-r from-orange-500 to-orange-600 text-white text-xs font-bold rounded-full h-6 w-6 flex items-center justify-center min-w-6 shadow-lg border-2 border-white transform transition-all duration-200 group-hover:scale-110">
            {enabledCount > 99 ? '99+' : enabledCount}
          </div>
        )}

        {/* Pulse animation for active notifications */}
        {!isLoading && showActive && hasEnabledNotifications && (
          <div className="absolute -top-2 -right-2 h-6 w-6 bg-orange-400 rounded-full animate-ping opacity-30"></div>
        )}
      </div>
    </Tooltip>
  );
};

export default NotificationButton;
