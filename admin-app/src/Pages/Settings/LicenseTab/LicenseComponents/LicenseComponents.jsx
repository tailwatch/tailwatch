import React, { useState } from 'react';
import ActionButton from '../../../../Components/Buttons/ActionButton';
import { CheckCircle, AlertTriangle, Clock, XCircle, Smartphone, Calendar, Mail, Key, X } from "lucide-react";
import { Star } from "lucide-react";
import IconButton from "../../../../Components/Buttons/IconButton";
import { Eye, EyeOff, Copy } from "lucide-react";

export const ConnectedLicenseView = ({ email, startData, endData, isExpiry, licenseKey, connectionDateTime, planName, devices, role, onDisconnect }) => {
  const isSuspended = role === 'suspended';

  const handleUpgrade = () => {
    window.open("https://dashboard.wptailwatch.com/signin", "_blank", "noopener,noreferrer");
  };

  const handleReactivate = () => {
    // Opens the dashboard so the user can settle outstanding payment / reactivate their plan.
    window.open("https://dashboard.wptailwatch.com/billing", "_blank", "noopener,noreferrer");
  };

    const [show, setShow] = useState(false);
  const [copied, setCopied] = useState(false);

  const formatDateTime = (dateString) => {
    if (!dateString || dateString === 'null') return '-';
    return new Date(dateString).toLocaleString(undefined, {
      year: 'numeric',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit'
    });
  };

  // Calculate license duration and remaining time
  const calculateLicenseDuration = () => {
    // if (!startData || !endData || parseInt(endData) === 0) return null; // Currently disable this due to facing issue in the basic plan, in basic plan enddate is null
     
    const startDate = new Date(startData);
    const now = new Date();

    // Calculate end date by adding months to start date
    const endDate = new Date(startDate);
    endDate.setMonth(endDate.getMonth() + parseInt(endData));

    // Calculate total duration and elapsed time
    const totalDuration = endDate.getTime() - startDate.getTime();
    const elapsedTime = now.getTime() - startDate.getTime();

    // Calculate progress percentage
    const progressPercentage = Math.max(0, Math.min(100, (elapsedTime / totalDuration) * 100));

    // Get remaining time in seconds from isExpiry and convert to days/hours/minutes
    const remainingTimeSeconds = parseInt(isExpiry) || 0;
    const remainingDays = Math.floor(remainingTimeSeconds / 86400);
    const remainingHours = Math.floor((remainingTimeSeconds % 86400) / 3600);
    const remainingMinutes = Math.floor((remainingTimeSeconds % 3600) / 60);

    const isExpired = remainingTimeSeconds <= 0;
    const isExpiringSoon = remainingDays <= 7 && !isExpired;

    return {
      startDate,
      endDate,
      remainingDays: Math.max(0, remainingDays),
      remainingHours: Math.max(0, remainingHours),
      remainingMinutes: Math.max(0, remainingMinutes),
      progressPercentage,
      isExpired,
      isExpiringSoon,
      totalMonths: parseInt(endData),
      isExpiry: remainingTimeSeconds
    };
  };

  const licenseInfo = calculateLicenseDuration();

  const getDurationStatusColor = () => {
    if (!licenseInfo) return 'text-gray-600';
    if (licenseInfo.isExpired) return 'text-red-600';
    if (licenseInfo.isExpiringSoon) return 'text-amber-600';
    return 'text-[#007980]';
  };


  const hideExpirySection =
  planName?.toLowerCase() === "basic" && (!endData || endData === "0" || endData === 0);

  return (
    <div className="space-y-4 sm:space-y-6 lg:space-y-8">
      {licenseInfo && (
        <div className="bg-white rounded-lg shadow-md border border-gray-300 overflow-hidden">
          {/* Header with Plan Info and Buttons */}
          <div className={`${isSuspended ? 'bg-gradient-to-br from-red-50 to-red-100' : 'bg-gradient-to-br from-blue-50 to-[#85cbcf]'} border-b border-gray-200 p-4 sm:p-6`}>
            <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
              <div className="flex items-center">
                <div className={`${isSuspended ? 'bg-red-100' : 'bg-[#07C07E1A]'} p-2 sm:p-3 rounded-full mr-3 sm:mr-4`}>
                  {isSuspended ? (
                    <AlertTriangle className="h-5 w-5 sm:h-6 sm:w-6 text-red-600" />
                  ) : (
                    <CheckCircle className="h-5 w-5 sm:h-6 sm:w-6 text-[#007980]" />
                  )}
                </div>
                <div>
                  <h2 className={`text-xl sm:text-2xl font-semibold ${isSuspended ? 'text-red-700' : 'text-[#007980]'}`}>{planName === 'Basic' ? 'Basic - Free Plan' : planName}</h2>
                  <p className={`text-sm sm:text-base ${isSuspended ? 'text-red-700' : 'text-[#007980]'}`}>
                    {isSuspended ? 'Account Suspended — payment required to restore full access' : 'License active and connected'}
                  </p>
                </div>
              </div>

              <div className="flex flex-wrap gap-2 sm:gap-3 w-full md:w-auto">
                {isSuspended ? (
                  <IconButton icon={AlertTriangle} text="Reactivate Subscription" onClick={handleReactivate} bgColor="bg-gradient-to-r from-red-600 to-red-700" hoverBgColor="hover:from-red-700 hover:to-red-800" textColor="text-white" className="shadow-md hover:shadow-lg transition-all duration-200 text-xs sm:text-sm" />
                ) : planName === "Exclusive" ? (
                  <div className="bg-gradient-to-r from-[#007980] to-[#00a0aa] text-white px-3 sm:px-4 py-2 rounded-lg shadow-md flex items-center text-xs sm:text-sm">
                    <Star className="w-3 h-3 sm:w-4 sm:h-4 mr-1 sm:mr-2" />
                    <span className="font-medium">You have the highest plan</span>
                  </div>
                ) : (
                  <IconButton icon={Star} text="Upgrade Plan" onClick={handleUpgrade} bgColor="bg-gradient-to-r from-[#007980] to-[#00a0aa]" hoverBgColor="hover:from-[#006970] hover:to-[#008a93]" textColor="text-white" className="shadow-md hover:shadow-lg transition-all duration-200 text-xs sm:text-sm" />
                )}
                <ActionButton defaultText="Disconnect" onClick={onDisconnect} className="!bg-red-500 border border-red-500 !text-red-500 hover:bg-red-50 px-3 py-2 rounded-md text-xs sm:text-sm font-medium transition-colors duration-200" />
              </div>
            </div>
          </div>

          {/* All License Details */}
         
          <div className="p-4 sm:p-6 md:max-h-64 overflow-y-auto space-y-6">
            {/* Connected Account & License Key */}
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
              <div className="flex items-start">
                <Mail className="w-4 h-4 sm:w-5 sm:h-5 mr-2 sm:mr-3 text-[#007980] mt-1 flex-shrink-0" />
                <div className="min-w-0">
                  <p className="text-xs sm:text-sm font-medium text-gray-800 mb-1">Connected Account</p>
                  <p className="text-sm sm:text-base text-gray-800 break-all">{email}</p>
                  {!hideExpirySection && (
                    <p className="text-xs sm:text-sm text-gray-600 mt-1">
                      Renewal at: {formatDateTime(endData)}
                    </p>
                  )}
                </div>
              </div>

              <div>
                <p className="text-xs sm:text-sm font-medium text-gray-800 mb-2 flex items-start">
                  <Key className="w-4 h-4 sm:w-5 sm:h-5 mr-2 sm:mr-3 text-[#007980] mt-0.5 flex-shrink-0" />
                  License Key
                </p>
                <div className="relative">
                  <input
                    type={show ? "text" : "password"}
                    className="w-full border border-gray-300 bg-gray-50 px-3 sm:px-4 py-2 sm:py-3 rounded-md shadow-sm text-gray-800 font-mono text-xs sm:text-sm pr-16 sm:pr-20"
                    value={licenseKey}
                    readOnly
                  />

                  {/* Eye Toggle */}
                  <button
                    type="button"
                    onClick={() => setShow(!show)}
                    className="absolute right-10 sm:right-12 top-1/2 transform -translate-y-1/2 text-gray-500 hover:text-gray-700"
                  >
                    {show ? <EyeOff className="h-3 w-3 sm:h-4 sm:w-4" /> : <Eye className="h-3 w-3 sm:h-4 sm:w-4" />}
                  </button>

                  {/* Copy Button */}
                  <button
                    type="button"
                    onClick={() => {
                      navigator.clipboard.writeText(licenseKey);
                      setCopied(true);
                      setTimeout(() => setCopied(false), 2500);
                    }}
                    className="absolute right-2 sm:right-3 top-1/2 transform -translate-y-1/2 bg-gray-200 hover:bg-gray-300 p-1 sm:p-1.5 rounded-md shadow-sm text-gray-600"
                  >
                    <Copy className="h-3 w-3 sm:h-4 sm:w-4" />
                  </button>

                  {/* Copied tooltip */}
                  {copied && (
                    <span className="absolute -top-6 right-2 sm:right-3 text-xs text-green-600 font-medium">
                      Copied!
                    </span>
                  )}
                </div>
              </div>
            </div>

            {/* Status Alert */}
           {!hideExpirySection && (licenseInfo.isExpiringSoon || licenseInfo.isExpired) && (
              <div className={`rounded-lg p-3 sm:p-4 flex items-start ${licenseInfo.isExpired
                  ? 'bg-red-50 border border-red-200'
                  : 'bg-amber-50 border border-amber-200'
                }`}>
                {licenseInfo.isExpired ? (
                  <XCircle className="w-4 h-4 sm:w-5 sm:h-5 text-red-600 mr-2 sm:mr-3 mt-0.5 flex-shrink-0" />
                ) : (
                  <AlertTriangle className="w-4 h-4 sm:w-5 sm:h-5 text-amber-600 mr-2 sm:mr-3 mt-0.5 flex-shrink-0" />
                )}
                <div>
                  <p className={`text-sm sm:text-base font-medium ${licenseInfo.isExpired ? 'text-red-800' : 'text-amber-800'
                    }`}>
                    {licenseInfo.isExpired
                      ? 'License has expired'
                      : 'License expires soon'}
                  </p>
                  <p className={`text-xs sm:text-sm ${licenseInfo.isExpired ? 'text-red-700' : 'text-amber-700'
                    }`}>
                    {licenseInfo.isExpired
                      ? 'Please renew your license to restore premium features.'
                      : 'Consider renewing your license to avoid service interruption.'}
                  </p>
                </div>
              </div>
            )}
          </div>
          
        </div>
      )}
      <ConnectedDevices devices={devices} />
    </div>
  );
};

const ConnectedDevices = ({ devices = [] }) => {
  return (
    <div className="bg-white rounded-lg shadow-sm border border-gray-300 overflow-hidden">
      <div className="p-4 sm:p-6 border-b border-gray-200 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-2 sm:gap-0">
        <h3 className="text-base sm:text-lg text-gray-800 flex items-center">
          <Smartphone className="w-4 h-4 sm:w-5 sm:h-5 mr-2 text-[#007980]" />
          Connected Mobile Devices
        </h3>
        <span className="bg-teal-100 text-teal-800 px-2 sm:px-3 py-0.5 sm:py-1 rounded-full text-[10px] sm:text-xs font-medium">
          {devices.length} {devices.length === 1 ? 'Device' : 'Devices'}
        </span>
      </div>

      {devices.length === 0 ? (
        <div className="py-8 sm:py-12 px-4 sm:px-6 text-center bg-gray-50">
          <div className="inline-flex items-center justify-center w-10 h-10 sm:w-12 sm:h-12 bg-gray-100 rounded-full mb-3 sm:mb-4">
            <Smartphone className="h-5 w-5 sm:h-6 sm:w-6 text-gray-400" />
          </div>
          <p className="text-sm sm:text-base text-gray-700 font-medium">No mobile devices currently connected</p>
          <p className="text-gray-500 text-xs sm:text-sm mt-1">Use the mobile app to connect your devices</p>
        </div>
      ) : (
        <div className="overflow-x-auto">
          {/* Mobile Card View */}
          <div className="block sm:hidden">
            {devices.map((device, index) => (
              <div key={index} className="p-4 border-b border-gray-200 last:border-b-0">
                <div className="space-y-2">
                  <div className="flex justify-between">
                    <span className="text-xs font-medium text-gray-500">Device ID</span>
                    <span className="text-xs text-gray-800">{device?.device?.device_id || "N/A"}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-xs font-medium text-gray-500">Device Name</span>
                    <span className="text-xs text-gray-800">{device?.device?.device_name || "N/A"}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-xs font-medium text-gray-500">Mobile Model</span>
                    <span className="text-xs text-gray-600">{device?.device?.device_model || "N/A"}</span>
                  </div>
                </div>
              </div>
            ))}
          </div>
          {/* Desktop Table View */}
          <table className="hidden sm:table min-w-full divide-y divide-gray-200">
            <thead>
              <tr className="bg-gray-50">
            
                <th className="px-4 sm:px-6 py-2 sm:py-3 text-left text-[10px] sm:text-xs font-medium text-gray-800 uppercase tracking-wider">
                  Device Name
                </th>
                <th className="px-4 sm:px-6 py-2 sm:py-3 text-left text-[10px] sm:text-xs font-medium text-gray-800 uppercase tracking-wider">
                  Mobile Model
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200">
              {devices.map((device, index) => (
                <tr key={index} className="hover:bg-gray-50 transition-colors duration-150">
                 
                  <td className="px-4 sm:px-6 py-3 sm:py-4 text-xs sm:text-sm text-gray-800 whitespace-nowrap">
                    {device?.device?.device_name ? device?.device?.device_name : "N/A"}
                  </td>
                  <td className="px-4 sm:px-6 py-3 sm:py-4 text-xs sm:text-sm text-gray-600 whitespace-nowrap">
                    {device?.device?.device_model ? device?.device?.device_model : "N/A"}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
};

export const NoLicenseView = ({ handleConnectClick, connecting }) => {
  return (
    <div className="space-y-4 sm:space-y-6 lg:space-y-8">
      {/* License Status Banner */}
      <div className="bg-gradient-to-r from-gray-50 to-gray-100 rounded-lg shadow-sm border border-gray-300 p-4 sm:p-6">
        <div className="flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
          <div className="flex items-center">
            <div className="bg-amber-100 p-2 sm:p-3 rounded-full mr-3 sm:mr-4">
              <AlertTriangle className="h-5 w-5 sm:h-6 sm:w-6 text-amber-600" />
            </div>
            <div>
              <h2 className="text-lg sm:text-xl font-semibold text-gray-800">Connect your Tailwatch Account</h2>
              <p className="text-sm sm:text-base text-black">Gain access to dozens of more tools and connect your site to your My Tailwatch Dashboard</p>
            </div>
          </div>

          <ActionButton defaultText="Connect to Tailwatch" onClick={handleConnectClick} isDisabled={connecting} className="rounded-md shadow hover:bg-[#007980] transition-colors duration-200 font-medium text-sm sm:text-base w-full md:w-auto" />
        </div>
      </div>

      {/* License Benefits */}
   <div className="bg-white rounded-lg shadow-sm border border-gray-300 p-4 sm:p-6">
  <h3 className="text-base sm:text-lg font-semibold text-gray-800 mb-3 sm:mb-4">Why Connect a License?</h3>

  <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 lg:gap-6">
    {/* Cross-Device Sync */}
    <div className="p-3 sm:p-4 bg-gray-50 rounded-md">
      <div className="flex items-center mb-2 sm:mb-3">
        <div className="bg-[#07C07E1A] p-1.5 sm:p-2 rounded-full mr-2 sm:mr-3">
          <Smartphone className="h-4 w-4 sm:h-5 sm:w-5 text-[#007980]" />
        </div>
        <h4 className="text-sm sm:text-base font-medium text-gray-800">Cross-Device Sync</h4>
      </div>
      <p className="text-gray-600 text-xs sm:text-sm">Seamlessly sync your data across all your devices</p>
    </div>

    {/* Premium Features */}
    <div className="p-3 sm:p-4 bg-gray-50 rounded-md">
      <div className="flex items-center mb-2 sm:mb-3">
        <div className="bg-[#07C07E1A] p-1.5 sm:p-2 rounded-full mr-2 sm:mr-3">
          <CheckCircle className="h-4 w-4 sm:h-5 sm:w-5 text-[#007980]" />
        </div>
        <h4 className="text-sm sm:text-base font-medium text-gray-800">Premium Features</h4>
      </div>
      <p className="text-gray-600 text-xs sm:text-sm">Access all premium features and functionality</p>
    </div>

    {/* Push Notifications */}
    <div className="p-3 sm:p-4 bg-gray-50 rounded-md">
      <div className="flex items-center mb-2 sm:mb-3">
        <div className="bg-[#07C07E1A] p-1.5 sm:p-2 rounded-full mr-2 sm:mr-3">
          <Mail className="h-4 w-4 sm:h-5 sm:w-5 text-[#007980]" />
        </div>
        <h4 className="text-sm sm:text-base font-medium text-gray-800">Push Notifications</h4>
      </div>
      <p className="text-gray-600 text-xs sm:text-sm">Get real-time notifications about your license and updates</p>
    </div>

    {/* Regular Updates */}
    <div className="p-3 sm:p-4 bg-gray-50 rounded-md">
      <div className="flex items-center mb-2 sm:mb-3">
        <div className="bg-[#07C07E1A] p-1.5 sm:p-2 rounded-full mr-2 sm:mr-3">
          <Calendar className="h-4 w-4 sm:h-5 sm:w-5 text-[#007980]" />
        </div>
        <h4 className="text-sm sm:text-base font-medium text-gray-800">Regular Updates</h4>
      </div>
      <p className="text-gray-600 text-xs sm:text-sm">Receive priority access to new features and updates</p>
    </div>
  </div>
</div>

  
    </div>
  );
};