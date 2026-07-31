import { useState, useEffect } from "react";
import ToggleSwitch from "../ToggleSwitcher/ToggleSwitch";
import { Lock, Settings, ArrowRight, Loader2 } from "lucide-react";
import IconButton from "../Buttons/IconButton";
import { useFeatureContent } from "../Hooks/useFeatureContent/useFeatureContent";
import {
  handleConnect,
} from "../../Pages/Settings/LicenseTab/LicenseTabServices/LicenseTabServices";
import { useFeaturesData } from "../Context/FeaturesDataContext";
/* global wptw_ajax */

const LockCard = ({
  featureId,
  isActive: initialIsActive,
  isLocked,
  afterToggleCallback,
  showOverlay = true,
  featureName,
  featureDescription,
}) => {
  const [isActive, setIsActive] = useState(initialIsActive);
  const { handleToggleChange, loadingFeature } = useFeatureContent();
  const { fetchFeaturesData } = useFeaturesData();
  const [loading, setLoading] = useState(false);
  const [connecting, setConnecting] = useState(false);

  useEffect(() => {
    setIsActive(initialIsActive);
  }, [initialIsActive]);

  const isLoading = loadingFeature === featureId || loading;
  const isActiveBoolean = isActive === "1";

  const handleToggle = async () => {
    const previousIsActive = isActive;
    const nextIsActive = previousIsActive === "1" ? "0" : "1";
    // Optimistic flip — UI reflects the intended state immediately…
    setIsActive(nextIsActive);
    try {
      // …and we await the actual toggle so we can roll back if the backend rejects.
      await handleToggleChange(featureId, previousIsActive, afterToggleCallback);
    } catch {
      setIsActive(previousIsActive);
    }
  };

  const handleActivateLicense = async () => {
    setConnecting(true);
    try {
      await handleConnect({
        setLoading: setLoading,
        wptw_ajax,
        successCallback: async () => {
          await fetchFeaturesData();
          if (typeof afterToggleCallback === "function") {
            await afterToggleCallback();
          }
        },
      });
    } finally {
      setConnecting(false);
    }
  };

  const glowColor = "#007980";
  const overlayClasses = `absolute inset-0 flex items-center justify-center backdrop-blur-sm ${
    showOverlay ? "bg-gray-900 bg-opacity-60 " : ""
  } z-[40] px-4 sm:px-6`;

  return (
        <div className={overlayClasses}>
      <div
        className="relative w-full max-w-lg overflow-hidden rounded-lg sm:rounded-xl shadow-2xl transform transition-all mx-auto "
        style={{
          boxShadow: `0 0 20px 6px ${glowColor}30, 0 0 40px 8px ${glowColor}15`,
          border: `2px solid ${glowColor}40`,
          transition:
            "all 0.5s ease-in-out, opacity 0.3s ease-in-out, transform 0.3s ease-in-out",
        }}
      >
        <div className="absolute inset-0 bg-gradient-to-br from-blue-50 to-[#85cbcf] opacity-60"></div>
        <div className="relative bg-white backdrop-blur-sm p-4 sm:p-5 md:p-6 rounded-lg sm:rounded-xl border border-white border-opacity-30 shadow-lg">
          {/* Decorative elements - hidden on mobile for cleaner look */}
          <div className="absolute -top-8 -right-8 w-16 h-16 sm:w-32 sm:h-32 bg-[#007980] opacity-10 rounded-full hidden sm:block"></div>

          {/* Icon section */}
          <div className="flex justify-center mb-3 sm:mb-2 ">
            <Lock size={36} className="sm:w-10 sm:h-10 text-[#007980]" />
          </div>

          {/* Title */}
          <h3 className="text-base sm:text-lg font-semibold text-center bg-clip-text text-transparent bg-gradient-to-r from-[#007980] to-[#006066] mb-2 px-2">
            {isLocked ? "Tailwatch Pro Feature" : "Action Required"}
          </h3>

          {/* Description */}
          <p className="text-xs sm:text-sm text-gray-600 text-center mb-4 sm:mb-5 px-2 py-1">
            {isLocked
              ? "This is a Tailwatch Pro feature. Connect your license to enable it."
              : "This feature cannot be used until the required options are enabled."}
          </p>

          {/* Main content card */}
          <div className="mt-3 sm:mt-4">
            <div className="bg-gray-50 shadow-sm rounded-lg p-2 sm:p-3 md:p-4 border border-gray-200 space-y-2 sm:space-y-3">
              {isLocked ? (
                <div className="flex items-center py-2 sm:py-3 px-1 sm:px-2">
                  <div className="p-1 sm:p-1.5 rounded-md bg-[#007980] bg-opacity-10 mr-2 sm:mr-3 flex-shrink-0">
                    <Settings className="h-3.5 w-3.5 sm:h-4 sm:w-4 text-[#007980]" />
                  </div>
                  <div className="flex-1 min-w-0">
                    <span className="block text-xs sm:text-sm font-semibold text-gray-800 truncate">
                      Pro Feature
                    </span>
                  </div>
                  <div className="ml-2 sm:ml-4 p-1 sm:p-1.5 rounded-full bg-yellow-50 border border-yellow-200 flex-shrink-0">
                    <Lock className="h-3 w-3 sm:h-4 sm:w-4 text-yellow-500" />
                  </div>
                </div>
              ) : (
                <div className="flex justify-between items-center py-2 sm:py-2.5 px-2 sm:px-3 bg-white rounded-lg gap-2">
                  <div className="flex items-center flex-1 min-w-0">
                    <div className="p-1 sm:p-1.5 rounded-md bg-[#007980] bg-opacity-10 mr-2 sm:mr-3 flex-shrink-0">
                      <Settings className="h-3.5 w-3.5 sm:h-4 sm:w-4 text-[#007980]" />
                    </div>
                    <div className="flex-1 min-w-0">
                      <span className="block text-xs sm:text-sm font-semibold text-gray-900">
                        Enable {featureName}
                      </span>
                      <span className="block text-[10px] sm:text-xs text-gray-500 mt-0.5 whitespace-normal break-words">
                       {featureDescription}
                      </span>
                    </div>
                  </div>

                    <ToggleSwitch
                      checked={isActiveBoolean}
                      onChange={handleToggle}
                      disabled={isLoading}
                      id="feature-toggle"
                      skipLoading={true}
                    />
                </div>
              )}
            </div>
          </div>

          {/* Action button */}
          <div className="mt-4 sm:mt-5 flex justify-center px-2">
            {isLocked && (
              <IconButton
                icon={connecting ? Loader2 : ArrowRight}
                text={connecting ? "Connecting..." : "Activate License"}
                onClick={handleActivateLicense}
                disabled={connecting}
                bgColor="bg-gradient-to-r from-[#007980] to-[#00a0aa]"
                hoverBgColor="hover:from-[#006970] hover:to-[#008a93]"
                textColor="text-white"
                className={`shadow-md hover:shadow-lg transition-all duration-200 text-xs sm:text-sm w-full sm:w-auto ${
                  connecting ? "[&>svg]:animate-spin" : ""
                }`}
              />
            )}
          </div>
          {/* Loading overlay */}
          {isLoading && (
            <div className="absolute inset-0 flex items-center justify-center bg-white bg-opacity-50 rounded-xl sm:rounded-2xl">
              <div className="p-2 sm:p-3 rounded-full bg-white shadow-lg">
                <Loader2 className="h-5 w-5 sm:h-6 sm:w-6 text-[#007980] animate-spin" />
              </div>
            </div>
          )}

          {/* Footer text */}
          <p className=" text-center text-[10px] sm:text-xs text-gray-500 px-2">
            {isLocked
              ? "Need help? Contact support for assistance with activation."
              : "You can modify these settings at any time."}
          </p>
        </div>
      </div>
    </div>
  );
};

export default LockCard;
