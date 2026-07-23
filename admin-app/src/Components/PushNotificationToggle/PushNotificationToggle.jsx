import { useState, useEffect } from "react";
import { toast } from "react-toastify";
import { useNavigate } from "react-router-dom";
import { Crown } from "lucide-react";
import ToggleSwitcher from '../ToggleSwitcher/ToggleSwitch';
import PopUpModal from '../Modal/PopUpModal';
import Skeleton from "react-loading-skeleton";
import "react-loading-skeleton/dist/skeleton.css";
import { fetchNotificationStatus, handleToggleNotification } from '../Header/Services/Services';
import { FaBell, FaBellSlash } from "react-icons/fa";
import { mobileNotification as mobileNotificationImg } from "../Hooks/useImages/useImages";

const PushNotificationToggle = ({ onStatusChange, showLabel = true, labelText = "Push Notification" }) => {
  const [loading, setLoading] = useState(true);
  const [notificationData, setNotificationData] = useState({
    push_notification: false,
    mobile_notification: false,
    severity_settings: {
      critical: true,
      warning: true,
      info: true
    }
  });
  const [showModal, setShowModal] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [isMobileDisabled, setIsMobileDisabled] = useState(null);
  const [tempMobileToggle, setTempMobileToggle] = useState(false);
  const navigate = useNavigate();

  const loadNotificationStatus = async () => {
    setLoading(true);
    try {
      const data = await fetchNotificationStatus({ onStatusChange });
      setNotificationData(data);
      setTempMobileToggle(data.mobile_notification);
      setIsMobileDisabled(data.mobile_notification_disabled);
    } catch (error) {
      const defaultData = {
        push_notification: false,
        mobile_notification: false,
        severity_settings: { critical: true, warning: true, info: true }
      };
      setNotificationData(defaultData);
      setTempMobileToggle(defaultData.mobile_notification);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    loadNotificationStatus();
  }, []);

  const handleOpenModal = () => {
    setTempMobileToggle(notificationData.mobile_notification);
    setShowModal(true);
  };

  const handleCloseModal = () => {
    setShowModal(false);
  };

  const handleSave = async () => {
    setIsSaving(true);
    const prevMobile = notificationData.mobile_notification;
    try {
      // Preserve existing email/severity settings from the backend so this save doesn't
      // reset them — this UI now only manages the mobile-notification toggle.
      const success = await handleToggleNotification({
        isEnabled: notificationData.push_notification,
        severitySettings: notificationData.severity_settings,
        mobileNotification: tempMobileToggle,
        loadNotificationStatus
      });
      if (success !== false) {
        if (tempMobileToggle !== prevMobile) {
          toast[tempMobileToggle ? "success" : "info"](
            tempMobileToggle ? "Mobile Notifications Enabled" : "Mobile Notifications Disabled"
          );
        } else {
          toast.success("Notification settings saved.");
        }
        setShowModal(false);
      }
    } catch (error) {
      // Error modal will be shown by axios interceptor — keep modal open so user can retry.
    } finally {
      setIsSaving(false);
    }
  };

  const handleConnectLicense = () => {
    setShowModal(false);
    navigate("/dashboard/settings/license");
  };

  const isLicenseDisconnected = isMobileDisabled === false;
  // While the license is disconnected, mobile notifications cannot be active even if the
  // backend still has a stale `mobile_notification: true` value — so the trigger should
  // render in its disabled (gray) state rather than the green "Enabled" state.
  const isAnyNotificationEnabled = !isLicenseDisconnected && notificationData.mobile_notification;

  return (
    <>
      {/* Icon-only mode with tooltip */}
      {!showLabel ? (
        <div className="relative group">
          <div
            onClick={handleOpenModal}
            className={`w-9 h-9 rounded-lg flex items-center justify-center cursor-pointer border-2 shadow-sm transition-all duration-300
              ${loading
                ? 'bg-gray-200 border-gray-200 cursor-not-allowed opacity-60 pointer-events-none'
                : isAnyNotificationEnabled
                  ? 'bg-emerald-50 border-emerald-400 hover:bg-emerald-100 hover:shadow-md'
                  : 'bg-gray-100 border-gray-300 hover:bg-gray-200'
              }`}
          >
            {loading ? (
              <Skeleton width={16} height={16} circle />
            ) : (
              <div className="relative">
                {isAnyNotificationEnabled ? (
                  <FaBell className="text-emerald-600 text-base animate-pulse" />
                ) : (
                  <FaBellSlash className="text-gray-500 text-base" />
                )}
              </div>
            )}
          </div>

          {/* Tooltip */}
          <div className="absolute right-0 top-full mt-2.5 w-48 bg-[#ec5023] text-white rounded-lg p-3 opacity-0 invisible group-hover:opacity-100 group-hover:visible transition-all duration-200 z-50 pointer-events-none shadow-xl">
            <div className="absolute -top-1.5 right-3 w-3 h-3 bg-[#ec5023] rotate-45 rounded-sm" />
            <p className="text-xs font-semibold mb-1">{labelText}</p>
            <p className="text-[11px] text-gray-300">
              {isAnyNotificationEnabled ? 'Mobile notifications active' : 'Disabled — click to configure'}
            </p>
          </div>
        </div>
      ) : (
        /* Full label mode */
        <div
          onClick={handleOpenModal}
          className={`relative rounded-lg px-4 py-2 shadow-sm transition-all duration-300 cursor-pointer
            ${loading
              ? 'bg-gray-200 cursor-not-allowed opacity-60 pointer-events-none'
              : isAnyNotificationEnabled
                ? 'bg-gradient-to-r from-emerald-50 to-teal-50 border-2 border-emerald-400 hover:shadow-md'
                : 'bg-gray-100 border-2 border-gray-300 hover:bg-gray-200'
            }`}
        >
          <div className={`inline-flex items-center ${loading ? "pointer-events-none opacity-60 cursor-not-allowed" : ""}`}>
            {loading ? (
              <Skeleton width={120} height={20} />
            ) : (
              <>
                <div className="relative mr-3">
                  {isAnyNotificationEnabled ? (
                    <FaBell className="text-emerald-600 text-lg animate-pulse" />
                  ) : (
                    <FaBellSlash className="text-gray-500 text-lg" />
                  )}
                </div>
                <div className="flex flex-col">
                  <span className={`text-sm font-medium ${isAnyNotificationEnabled ? "text-emerald-700" : "text-gray-700"}`}>
                    {labelText}
                  </span>
                  <span className="text-xs text-gray-500">
                    {isAnyNotificationEnabled ? "Enabled" : "Disabled"}
                  </span>
                </div>
              </>
            )}
          </div>
        </div>
      )}

      {showModal && (
        <PopUpModal showExpandIcon={true} title="Real-time Notifications" onClose={handleCloseModal} onSave={handleSave} saveButtonText="Save Settings" cancelButtonText="Cancel" isLoading={isSaving}
          disabled={isSaving || isLicenseDisconnected} width="w-full max-w-2xl" glowColor="#10b981" >
          <div className="space-y-4 sm:space-y-6 p-1">

            {/* Mobile Notification Toggle */}
            <div className="bg-white border-2 border-gray-200 rounded-xl p-3 sm:p-4 md:p-5 hover:border-emerald-300 transition-all">
              <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 sm:gap-4">
                <div className="flex-1 min-w-0">
                  <div className="flex items-center space-x-2 sm:space-x-3 mb-2">
                    <div className={`p-1.5 sm:p-2 rounded-lg flex-shrink-0 ${tempMobileToggle ? 'bg-emerald-100' : 'bg-gray-100'}`}>
                      {tempMobileToggle ? (
                        <FaBell className="text-emerald-600 text-base sm:text-lg md:text-xl" />
                      ) : (
                        <FaBellSlash className="text-gray-500 text-base sm:text-lg md:text-xl" />
                      )}
                    </div>
                    <h3 className="text-base sm:text-lg font-semibold text-gray-800 truncate">
                      Mobile Notifications
                    </h3>
                  </div>
                  <p className="text-xs sm:text-sm text-gray-600 ml-8 sm:ml-11 md:ml-12 break-words -mt-2">
                    {isLicenseDisconnected
                      ? "Please connect your free Tailwatch account to enable mobile notifications."
                      : "Please connect your free Tailwatch account to enable mobile notifications."}
                  </p>
                </div>
                <div className="flex-shrink-0 self-end sm:self-auto sm:ml-4">
                  {isLicenseDisconnected ? (
                    <button
                      type="button"
                      onClick={handleConnectLicense}
                      className="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold text-white bg-gradient-to-r from-[#007980] to-[#0a9aa3] hover:from-[#006670] hover:to-[#088d97] shadow-sm hover:shadow transition-all duration-200"
                    >
                      <Crown size={12} />
                      Connect to Tailwatch
                    </button>
                  ) : (
                    <ToggleSwitcher
                      checked={tempMobileToggle}
                      onChange={(e) => setTempMobileToggle(e.target.checked)}
                      disabled={false}
                    />
                  )}
                </div>
              </div>
            </div>

            {/* Mobile mockup illustration */}
            {/* <div className="flex justify-center pt-2">
              <img
                src={mobileNotificationImg}
                alt="Mobile notification preview"
                className="max-w-full h-auto max-h-[420px] object-contain drop-shadow-md"
              />
            </div> */}

          </div>
        </PopUpModal>
      )}
    </>
  );
};

export default PushNotificationToggle;
