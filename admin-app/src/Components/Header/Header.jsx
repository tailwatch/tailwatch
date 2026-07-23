import React, { useState, useEffect, useRef } from "react";
import ToggleSwitcher from '../ToggleSwitcher/ToggleSwitch';
import "react-loading-skeleton/dist/skeleton.css";
import ProfileLog from "./ProfileLog/ProfileLog";
import Skeleton from "react-loading-skeleton";
import { fetchNotificationStatus, handleToggleNotification, processMoniteringStatus } from "./Services/Services";
import { Tooltip } from "../ToolTip/Tooltip";
import SearchBar from "../SearchBar/SearchBar";
import { ArrowLeft, Menu, CircleCheckBig, AlertTriangle, Crown, ArrowRight, FileCode } from "lucide-react";
import { useNavigate } from "react-router-dom";
import SystemInfo from '../SystemInfo/SystemInfo';
import { useLicense } from '../Context/LicenseProvider';

const Header = ({ title, showNotificationSwitch = false, onStatusChange, searchQuery, setSearchQuery, showBackIcon = false, backIconCallback = null }) => {
  const lordIconRef = useRef();
  const [loading, setLoading] = useState(true);
  const [notificationEnabled, setNotificationEnabled] = useState(false);
  const [showSystemInfoModal, setShowSystemInfoModal] = useState(false);
  const [showProcessDropdown, setShowProcessDropdown] = useState(false);
  const [processLoading, setProcessLoading] = useState(false);
  const [processData, setProcessData] = useState(null);
  const [showMobileMenu, setShowMobileMenu] = useState(false);
  const [showIcon, setShowIcon] = useState(window.innerWidth > 1274);
  const processDropdownRef = useRef(null);

  const navigate = useNavigate();
  const license = useLicense() || {};
  const isAccountSuspended = license?.planStatus === 'suspended';
  const suspensionReason = license?.suspensionReason;
  const endDate = license?.endDate;
  const isPaymentDue = !isAccountSuspended && !!endDate && new Date(endDate).getTime() < Date.now();
  const planName = license?.planName;
  const showUpgradeLink = planName !== 'Business' && planName !== 'Agency';
  const formattedEndDate = endDate
    ? new Date(endDate).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
    : '';

  const loadNotificationStatus = async () => {
    setLoading(true);
    const status = await fetchNotificationStatus({ onStatusChange });
    setNotificationEnabled(status);
    setLoading(false);
  };

  useEffect(() => {
    const handler = (e) => {
      if (processDropdownRef.current && !processDropdownRef.current.contains(e.target)) {
        setShowProcessDropdown(false);
      }
    };
    document.addEventListener('click', handler);
    return () => document.removeEventListener('click', handler);
  }, []);
  const handleCardMouseEnter = () => {
    if (lordIconRef.current) {
      lordIconRef.current.play();
    }
  };

  const handleCardMouseLeave = () => {
    if (lordIconRef.current) {
      lordIconRef.current.stop();
    }
  };

  const handlePhpIconClick = () => {
    setShowSystemInfoModal(true);
  };

  const toggleProcessDropdown = async () => {
    const opening = !showProcessDropdown;
    setShowProcessDropdown(opening);
    if (opening && !processData) {
      setProcessLoading(true);
      const payload = await processMoniteringStatus();
      setProcessData(payload);
      setProcessLoading(false);
    }
  };

  const refreshProcessData = async () => {
    setProcessLoading(true);
    const payload = await processMoniteringStatus();
    setProcessData(payload);
    setProcessLoading(false);
  };

  const handleCloseSystemInfoModal = () => {
    setShowSystemInfoModal(false);
  };

  useEffect(() => {
    if (showNotificationSwitch) {
      loadNotificationStatus();
    }
  }, [showNotificationSwitch]);

  const handleToggle = async (isEnabled) => {
    setLoading(true);
    await handleToggleNotification({ isEnabled, loadNotificationStatus });
    setLoading(false);
  };

  const handleBackClick = () => {
    if (backIconCallback && typeof backIconCallback === 'function') {
      backIconCallback();
    } else {
      navigate(-1);
    }
  };

  useEffect(() => {
    const handleResize = () => {
      setShowIcon(window.innerWidth > 1274);
    };

    window.addEventListener("resize", handleResize);
    return () => window.removeEventListener("resize", handleResize);
  }, []);

  return (
    <div className="sticky top-0 z-50 bg-gray-800 w-full left-0">
      <div className="w-full px-3 sm:px-4 md:px-6 lg:px-8 py-3 sm:py-4">
        {/* Desktop Layout */}
        <div className="hidden lg:flex justify-between items-center">
          <div className="flex items-center min-w-0">
            {showBackIcon && (
              <button
                onClick={handleBackClick}
                className="mr-3 text-white hover:text-gray-300 transition-colors flex-shrink-0"
                aria-label="Go back"
              >
                <ArrowLeft size={24} />
              </button>
            )}
            <h1 className="text-xl xl:text-2xl text-white font-semibold truncate">{title}</h1>
            {showUpgradeLink && (
              <div className="ml-5 hidden xl:flex items-center gap-3">
                <span className="text-sm font-light tracking-wide text-gray-400 whitespace-nowrap">
                  Explore Pro features
                </span>
                <span className="h-4 w-px bg-gray-600/70" aria-hidden="true" />
                <a
                  href="https://wptailwatch.com/pricing/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=pro_upgrade&utm_content=header_upgrade"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="group relative inline-flex items-center gap-1.5 overflow-hidden rounded-md bg-gradient-to-b from-amber-400 to-amber-600 px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wider !text-white hover:!text-white focus:!text-white !no-underline hover:!no-underline ring-1 ring-amber-700/40 transition-all duration-300 hover:from-amber-300 hover:to-amber-500"
                  style={{ boxShadow: 'inset 0 1px 0 0 rgba(255,255,255,0.35), 0 2px 8px rgba(245,158,11,0.35)' }}
                >
                  <span className="pointer-events-none absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/40 to-transparent transition-transform duration-700 group-hover:translate-x-full" aria-hidden="true" />
                  <Crown className="relative h-3 w-3 drop-shadow-sm" />
                  <span className="relative">Upgrade</span>
                  <ArrowRight className="relative h-3 w-3 transition-transform duration-300 group-hover:translate-x-0.5" />
                </a>
              </div>
            )}
          </div>

          <div className="flex items-center space-x-3 xl:space-x-4 flex-shrink-0">
            {showNotificationSwitch && (
              <label className="inline-flex items-center cursor-pointer">
                {loading ? (
                  <Skeleton width={40} height={25} />
                ) : (
                  <ToggleSwitcher
                    checked={notificationEnabled}
                    onChange={(e) => handleToggle(e.target.checked)}
                    disabled={false}
                  />
                )}
                <span className="ms-3 text-sm font-medium text-white whitespace-nowrap">
                  Enable Mobile Notifications
                </span>
              </label>
            )}

            {showIcon && setSearchQuery && (
              <div className="relative w-48 xl:w-64">
                <SearchBar searchQuery={searchQuery} setSearchQuery={setSearchQuery} />
              </div>
            )}
            {showIcon && (
              <div className="relative" ref={processDropdownRef}>
                <button onClick={toggleProcessDropdown} className="mb-1 flex items-center space-x-2 px-2 py-1 rounded-md border border-green-600 text-green-600 hover:text-white hover:bg-green-600" aria-label="Process monitoring">
                  <CircleCheckBig size={16} />
                  <span className="text-sm">Active Tasks</span>
                </button>
                {showProcessDropdown && (
                  <div className="absolute right-0 mt-2 w-96 bg-white border border-gray-200 rounded-lg shadow-lg p-3 min-h-36">
                    <div className="flex justify-between items-center mb-2">
                      <span className="text-gray-900 font-semibold text-sm">Active Tasks</span>
                      <button onClick={refreshProcessData} className="text-xs px-2 py-1 rounded border border-gray-300 bg-white text-gray-800 hover:bg-gray-50">Refresh</button>
                    </div>
                    {processLoading && (
                      <div className="flex items-center justify-center h-24 text-gray-500 text-sm"> 
                        Loading...
                      </div>
                    )}
                    {!processLoading && processData && processData.data && processData.data.active_processes && processData.data.active_processes.length === 0 && (
                      <div className="flex items-center justify-center h-24 text-gray-500 text-sm">
                        No active tasks
                      </div>
                    )}
                    {!processLoading && processData && processData.data && processData.data.active_processes && processData.data.active_processes.length > 0 && (
                      <ul className="space-y-2 max-h-80 overflow-auto">
                        {processData.data.active_processes.map((p) => (
                          <li key={p.id} className="bg-white rounded-md p-3 border border-gray-200">
                            <div className="flex justify-between">
                              <span className="text-gray-900 text-sm font-medium">{p.type_label}</span>
                              <span className={`text-xs px-2 py-0.5 rounded ${p.state === 'in_progress' ? 'bg-yellow-100 text-yellow-700' : p.state === 'completed' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>{p.state_label}</span>
                            </div>
                            <div className="text-gray-700 text-xs mt-1">State: {p.state}</div>
                            {p.started_at && (
                              <div className="text-gray-700 text-xs">Started: {p.started_at}</div>
                            )}
                            {p.time_running && (
                              <div className="text-gray-500 text-xs mt-1">Running: {p.time_running.formatted}</div>
                            )}
                            {p.last_heartbeat && (
                              <div className="text-gray-400 text-xs">Last heartbeat: {p.last_heartbeat}</div>
                            )}
                          </li>
                        ))}
                      </ul>
                    )}
                    {!processLoading && !processData && (
                      <div className="text-gray-700 text-sm">No data</div>
                    )}
                  </div>
                )}
              </div>
            )}
            {showIcon && (
              <div
                className="cursor-pointer flex-shrink-0"
                onMouseEnter={handleCardMouseEnter}
                onMouseLeave={handleCardMouseLeave}
                onClick={handlePhpIconClick}
              >
                <FileCode size={24} className="text-green-600 hover:text-green-500 transition-colors" />
              </div>
            )}

            <ProfileLog />
          </div>
        </div>

        {/* Tablet Layout (md to lg) */}
        <div className="hidden md:flex lg:hidden flex-col space-y-3">
          <div className="flex justify-between items-center">
            <div className="flex items-center min-w-0 flex-1">
              {showBackIcon && (
                <button
                  onClick={handleBackClick}
                  className="mr-2 text-white hover:text-gray-300 transition-colors flex-shrink-0"
                  aria-label="Go back"
                >
                  <ArrowLeft size={22} />
                </button>
              )}
              <h1 className="text-lg text-white font-semibold truncate">{title}</h1>
              {showUpgradeLink && (
                <a
                  href="https://wptailwatch.com/pricing/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=pro_upgrade&utm_content=header_upgrade"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="group relative ml-3 inline-flex items-center gap-1.5 overflow-hidden rounded-md bg-gradient-to-b from-amber-400 to-amber-600 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-wider !text-white hover:!text-white focus:!text-white !no-underline hover:!no-underline ring-1 ring-amber-700/40 transition-all duration-300 hover:from-amber-300 hover:to-amber-500 whitespace-nowrap"
                  style={{ boxShadow: 'inset 0 1px 0 0 rgba(255,255,255,0.35), 0 2px 8px rgba(245,158,11,0.35)' }}
                >
                  <span className="pointer-events-none absolute inset-0 -translate-x-full bg-gradient-to-r from-transparent via-white/40 to-transparent transition-transform duration-700 group-hover:translate-x-full" aria-hidden="true" />
                  <Crown className="relative h-3 w-3 drop-shadow-sm" />
                  <span className="relative">Upgrade</span>
                </a>
              )}
            </div>

            <div className="flex items-center space-x-2 flex-shrink-0">
              <ProfileLog />
            </div>
          </div>

          {/* Search bar always visible on tablet */}
          {setSearchQuery && (
            <div className="w-full">
              <SearchBar searchQuery={searchQuery} setSearchQuery={setSearchQuery} />
            </div>
          )}
        </div>

        {/* Mobile Layout (below md) */}
        <div className="flex md:hidden flex-col space-y-3">
          <div className="flex justify-between items-center">
            <div className="flex items-center min-w-0 flex-1">
              {showBackIcon && (
                <button
                  onClick={handleBackClick}
                  className="mr-2 text-white hover:text-gray-300 transition-colors flex-shrink-0"
                  aria-label="Go back"
                >
                  <ArrowLeft size={20} />
                </button>
              )}
              <h1 className="text-base sm:text-lg text-white font-semibold truncate">{title}</h1>
              {showUpgradeLink && (
                <a
                  href="https://wptailwatch.com/pricing/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=pro_upgrade&utm_content=header_upgrade"
                  target="_blank"
                  rel="noopener noreferrer"
                  className="group relative ml-2 inline-flex items-center gap-1 overflow-hidden rounded-md bg-gradient-to-b from-amber-400 to-amber-600 px-2 py-1 text-[10px] sm:text-[11px] font-semibold uppercase tracking-wider !text-white hover:!text-white focus:!text-white !no-underline hover:!no-underline ring-1 ring-amber-700/40 transition-all duration-300 whitespace-nowrap"
                  style={{ boxShadow: 'inset 0 1px 0 0 rgba(255,255,255,0.35), 0 2px 6px rgba(245,158,11,0.35)' }}
                >
                  <Crown className="relative h-3 w-3 drop-shadow-sm" />
                  <span className="relative">Upgrade</span>
                </a>
              )}
            </div>

            <div className="flex items-center space-x-2 flex-shrink-0">
              <ProfileLog />
            </div>
          </div>

          {/* Search bar always visible on mobile */}
          {setSearchQuery && (
            <div className="w-full">
              <SearchBar searchQuery={searchQuery} setSearchQuery={setSearchQuery} />
            </div>
          )}
        </div>

        {/* Mobile/Tablet Dropdown Menu - Only for other settings */}
        {showMobileMenu && (
          <div className="lg:hidden mt-3 pt-3 border-t border-gray-700 space-y-3">
            {showNotificationSwitch && (
              <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-white">
                  Mobile Notifications
                </span>
                {loading ? (
                  <Skeleton width={40} height={25} />
                ) : (
                  <ToggleSwitcher
                    checked={notificationEnabled}
                    onChange={(e) => handleToggle(e.target.checked)}
                    disabled={false}
                  />
                )}
              </div>
            )}

            <div className="flex items-center justify-between">
              <span className="text-sm font-medium text-white">System Info</span>
              <div
                className="cursor-pointer"
                onMouseEnter={handleCardMouseEnter}
                onMouseLeave={handleCardMouseLeave}
                onClick={handlePhpIconClick}
              >
                <FileCode size={24} className="text-green-600 hover:text-green-500 transition-colors" />
              </div>
            </div>
          </div>
        )}
      </div>

      {/* Account suspension banner — persistent across pages while plan is suspended */}
      {isAccountSuspended && (
        <div className="bg-red-50 border-l-4 border-red-500 px-3 sm:px-4 md:px-6 lg:px-8 py-2.5 shadow-sm">
          <div className="flex items-start gap-3">
            <AlertTriangle className="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" aria-hidden="true" />
            <div className="flex-1 min-w-0">
              <p className="text-sm font-semibold text-red-800 leading-tight">
                Account Suspended
              </p>
              {suspensionReason && (
                <p className="text-xs text-red-700 mt-0.5 leading-relaxed">
                  {suspensionReason}
                </p>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Payment due banner — plan period has ended but account isn't suspended yet */}
      {isPaymentDue && (
        <div className="bg-amber-50 border-l-4 border-amber-500 px-3 sm:px-4 md:px-6 lg:px-8 py-2.5 shadow-sm">
          <div className="flex items-start gap-3">
            <AlertTriangle className="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" aria-hidden="true" />
            <div className="flex-1 min-w-0">
              <p className="text-sm font-semibold text-amber-800 leading-tight">
                Payment Due — Action Required
              </p>
              <p className="text-xs text-amber-700 mt-0.5 leading-relaxed">
                Your billing cycle ended on {formattedEndDate} and a pending invoice is awaiting payment. Please settle it to keep your premium features active. If left unpaid, your account will be suspended and access will be limited to the Basic plan.
              </p>
            </div>
          </div>
        </div>
      )}

      {showSystemInfoModal && (
        <SystemInfo
          section="php_configuration"
          setShowModal={setShowSystemInfoModal}
          handleCloseSystemInfoModal={handleCloseSystemInfoModal}
          loading={false}
        />
      )}
    </div>
  );
};

export default Header;