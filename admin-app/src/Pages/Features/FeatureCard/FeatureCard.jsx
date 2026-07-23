import { memo } from 'react';
import Skeleton from 'react-loading-skeleton';
import 'react-loading-skeleton/dist/skeleton.css';
import ToggleSwitch from '../../../Components/ToggleSwitcher/ToggleSwitch';
import ActionButton from '../../../Components/Buttons/ActionButton';
import NotificationButton from './NotificationButton';
import FeatureIconMapper from './FeatureIconMapper';
import { bgprimary, bgdarkGray, lightBorder, primaryGradient, secondaryGradient, inactiveGradient, secondaryGreen, orangeBorder, secondaryBorder, alertOrange } from '../../../Components/Utils/Colors/Colors';
import { useRef, useState, useEffect } from 'react';
import { useFeaturesData } from '../../../Components/Context/FeaturesDataContext';
import { resolveIsLocked } from '../../../Components/Utils/HelperFunctions/LockHelper';
import { Crown, Sparkles, ExternalLink } from 'lucide-react';

const FeatureCard = ({ feature, isLoading, featureLicenseData, loadingFeature, notificationEnabled, handleToggleChange, handleShowCanvas, handleOpenModal }) => {
  const lordIconRef = useRef();
  const descriptionRef = useRef();
  const [isExpanded, setIsExpanded] = useState(false);
  const [showReadMore, setShowReadMore] = useState(false);
  const [iconLoading, setIconLoading] = useState(true);
  const { proPluginActive } = useFeaturesData();

  const isActive = feature.is_active === "1";
  const isLoadingState = isLoading || loadingFeature === feature.id;
  const featureValue = JSON.parse(feature.value);
  const isPro = featureValue.feature_type === "pro";
  const isLocked = resolveIsLocked(featureValue, proPluginActive);
  const isComingSoon = featureValue.comming_soon === true;
  const isUpgradeFeature = featureValue.is_upgrade_feature === true;
  // Determine column span based on feature.value.col
  let colSpanClass = "";
  if (featureValue.col === "2") {
    colSpanClass = "lg:col-span-2";
  } else if (featureValue.col === "3") {
    colSpanClass = "lg:col-span-3";
  } else if (featureValue.col === "4") {
    colSpanClass = "lg:col-span-4";
  }

  // Determine card styles based on locked/active status
  const cardBgClass = isLocked || isUpgradeFeature ? secondaryGradient : isPro ? primaryGradient : isActive ? primaryGradient : inactiveGradient;

  const borderClass = isLocked || isUpgradeFeature ? orangeBorder : isPro ? `border-[${secondaryGreen}]` : isActive ? `border-[${secondaryGreen}]` : secondaryBorder;

  const licenseStatus = featureLicenseData?.connection;
  const planName = featureLicenseData?.planName;
  const isSuspended = featureLicenseData?.planStatus === "suspended";
  const suspensionReason = featureLicenseData?.suspension_reason || featureLicenseData?.suspensionReason;

  // Badge styles
  const badgeColor = isLocked || isUpgradeFeature ? alertOrange : isPro ? bgprimary : isActive ? bgprimary : bgdarkGray;

  const badgeText = isUpgradeFeature
    ? "Upgrade"
    : (isLocked && isSuspended)
      ? "Upgrade"
      : (featureValue.is_locked && licenseStatus === "killed")
        ? "Upgrade"
        : (featureValue.is_locked && licenseStatus === "live" && planName === "Basic")
          ? "Upgrade"
          : (featureValue.is_locked && licenseStatus === "live" && planName !== "Basic")
            ? "Install Pro Plugin"
            : (featureValue.is_locked && proPluginActive === false)
              ? "Install Pro Plugin"
              : featureValue.is_locked
                ? "Upgrade"
                : (isActive ? "Active" : "Inactive");

  // Notification button is disabled when the license isn't connected (mobile notifications
  // require a connected license) and when a pro feature is locked because of license/plan/
  // pro-plugin state. The tooltip mirrors the badge wording.
  let notificationLockReason = null;
  if (isSuspended) {
    notificationLockReason = "Upgrade";
  } else if (featureLicenseData?.connection_status === false) {
    notificationLockReason = "Connect to Tailwatch";
  }  else if (featureValue.is_locked) {
    if (proPluginActive === false) {
      notificationLockReason = "Install Pro Plugin";
    } else if (planName === "Basic") {
      notificationLockReason = "Upgrade";
    } else {
      notificationLockReason = "Upgrade";
    }
  }

  const handleCardMouseEnter = () => {
    if (lordIconRef.current && !isLoadingState) {
      lordIconRef.current.play();
    }
  };

  const handleCardMouseLeave = () => {
    if (lordIconRef.current && !isLoadingState) {
      lordIconRef.current.stop();
    }
  };

  const toggleExpanded = () => {
    setIsExpanded(!isExpanded);
  };

  const handleIconReady = () => {
    setIconLoading(false);
  };

  // Reset icon loading state when loading state changes
  useEffect(() => {
    if (isLoadingState) {
      setIconLoading(true);
    }
  }, [isLoadingState]);

  // Check if text is truncated
  useEffect(() => {
    if (descriptionRef.current && !isLoadingState) {
      const element = descriptionRef.current;
      const isTruncated = element.scrollHeight > element.clientHeight;
      setShowReadMore(isTruncated);
    }
  }, [featureValue.description, isLoadingState]);

  if (isComingSoon) {
    return (
      <div
        key={feature.id}
        className={`relative rounded-xl shadow-md border border-[#85cbcf]/50 overflow-hidden transition-all duration-300 hover:shadow-xl flex flex-col bg-gradient-to-br from-[#f0fbfb] via-white to-[#e6f7f7] ${colSpanClass}`}
        onMouseEnter={handleCardMouseEnter}
        onMouseLeave={handleCardMouseLeave}
      >
        <div className="pointer-events-none absolute inset-0 overflow-hidden">
          <div className="absolute -top-10 -right-10 h-32 w-32 rounded-full bg-[#85cbcf] opacity-20 blur-2xl"></div>
          <div className="absolute -bottom-10 -left-10 h-32 w-32 rounded-full bg-[#007980] opacity-15 blur-2xl"></div>
        </div>

        <div className="relative">
          <div className="absolute top-0 right-0 py-1 px-3 text-xs font-semibold text-white rounded-bl-lg flex items-center gap-1 bg-gradient-to-r from-[#007980] to-[#0a9aa3] shadow-sm">
            <Sparkles size={11} />
            Coming Soon
          </div>
        </div>

        <div className="relative p-4 sm:p-5 lg:p-6 flex flex-col h-full">
          <div className="flex items-center mb-1 sm:mb-1">
            <div className="rounded-full flex items-center justify-center mr-2 sm:mr-3">
              {isLoadingState || iconLoading ? (
                <Skeleton circle width={32} height={32} className="sm:!w-[38px] sm:!h-[38px]" />
              ) : null}
              {!isLoadingState && (
                <div style={{ display: iconLoading ? 'none' : 'block' }}>
                  <FeatureIconMapper ref={lordIconRef} title={featureValue.title} isActive={isActive} isPro={isPro} isLocked={isLocked} size={25} colorClass={(isUpgradeFeature || isLocked) ? 'text-orange-500' : 'text-[#007980]'} onReady={handleIconReady} />
                </div>
              )}
            </div>
            <h2 className="text-base sm:text-lg font-semibold text-black">
              {isLoadingState ? <Skeleton width={100} /> : featureValue.title}
            </h2>
          </div>

          <div className="mb-2 sm:mb-2 flex-grow">
            {isLoadingState ? (
              <Skeleton count={3} />
            ) : (
              <>
                <p ref={descriptionRef} className={`!text-black text-sm sm:text-base ${!isExpanded ? '!line-clamp-3' : ''}`}>
                  {featureValue.description}
                </p>
                {showReadMore && (
                  <button
                    onClick={toggleExpanded}
                    className="text-xs sm:text-sm font-medium text-gray-600 underline underline-offset-2 hover:text-gray-800 mt-1 focus:outline-none transition-colors"
                  >
                    {isExpanded ? 'Read less' : 'Read more'}
                  </button>
                )}
              </>
            )}
          </div>

          <div className="mt-auto pt-3 sm:pt-4 border-t border-[#85cbcf]/40">
            <a
              href="https://wptailwatch.com/roadmap?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=free&utm_content=feature_card_roadmap"
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-2 px-4 py-2 rounded-lg text-xs sm:text-sm font-semibold !text-white hover:!text-white focus:!text-white !no-underline hover:!no-underline bg-gradient-to-r from-[#007980] to-[#0a9aa3] hover:from-[#006670] hover:to-[#088d97] shadow-md hover:shadow-lg transition-all duration-300"
            >
              <ExternalLink className="w-4 h-4" />
              View Roadmap
            </a>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div key={feature.id} className={`rounded-xl shadow-lg border ${borderClass} overflow-hidden transition-all duration-300 hover:shadow-xl flex flex-col ${cardBgClass} ${colSpanClass}`} onMouseEnter={handleCardMouseEnter} onMouseLeave={handleCardMouseLeave}>
      <div className="relative">
        <div className={`absolute top-0 right-0 py-1 px-3 text-xs font-medium text-white rounded-bl-lg flex items-center gap-1 ${badgeColor}`}>
          {featureValue?.is_locked || isUpgradeFeature ? <Crown size={11} /> : null}
          {badgeText}
        </div>
      </div>

      <div className="p-4 sm:p-5 lg:p-6 flex flex-col h-full">
        {/* Title area with icon */}
        <div className="flex items-center mb-1 sm:mb-1">
          <div className="rounded-full flex items-center justify-center mr-2 sm:mr-3">
            {isLoadingState || iconLoading ? (
              <Skeleton circle width={32} height={32} className="sm:!w-[38px] sm:!h-[38px]" />
            ) : null}
            {!isLoadingState && (
              <div style={{ display: iconLoading ? 'none' : 'block' }}>
                <FeatureIconMapper ref={lordIconRef} title={featureValue.title} isActive={isActive} isPro={isPro} isLocked={isLocked} size={25} colorClass={(isUpgradeFeature || isLocked) ? 'text-orange-500' : 'text-[#007980]'} onReady={handleIconReady} />
              </div>
            )}
          </div>
          <h2 className="text-base sm:text-lg font-semibold text-black">
            {isLoadingState ? <Skeleton width={100} /> : featureValue.title}
          </h2>
        </div>

        {/* Description with Read More/Less functionality */}
        <div className="mb-2 sm:mb-2 flex-grow">
          {isLoadingState ? (
            <Skeleton count={3} />
          ) : (
            <>
              <p ref={descriptionRef} className={`!text-black text-sm sm:text-base ${!isExpanded ? '!line-clamp-3' : ''}`}>
                {featureValue.description}
              </p>
              {showReadMore && (
                <button
                  onClick={toggleExpanded}
                  className="text-xs sm:text-sm font-medium text-gray-600 underline underline-offset-2 hover:text-gray-800 mt-1 focus:outline-none transition-colors"
                >
                  {isExpanded ? 'Read less' : 'Read more'}
                </button>
              )}
            </>
          )}
        </div>

        {/* Footer with toggle and buttons */}
        {isUpgradeFeature ? (
          <div className={`flex items-center justify-start mt-auto pt-3 sm:pt-4 border-t border-t border-amber-200`}>
            {isLoadingState ? (
              <Skeleton width={120} height={36} />
            ) : (
              <a
                href="https://wptailwatch.com/pricing/?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=pro_upgrade&utm_content=feature_card_upgrade"
                target="_blank"
                rel="noopener noreferrer"
                className="inline-flex items-center gap-1.5 rounded-lg bg-gradient-to-b from-amber-400 to-amber-600 px-4 py-2 text-xs sm:text-sm font-semibold uppercase tracking-wider !text-white hover:!text-white focus:!text-white !no-underline hover:!no-underline transition-colors duration-200 hover:from-amber-500 hover:to-amber-700"
              >
                <Crown className="h-3.5 w-3.5" />
                <span>Upgrade</span>
              </a>
            )}
          </div>
        ) : (
          <div className={`flex items-center justify-between mt-auto pt-3 sm:pt-4 ${isActive ? `border-t border-[${secondaryGreen}]` : isLocked ? "border-t border-amber-200" : isPro ? `border-t border-[${secondaryGreen}]` : `border-t ${lightBorder}`} `}>
            <label className="relative flex-shrink-0">
              {isLoadingState ? (
                <Skeleton width={44} height={22} />
              ) : (
                <ToggleSwitch checked={featureValue.is_locked && proPluginActive === false ? false : isActive} onChange={() => handleToggleChange(feature.id, feature.is_active)} disabled={isLocked || loadingFeature !== null || (featureValue.is_locked && proPluginActive === false)} />
              )}
            </label>

            <div className="flex items-center space-x-2 sm:space-x-3">
              {featureValue.mobile_notification === true && (
                <NotificationButton
                  isLoading={isLoadingState}
                  notificationEnabled={notificationEnabled}
                  handleOpenModal={handleOpenModal}
                  featureId={feature.id}
                  feature={feature}
                  forceDisabled={notificationLockReason !== null}
                  forceTooltip={notificationLockReason}
                />
              )}

              {isLoadingState ? (
                <Skeleton width={80} height={32} />
              ) : (
                <ActionButton defaultText="Configure" onClick={() => handleShowCanvas(feature.id)} isDisabled={!isActive || loadingFeature !== null || (featureValue.is_locked && proPluginActive === false)} className={`!py-1.5 sm:!py-2 !px-3 sm:!px-4 !rounded-lg !font-medium !transition-all !text-xs sm:!text-sm ${isActive && !(featureValue.is_locked && proPluginActive === false) ? isPro ? "bg-[#007980] " : "!bg-[#007980]" : "!bg-[#e5e7eb] !text-[#9ca3af]"}`} />
              )}
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default memo(FeatureCard);