import { useRef, useState } from 'react';
import { Activity, MailCheck, Network, MailX, Lock, LockKeyhole } from 'lucide-react';
import ActionButton from '../../../Components/Buttons/ActionButton';
import { instantVerifySsl } from '../HomeServices/HomeServices';
import { alertService } from '../../../Components/AlertService/AlertService';
import { toast } from 'react-toastify';
import { MdUpdate } from "react-icons/md";

const Widgets = ({ widgetData, verifySslData, verifySslDetail, setVerifysslData, setLoadingVerify, sslIsDisabled, setIsDisabled }) => {
  const lordIconRef = useRef();
  const [loadingVerify, setLoadingVerifyState] = useState(false);

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

  const handleInstantVerify = async () => {
    const confirmed = await alertService.verify("Verify SSL", "Are you sure to verify?");
    if (!confirmed) {
      return;
    }
    await instantVerifySsl(setLoadingVerifyState, verifySslDetail, setVerifysslData, setLoadingVerify, setIsDisabled);
    toast.success("SSL Verification run successfully.");
  };

  const getWidgetIcon = (title) => {
    if (!title) return null;

    const titleLower = title.toLowerCase();

    if (titleLower.includes('logs activity')) {
      return <Activity size={24} className="text-[#007980]" />;
    } else if (titleLower.includes('email logs')) {
      return <MailCheck size={24} className="text-[#007980]" />;
    } else if (titleLower.includes('network logs')) {
      return <Network size={24} className="text-[#007980]" />;
    } else if (titleLower.includes('error logs')) {
      return <MailX size={24} className="text-[#007980]" />;
    } else {
      // Default icon
      return <Activity size={24} className="text-[#007980]" />;
    }
  };

  // Function to determine gradient background based on widget type and status
  const getBackgroundGradient = (title, isDisabled) => {
    if (isDisabled) return "bg-gradient-to-br from-gray-50 to-gray-100";

    if (!title) return "bg-gradient-to-br from-blue-50 to-[#85cbcf]";

    const titleLower = title.toLowerCase();

    if (titleLower.includes('logs activity')) {
      return "bg-gradient-to-br from-blue-50 to-[#85cbcf]";
    } else if (titleLower.includes('email logs')) {
      return "bg-gradient-to-br from-blue-50 to-[#85cbcf]";
    } else if (titleLower.includes('ajax logs')) {
      return "bg-gradient-to-br from-blue-50 to-[#85cbcf]";
    } else if (titleLower.includes('monitoring logs')) {
      return "bg-gradient-to-br from-blue-50 to-[#85cbcf]";
    } else {
      return "bg-gradient-to-br from-blue-50 to-[#85cbcf]";
    }
  };

  // Function to determine border color based on widget type and status
  const getBorderColor = (title, isDisabled) => {
    if (isDisabled) return "border-gray-300";

    if (!title) return "border-gray-300";

    const titleLower = title.toLowerCase();

    if (titleLower.includes('logs activity')) {
      return "border-[#85cbcf]";
    } else if (titleLower.includes('email logs')) {
      return "border-[#85cbcf]";
    } else if (titleLower.includes('ajax logs')) {
      return "border-[#85cbcf]";
    } else if (titleLower.includes('monitoring logs')) {
      return "border-[#85cbcf]";
    } else {
      return "border-teal-200";
    }
  };

  // Check if feature is disabled based on message
  const isFeatureDisabled = (widget) => {

    return widget.message && widget.message.toLowerCase().includes('not enabled');
  };

  // Prepare SSL data
  const sslFeature = verifySslData && verifySslData[0] ? verifySslData[0] : null;
  const sslDetails = sslFeature?.details || {};
  const sslLastRun = sslDetails.last_run || "N/A";

  const getSslStatus = () => {
    if (!sslDetails.Ssl_Connection) return { color: "gray", text: "Unknown" };
    if (sslDetails.Ssl_Connection === "Connected") return { color: "green", text: "Connected" };
    if (sslDetails.Ssl_Connection === "Disconnected") return { color: "red", text: "Disconnected" };
    return { color: "yellow", text: "Needs Verification" };
  };

  return (
    <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-6 gap-4">
      {widgetData.map((widget, index) => {
        const isDisabled = isFeatureDisabled(widget);

        return (
          <div key={index} className={`relative rounded-lg border transition-all ${getBorderColor(widget.title, isDisabled)} ${getBackgroundGradient(widget.title, isDisabled)} ${isDisabled ? 'opacity-75' : ''}`}
            onMouseEnter={handleCardMouseEnter}
            onMouseLeave={handleCardMouseLeave}
          >
            {/* Status badge - smaller */}
            <div className="relative">
              <div className={`absolute top-0 right-0 py-1 px-3 text-xs font-medium text-white rounded-bl-lg rounded-tr-lg ${isDisabled ? 'bg-gray-500' : 'bg-[#007980]'}`}>
                {isDisabled ? 'Inactive' : 'Active'}
              </div>
            </div>

            <div className="p-4">
              {/* Header - compact */}
              <div className="flex items-center gap-2 mb-3">
                <div className={`rounded-full px-1 ${isDisabled ? 'bg-gray-200' : 'bg-[#07C07E1A]'
                  }`}>
                  {isDisabled ?
                    <Lock size={28} className="text-gray-500" /> :
                    getWidgetIcon(widget.title)
                  }

                </div>
                <h3 className="text-sm font-semibold text-gray-900 truncate">
                  {widget.title}
                </h3>
              </div>

              {/* Content - minimal */}
              {widget.count !== null && widget.count !== undefined ? (
                <div className="space-y-0.5">
                  <div className="text-2xl font-semibold text-[#007980]">
                    {widget.count.toLocaleString()}
                  </div>
                  <div className="text-sm text-black">total records</div>
                </div>
              ) : (
                <div className="text-sm text-black">{widget.message}</div>
              )}
            </div>
          </div>
        );
      })}

      {/* SSL Verification Card - Always Show */}
      <div
        className={`col-span-1 md:col-span-2 lg:col-span-2 rounded-xl shadow-lg overflow-hidden transition-all duration-300 hover:shadow-xl flex flex-col border ${sslIsDisabled ? 'border-gray-300 bg-gradient-to-br from-gray-50 to-gray-100 opacity-75' : 'border-[#85cbcf] bg-gradient-to-br from-blue-50 to-[#85cbcf]'}`}
        onMouseEnter={handleCardMouseEnter}
        onMouseLeave={handleCardMouseLeave}
      >
        <div className="relative">
          <div className={`absolute top-0 right-0 py-1 px-3 text-xs font-medium text-white rounded-bl-lg ${sslIsDisabled ? 'bg-gray-500' : 'bg-[#007980]'}`}>
            {sslIsDisabled ? 'Inactive' : 'Active'}
          </div>
        </div>

        <div className="p-4 flex flex-col h-full">
          {sslIsDisabled ? (
            // Disabled State
            <>
              <div className="flex items-center mb-3">
                <div className="w-9 h-9 rounded-full flex items-center justify-center mr-3 bg-gray-200">
                  <Lock size={24} className="text-gray-500" />
                </div>
                <h2 className="text-sm font-semibold text-gray-900">SSL Verification</h2>
              </div>
              <p className="text-sm text-black ">This feature is not enabled</p>
            </>
          ) : (
            // Active State
            <>
              <div className="flex items-center gap-2 mb-3">
                <div className="flex items-center">
                  <div className="w-9 h-9 rounded-full flex items-center justify-center mr-3 bg-[#07C07E1A]">
                    <LockKeyhole size={24} className="text-[#007980]" />
                  </div>
                  <h2 className="text-sm font-semibold text-gray-900">SSL Verification</h2>
                </div>

                {/* Circular Verify Button */}
                <button
                  onClick={handleInstantVerify}
                  disabled={loadingVerify}
                  className={` ${loadingVerify
                    ? 'bg-gray-300 cursor-not-allowed'
                    : ''
                    }`}
                  title={loadingVerify ? "Verifying..." : "Verify SSL"}
                >
                  {loadingVerify ? (
                    <svg className="animate-spin h-4 w-4 text-[#007980]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                      <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                      <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                  ) : (
                    <svg className="w-4 h-4 text-[#007980]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                  )}
                </button>
              </div>

              {/* SSL Details */}
              <div className="space-y-1.5 text-sm flex-grow">
                <div className="flex justify-between items-center">
                  <span className="text-black">SSL Connection:</span>
                  <span className={`font-semibold ${getSslStatus().color === 'green' ? 'text-green-600' : getSslStatus().color === 'red' ? 'text-red-600' : 'text-amber-600'}`}>
                    {getSslStatus().text}
                  </span>
                </div>
                <div className="flex justify-between items-center">
                  <span className="text-black">HTTPS Redirect:</span>
                  <span className="text-black">{sslDetails.Https_Redirect || '--'}</span>
                </div>
                <div className="flex justify-between items-center">
                  <span className="text-black">Expiry Time:</span>
                  <span className="text-black">{sslDetails.Expiry_Time || '--'}</span>
                </div>
              </div>
            </>
          )}
        </div>

        <div className={`${sslIsDisabled ? "h-2 w-full bg-gradient-to-r from-gray-600 to-gray-300" : ''}`}></div>
      </div>
    </div>
  );
};

export default Widgets;
