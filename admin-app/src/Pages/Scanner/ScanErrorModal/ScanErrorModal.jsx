import React from 'react';
import { AlertTriangle, RefreshCw, X } from 'lucide-react';

const ScanErrorModal = ({ scanError, onRetry, onClose }) => {
  if (!scanError) return null;

  const isLimitReached = scanError.type === 'SCAN_LIMIT_REACHED';
  const isScanningDisabled = scanError.type === 'SCANNING_DISABLED';
  const isSoftBlock = isLimitReached || isScanningDisabled;

  const getErrorIcon = () => {
    switch (scanError.type) {
      case 'SCAN_LIMIT_REACHED':
      case 'SCANNING_DISABLED':
      case 'NETWORK_ERROR':
      case 'TIMEOUT_ERROR':
        return <AlertTriangle className="w-16 h-16 text-orange-500" />;
      case 'SERVER_ERROR':
      case 'SERVICE_UNAVAILABLE':
        return <AlertTriangle className="w-16 h-16 text-red-500" />;
      case 'AUTH_ERROR':
        return <AlertTriangle className="w-16 h-16 text-yellow-500" />;
      default:
        return <AlertTriangle className="w-16 h-16 text-red-500" />;
    }
  };

  const getErrorTitle = () => {
    switch (scanError.type) {
      case 'SCAN_LIMIT_REACHED':
        return 'Scan Limit Reached';
      case 'SCANNING_DISABLED':
        return 'Scanning Disabled';
      case 'NETWORK_ERROR':
        return 'Network Connection Failed';
      case 'TIMEOUT_ERROR':
        return 'Request Timed Out';
      case 'SERVER_ERROR':
        return 'Server Error';
      case 'SERVICE_UNAVAILABLE':
        return 'Service Unavailable';
      case 'AUTH_ERROR':
        return 'Authentication Error';
      case 'PARSE_ERROR':
        return 'Response Parse Error';
      case 'INVALID_STATE':
        return 'Invalid Scan State';
      case 'SCAN_FAILED':
        return 'Scan Process Failed';
      case 'BACKEND_ERROR':
        return 'Backend Error';
      default:
        return 'Scan Error Occurred';
    }
  };

  const getHeaderTitle = () => {
    if (isLimitReached) return 'Scan Limit Reached';
    if (isScanningDisabled) return 'Malware Scanning Disabled';
    return 'Malware Scan Failed';
  };

  return (
    <div className="fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
      <div className="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        {/* Header */}
        <div className="flex items-center justify-between p-6 border-b border-gray-200">
          <h2 className="text-2xl font-bold text-gray-900">{getHeaderTitle()}</h2>
          <button
            onClick={onClose}
            className="text-gray-400 hover:text-gray-600 transition-colors"
            aria-label="Close"
          >
            <X className="w-6 h-6" />
          </button>
        </div>

        {/* Body */}
        <div className="p-6 space-y-6">
          {/* Error Icon and Title */}
          <div className="flex flex-col items-center text-center space-y-4">
            {getErrorIcon()}
            <h3 className="text-xl font-semibold text-gray-900">{getErrorTitle()}</h3>
          </div>

          {/* Error / Soft-block Message */}
          <div className={`${isSoftBlock ? 'bg-orange-50 border-orange-200' : 'bg-red-50 border-red-200'} border rounded-lg p-4`}>
            <p className={`${isSoftBlock ? 'text-orange-800' : 'text-red-800'} font-medium mb-2`}>
              {isSoftBlock ? 'Reason:' : 'Error Message:'}
            </p>
            <p className={isSoftBlock ? 'text-orange-700' : 'text-red-700'}>{scanError.message}</p>
          </div>

          {/* Error Details (if available) */}
          {scanError.details && (
            <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
              <p className="text-gray-800 font-medium mb-2">Details:</p>
              <p className="text-gray-700 text-sm">{scanError.details}</p>
            </div>
          )}

          {/* Retry Attempts Info — hide for soft-block cases (count is misleading) */}
          {!isSoftBlock && (
            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
              <p className="text-blue-800 font-medium mb-2">Retry Information:</p>
              <p className="text-blue-700 text-sm">
                Failed after <span className="font-semibold">{scanError.attempt}</span> of{' '}
                <span className="font-semibold">{scanError.maxRetries}</span> retry attempts.
              </p>
              {scanError.timestamp && (
                <p className="text-blue-600 text-xs mt-2">
                  Occurred at: {new Date(scanError.timestamp).toLocaleString()}
                </p>
              )}
            </div>
          )}

          {/* Troubleshooting Tips */}
          <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
            <p className="text-gray-800 font-medium mb-3">Troubleshooting Tips:</p>
            <ul className="list-disc pl-5 space-y-2 text-gray-700 text-sm">
              {scanError.type === 'NETWORK_ERROR' && (
                <>
                  <li>Check your internet connection</li>
                  <li>Verify that your server is running and accessible</li>
                  <li>Check if any firewall is blocking the connection</li>
                </>
              )}
              {scanError.type === 'TIMEOUT_ERROR' && (
                <>
                  <li>The server might be overloaded. Wait a few minutes and try again</li>
                  <li>Check server resource usage (CPU, memory)</li>
                  <li>Consider increasing the timeout limit if the issue persists</li>
                </>
              )}
              {(scanError.type === 'SERVER_ERROR' || scanError.type === 'SERVICE_UNAVAILABLE') && (
                <>
                  <li>Check server error logs for more details</li>
                  <li>Verify that all required services are running</li>
                  <li>Ensure database connectivity is stable</li>
                  <li>Contact your system administrator if the problem persists</li>
                </>
              )}
              {scanError.type === 'AUTH_ERROR' && (
                <>
                  <li>Your session may have expired. Try refreshing the page</li>
                  <li>Log out and log back in</li>
                  <li>Clear your browser cache and cookies</li>
                </>
              )}
              {(scanError.type === 'PARSE_ERROR' || scanError.type === 'INVALID_STATE') && (
                <>
                  <li>Check server logs for PHP errors or warnings</li>
                  <li>Ensure WordPress debug mode is disabled in production</li>
                  <li>Verify that no plugins are outputting unexpected content</li>
                </>
              )}
              {scanError.type === 'SCAN_FAILED' && (
                <>
                  <li>Check backend scan process logs</li>
                  <li>Verify sufficient disk space and memory</li>
                  <li>Ensure file permissions are correct</li>
                </>
              )}
              <li>If the problem persists, contact support with the error details above</li>
            </ul>
          </div>
        </div>

        {/* Footer */}
        <div className="flex justify-end gap-3 p-6 border-t border-gray-200 bg-gray-50">
          <button
            onClick={onClose}
            className="px-6 py-2.5 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition-colors font-medium"
          >
            Close
          </button>
          {scanError.canRetry && (
            <button
              onClick={onRetry}
              className="px-6 py-2.5 bg-[#007980] text-white rounded-lg hover:bg-opacity-90 transition-colors font-medium flex items-center gap-2"
            >
              <RefreshCw className="w-4 h-4" />
              Retry Scan
            </button>
          )}
        </div>
      </div>
    </div>
  );
};

export default ScanErrorModal;
