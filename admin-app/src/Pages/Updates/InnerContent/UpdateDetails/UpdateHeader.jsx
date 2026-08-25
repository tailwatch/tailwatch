import React from 'react';
import { CiImageOff } from 'react-icons/ci';
import { ArrowUpCircle, CheckCircle, XCircle } from 'lucide-react';
import DOMPurify from 'dompurify';

const UpdateHeader = ({ pluginDetails, pluginSlug }) => {
  const fallbackName = pluginSlug?.includes("theme") ? "Theme Name" : "Plugin Name";
  const displayName = pluginDetails?.name || fallbackName;   
  const isActive = pluginDetails?.is_active === true;

  return (
    <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-3 sm:gap-4 mb-4 sm:mb-6 bg-gradient-to-br from-blue-50 to-[#85cbcf] border border-[#85cbcf] p-3 sm:p-4 rounded-lg shadow-sm">

      <div className="flex items-center space-x-3 sm:space-x-4">
        {/* Image */}
        {pluginDetails?.icon ? (
          <img
            src={pluginDetails.icon}
            alt="plugin-logo"
            className="w-12 h-12 sm:w-16 sm:h-16 rounded-lg shadow-md object-cover flex-shrink-0"
          />
        ) : pluginDetails?.screenshot_url ? (
          <img
            src={pluginDetails.screenshot_url}
            alt="plugin-screenshot"
            className="w-12 h-12 sm:w-16 sm:h-16 rounded-lg shadow-md object-cover flex-shrink-0"
          />
        ) : (
          <div className="w-12 h-12 sm:w-16 sm:h-16 bg-gray-200 rounded-lg shadow-md flex items-center justify-center flex-shrink-0">
            <CiImageOff className="w-6 h-6 sm:w-8 sm:h-8 text-gray-400" />
          </div>
        )}

        {/* Name + Version + Status */}
        <div className="min-w-0">
          <div
            className="text-lg sm:text-xl lg:text-2xl font-semibold text-gray-800 truncate"
            dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(displayName) }}
          />

          <div className="flex flex-wrap items-center gap-2 sm:gap-3 mt-1">
            {pluginDetails?.current_version && (
              <span className="text-xs sm:text-sm text-gray-600">
                Version: <span className="font-medium">{pluginDetails.current_version}</span>
              </span>
            )}

            {/* Active / Deactivated Badge */}
            {pluginDetails?.name !== "WordPress" &&
              <span
                className={`inline-flex items-center px-2 sm:px-2.5 py-0.5 sm:py-1 rounded-full text-[10px] sm:text-xs font-medium
                ${isActive
                    ? 'bg-green-100 text-green-700'
                    : 'bg-red-100 text-red-700'
                  }`}
              >
                {isActive ? (
                  <>
                    <CheckCircle className="w-3 h-3 sm:w-3.5 sm:h-3.5 mr-1" />
                    Active
                  </>
                ) : (
                  <>
                    <XCircle className="w-3 h-3 sm:w-3.5 sm:h-3.5 mr-1" />
                    Deactivated
                  </>
                )}
              </span>
            }
          </div>
        </div>
      </div>

      {/* Update Available */}
      {pluginDetails?.update_available && (
        <div className="flex items-center bg-blue-50 text-orange-600 px-2 sm:px-3 py-1.5 sm:py-2 rounded-lg text-xs sm:text-sm w-full sm:w-auto justify-center sm:justify-start">
          <ArrowUpCircle className="w-4 h-4 sm:w-5 sm:h-5 mr-1.5 sm:mr-2 flex-shrink-0" />
          <span className="font-medium">
            Update Available: {pluginDetails.update_version}
          </span>
        </div>
      )}
    </div>
  );
};

export default UpdateHeader;
