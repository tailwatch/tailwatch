import React, { useState, useRef } from 'react';
import { instantVerifySsl } from '../HomeServices/HomeServices';
import { MdUpdate, MdWarning } from "react-icons/md";
import { AlertTriangle, Lock, LockKeyhole } from "lucide-react";
import ActionButton from '../../../Components/Buttons/ActionButton';
import { alertService } from '../../../Components/AlertService/AlertService';
import { toast } from 'react-toastify';

const VerifySsl = ({ verifySslData, verifySslDetail, setVerifysslData, setLoadingVerify, isDisabled, setIsDisabled }) => {
  const [loading, setLoading] = useState(false);
  const lordIconRef = useRef();

  const handleInstantVerify = async () => {
    const confirmed = await alertService.verify("Verify SSL", "Are you sure to verify?");
    if (!confirmed) {
      return;
    }
    await instantVerifySsl(setLoading, verifySslDetail, setVerifysslData, setLoadingVerify, setIsDisabled);
    toast.success("SSL Verification run successfully.");
  };

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

  const transformKeys = (details) =>
    Object.fromEntries(
      Object.entries(details).map(([key, value]) => [key.replace(/_/g, ' '), value])
    );

  const feature = verifySslData[0] || { name: "SSL Verification", details: {} };
  const transformedDetails = transformKeys(feature.details);
  const lastRun = feature.details.last_run ? feature.details.last_run : "N/A";

  const getStatusIndicator = () => {
    if (!feature.details) return { color: "gray", text: "Unknown" };
    if (feature.details.Ssl_Connection === "Connected") return { color: "green", text: "Connected" };
    if (feature.details.Ssl_Connection === "Disconnected") return { color: "red", text: "Disconnected" };
    return { color: "yellow", text: "Needs Verification" };
  };

  const status = getStatusIndicator();  

  return (
    <div
      className={`h-full ${isDisabled ? "border border-gray-300 rounded-xl" : ""}`}
      onMouseEnter={handleCardMouseEnter}
      onMouseLeave={handleCardMouseLeave}
    >

      <div
        className={`rounded-xl shadow-lg relative h-full overflow-hidden transition-all duration-300 ${isDisabled
          ? "bg-gradient-to-br from-gray-50 to-gray-100"
          : "bg-gradient-to-br from-blue-50 to-[#85cbcf] border border-[#85cbcf]"
          }`}
      >
        {/* Header Section */}
        <div className="relative">
          <div
            className={`absolute top-0 right-0 py-1 px-3 text-xs font-medium text-white rounded-bl-lg ${isDisabled ? "bg-gray-500" : "bg-[#007980]"
              }`}
          >
            {isDisabled ? "Inactive" : "Active"}
          </div>
        </div>

        {/* Main Content */}
        <div className="p-6">
          {isDisabled ? (
            // Disabled State
            <div className="flex flex-col h-full">
              <div className="flex items-center mb-4">
                <div className="w-10 h-10 rounded-full flex items-center justify-center mr-3 bg-gray-100">
                  <Lock size={24} className="text-gray-400" />
                </div>
                <h2 className="text-lg font-semibold text-black">{feature.name}</h2>
              </div>
              <div className="flex items-center p-4 bg-gray-50 rounded-lg mb-4">
                <p className="text-black italic flex items-center">
                  <AlertTriangle size={16} className="mr-2 text-black" />
                  This feature is not enabled
                </p>
              </div>
            </div>
          ) : (
            // Active State
            <div className="flex flex-col gap-4">
              {/* Title and Status Row */}
              <div className="flex items-center justify-between">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-full flex items-center justify-center bg-[#07C07E1A]">
                    <LockKeyhole size={24} className="text-[#007980]" />
                  </div>
                  <div>
                    <h3 className="text-lg font-semibold text-black">{feature.name}</h3>
                    <p className="text-sm text-black">
                      Status:{" "}
                      <span
                        className={`font-medium ${status.color === "green"
                          ? "text-green-600"
                          : status.color === "red"
                            ? "text-red-600"
                            : "text-amber-600"
                          }`}
                      >
                        {status.text}
                      </span>
                    </p>
                  </div>
                </div>

                <ActionButton
                  className="!text-xs !px-3 !py-2"
                  defaultText={loading ? "Verifying..." : "Verify Now"}
                  isDisabled={loading}
                  onClick={handleInstantVerify}
                />
              </div>

              {/* Last Update */}
              <div className="flex items-center text-sm text-gray-600 gap-2 pb-2 border-b border-gray-200">
                <MdUpdate className="text-gray-700 text-lg" />
                <span className="font-medium text-black">Last Updated:</span>
                <span className="text-black">{lastRun}</span>
              </div>

              {/* Details Grid */}
              <div className="grid grid-cols-1 sm:grid-cols-2 gap-3">
                {Object.entries(transformedDetails)
                  .filter(([key]) => key.toLowerCase() !== "last run")
                  .map(([key, value], idx) => (
                    <div
                      key={idx}
                      className={`rounded-lg p-3 border ${idx % 2 === 0
                        ? "bg-white border-gray-200"
                        : "bg-gray-50 border-gray-200"
                        }`}
                    >
                      <p className="text-xs text-gray-600 font-medium mb-1 uppercase tracking-wide">
                        {key}
                      </p>
                      <p className="text-sm text-black font-medium">
                        {value ? value.toString() : <span className="text-gray-400">N/A</span>}
                      </p>
                    </div>
                  ))}
              </div>
            </div>
          )}
        </div>

        {/* Bottom Gradient Bar */}
        <div
          className={`${isDisabled
            ? "h-2 w-full bg-gradient-to-r from-gray-600 to-gray-300"
            : "bg-transparent"
            }`}
        ></div>
      </div>
    </div>
  );
};

export default VerifySsl;