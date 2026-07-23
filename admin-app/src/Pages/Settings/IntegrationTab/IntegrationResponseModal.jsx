import React from 'react'
import PopUpModal from '../../../Components/Modal/PopUpModal'
import { FiCheckCircle, FiXCircle, FiKey, FiRefreshCw, FiAlertCircle } from 'react-icons/fi'

const IntegrationResponseModal = ({ isOpen, onClose, responseData }) => {
  if (!isOpen) return null

  const isSuccess = responseData?.code === 200
  const integrationData = responseData?.integration_data?.maxmind || {}
  const isConnected = integrationData.is_connected

  const maskKey = (key) => {
    if (!key) return 'N/A';
    return key.length > 10 ? key.slice(0, 6) + '••••••' + key.slice(-4) : key;
  }

  return (
    <PopUpModal
      title={isSuccess ? 'Integration Updated' : 'Update Failed'}
      onClose={onClose}
      cancelButtonText="Close"
      showCloseIcon={true}
      glowColor={isSuccess ? '#007980' : '#ef4444'}
      width="w-full max-w-md"
    >
      <div className="space-y-3">

        {/* Connection Status */}
        <div className="flex items-center justify-between bg-gray-50 rounded-lg px-4 py-3">
          <span className="text-sm text-gray-500">Connection Status</span>
          {isConnected ? (
            <span className="flex items-center gap-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 border border-emerald-100 px-3 py-1 rounded-full">
              <FiCheckCircle className="text-xs" /> Connected
            </span>
          ) : (
            <span className="flex items-center gap-1.5 text-xs font-semibold text-red-600 bg-red-50 border border-red-100 px-3 py-1 rounded-full">
              <FiXCircle className="text-xs" /> Not Connected
            </span>
          )}
        </div>

        {/* License Key */}
        <div className="bg-gray-50 rounded-lg px-4 py-3">
          <div className="flex items-center gap-1.5 mb-1">
            <FiKey className="text-[#007980] text-xs" />
            <p className="text-xs text-gray-500">License Key</p>
          </div>
          <p className="text-sm font-semibold text-gray-800 font-mono">{maskKey(integrationData.license_key)}</p>
        </div>

        {/* Previous Key */}
        {integrationData.previous_key && (
          <div className="bg-gray-50 rounded-lg px-4 py-3">
            <div className="flex items-center gap-1.5 mb-1">
              <FiKey className="text-gray-400 text-xs" />
              <p className="text-xs text-gray-500">Previous Key</p>
            </div>
            <p className="text-sm font-semibold text-gray-500 font-mono">{maskKey(integrationData.previous_key)}</p>
          </div>
        )}

        {/* Retry Attempts */}
        <div className="flex items-center justify-between bg-gray-50 rounded-lg px-4 py-3">
          <div className="flex items-center gap-1.5">
            <FiRefreshCw className="text-gray-400 text-xs" />
            <span className="text-sm text-gray-500">Retry Attempts</span>
          </div>
          <span className={`text-sm font-semibold ${integrationData.retry_attempts > 0 ? 'text-amber-500' : 'text-gray-800'}`}>
            {integrationData.retry_attempts || 0}
          </span>
        </div>

        {/* Message */}
        {integrationData.message && (
          <div className="bg-amber-50 border border-amber-100 rounded-lg px-4 py-3">
            <div className="flex items-center gap-1.5 mb-2">
              <FiAlertCircle className="text-amber-500 shrink-0 text-xs" />
              <p className="text-xs text-amber-600 font-medium">Message</p>
            </div>
            <p className="text-xs text-amber-700 break-all leading-relaxed">{integrationData.message}</p>
          </div>
        )}

        {/* Response Code */}
        <div className="flex items-center justify-between bg-gray-50 rounded-lg px-4 py-3">
          <span className="text-sm text-gray-500">Response Code</span>
          <span className={`text-xs font-semibold px-3 py-1 rounded-full border ${isSuccess ? 'text-emerald-700 bg-emerald-50 border-emerald-100' : 'text-red-600 bg-red-50 border-red-100'}`}>
            {responseData?.code || 'N/A'}
          </span>
        </div>

      </div>
    </PopUpModal>
  )
}

export default IntegrationResponseModal
