import React from 'react';
import { MdSos, MdClose } from 'react-icons/md';
import { FiAlertTriangle, FiCheckCircle, FiInfo, FiShield } from 'react-icons/fi';

const SosModal = ({ isOpen, onClose, licenseStatus }) => {
  if (!isOpen) return null;

  const isLicenseKilled = licenseStatus === "killed";

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-[9999] p-4">
      <div className="bg-white rounded-xl shadow-2xl max-w-2xl w-full max-h-[90vh] flex flex-col">
        {/* Header */}
        <div className={`px-6 py-4 border-b border-gray-200 flex items-center justify-between flex-shrink-0 ${
          isLicenseKilled ? 'bg-red-50' : 'bg-green-50'
        }`}>
          <div className="flex items-center space-x-3">
            <div className={`p-2 rounded-full ${
              isLicenseKilled ? 'bg-red-100' : 'bg-green-100'
            }`}>
              <MdSos className={`text-2xl ${
                isLicenseKilled ? 'text-red-600' : 'text-green-600'
              }`} />
            </div>
            <h2 className={`text-xl font-bold ${
              isLicenseKilled ? 'text-red-800' : 'text-green-800'
            }`}>
              {isLicenseKilled ? '🚨 SOS - Emergency Access' : '✅ SOS - System Ready'}
            </h2>
          </div>
          <button
            onClick={onClose}
            className="p-2 hover:bg-gray-100 rounded-full transition-colors"
          >
            <MdClose className="text-gray-500 text-xl" />
          </button>
        </div>

        {/* Content - Now with scroll */}
        <div className="p-6 space-y-6 overflow-y-auto flex-1">
          {isLicenseKilled ? (
            <>
              {/* Warning Section */}
              <div className="bg-red-50 border-l-4 border-red-400 p-4 rounded-r-lg">
                <div className="flex items-start space-x-3">
                  <FiAlertTriangle className="text-red-500 text-xl mt-0.5 flex-shrink-0" />
                  <div>
                    <h3 className="text-red-800 font-semibold text-lg mb-2">License Connection Required</h3>
                    <p className="text-red-700 leading-relaxed">
                      Your emergency access features are currently unavailable because your license is not connected. 
                      This means some critical safety features won't work during site emergencies.
                    </p>
                  </div>
                </div>
              </div>

              {/* What's Affected */}
              <div className="bg-orange-50 border border-orange-200 rounded-lg p-4">
                <div className="flex items-start space-x-3">
                  <FiInfo className="text-orange-500 text-xl mt-0.5 flex-shrink-0" />
                  <div>
                    <h4 className="text-orange-800 font-semibold mb-3">Currently Unavailable:</h4>
                    <ul className="space-y-2 text-orange-700">
                      <li className="flex items-center space-x-2">
                        <span className="w-2 h-2 bg-orange-400 rounded-full"></span>
                        <span>Real-time emergency notifications</span>
                      </li>
                      <li className="flex items-center space-x-2">
                        <span className="w-2 h-2 bg-orange-400 rounded-full"></span>
                        <span>Automatic crash detection</span>
                      </li>
                      <li className="flex items-center space-x-2">
                        <span className="w-2 h-2 bg-orange-400 rounded-full"></span>
                        <span>Remote troubleshooting tools</span>
                      </li>
                      <li className="flex items-center space-x-2">
                        <span className="w-2 h-2 bg-orange-400 rounded-full"></span>
                        <span>Priority support access</span>
                      </li>
                    </ul>
                  </div>
                </div>
              </div>

              {/* Still Available */}
              <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div className="flex items-start space-x-3">
                  <FiCheckCircle className="text-blue-500 text-xl mt-0.5 flex-shrink-0" />
                  <div>
                    <h4 className="text-blue-800 font-semibold mb-3">Still Available:</h4>
                    <ul className="space-y-2 text-blue-700">
                      <li className="flex items-center space-x-2">
                        <span className="w-2 h-2 bg-blue-400 rounded-full"></span>
                        <span>Web dashboard access during emergencies</span>
                      </li>
                      <li className="flex items-center space-x-2">
                        <span className="w-2 h-2 bg-blue-400 rounded-full"></span>
                        <span>Basic site monitoring</span>
                      </li>
                      <li className="flex items-center space-x-2">
                        <span className="w-2 h-2 bg-blue-400 rounded-full"></span>
                        <span>Manual dashboard login</span>
                      </li>
                    </ul>
                  </div>
                </div>
              </div>
            </>
          ) : (
            <>
              {/* Success Section */}
              <div className="bg-green-50 border-l-4 border-green-400 p-4 rounded-r-lg">
                <div className="flex items-start space-x-3">
                  <FiCheckCircle className="text-green-500 text-xl mt-0.5 flex-shrink-0" />
                  <div>
                    <h3 className="text-green-800 font-semibold text-lg mb-2">Emergency System Active</h3>
                    <p className="text-green-700 leading-relaxed">
                      Your license is connected and all emergency access features are fully operational. 
                      You're protected with 24/7 monitoring and instant emergency response capabilities.
                    </p>
                  </div>
                </div>
              </div>

              {/* Active Features */}
              <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
                <div className="flex items-start space-x-3">
                  <FiShield className="text-blue-500 text-xl mt-0.5 flex-shrink-0" />
                  <div>
                    <h4 className="text-blue-800 font-semibold mb-3">Active Protection Features:</h4>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-3">
                      <div className="flex items-center space-x-2">
                        <div className="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                        <span className="text-blue-700 text-sm">24/7 Site Monitoring</span>
                      </div>
                      <div className="flex items-center space-x-2">
                        <div className="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                        <span className="text-blue-700 text-sm">Instant Crash Alerts</span>
                      </div>
                      <div className="flex items-center space-x-2">
                        <div className="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                        <span className="text-blue-700 text-sm">Emergency Dashboard Access</span>
                      </div>
                      <div className="flex items-center space-x-2">
                        <div className="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                        <span className="text-blue-700 text-sm">Remote Troubleshooting</span>
                      </div>
                      <div className="flex items-center space-x-2">
                        <div className="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                        <span className="text-blue-700 text-sm">Priority Support</span>
                      </div>
                      <div className="flex items-center space-x-2">
                        <div className="w-2 h-2 bg-green-400 rounded-full animate-pulse"></div>
                        <span className="text-blue-700 text-sm">Automated Backups</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              {/* Emergency Contact */}
              <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <div className="flex items-start space-x-3">
                  <FiInfo className="text-gray-500 text-xl mt-0.5 flex-shrink-0" />
                  <div>
                    <h4 className="text-gray-800 font-semibold mb-2">Emergency Access Info:</h4>
                    <p className="text-gray-600 text-sm leading-relaxed">
                      In case of site emergencies, you can always access your dashboard through our web portal. 
                      All your monitoring data and emergency tools will be available even when your site is down.
                    </p>
                  </div>
                </div>
              </div>
            </>
          )}
        </div>

        {/* Footer */}
        <div className="px-6 py-4 bg-gray-50 border-t border-gray-200 rounded-b-xl flex-shrink-0">
          <div className="flex justify-end">
            <button
              onClick={onClose}
              className="px-6 py-2 bg-gray-600 text-white rounded-lg hover:bg-gray-700 transition-colors font-medium"
            >
              Close
            </button>
          </div>
        </div>
      </div>
    </div>
  );
};

export default SosModal;