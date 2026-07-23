import React, { useEffect } from 'react';
import { useErrorModal } from './ErrorModalContext';
import { WifiOff, AlertCircle, X } from 'lucide-react';

const ErrorModal = () => {
  const { errorState, closeError, retryRequest } = useErrorModal();

  const isNetworkError = errorState.type === 'network';
  const isLocalStorageError = errorState.isLocalStorageError;
  const isUnclosable = errorState.isUnclosable;
  // Prevent ESC key from closing localStorage error modals
  useEffect(() => {
    if ((isLocalStorageError || isUnclosable) && errorState.isOpen) {
      const handleEscKey = (event) => {
        if (event.key === 'Escape') {
          event.preventDefault();
          event.stopPropagation();
        }
      };
      window.addEventListener('keydown', handleEscKey);
      return () => {
        window.removeEventListener('keydown', handleEscKey);
      };
    }
  }, [isLocalStorageError, isUnclosable, errorState.isOpen]);

  if (!errorState.isOpen) return null;

  return (
    <div className="fixed inset-0 z-[9999] flex items-center justify-center">
      {/* Backdrop */}
      <div
        className="absolute inset-0 bg-black bg-opacity-50 backdrop-blur-sm"
        style={{ cursor: 'default' }}
      />

      {/* Modal */}
      <div className="relative bg-white rounded-lg shadow-2xl w-full max-w-md mx-4 overflow-hidden animate-slide-down">
        {/* Header with icon and close button */}
        <div className="flex items-start justify-between p-5 border-b border-gray-200">
          <div className="flex items-center gap-3">
            <div className={`flex-shrink-0 w-10 h-10 rounded-full flex items-center justify-center ${isNetworkError ? 'bg-orange-100' : 'bg-red-100'
              }`}>
              {isNetworkError ? (
                <WifiOff className="w-5 h-5 text-orange-600" />
              ) : (
                <AlertCircle className="w-5 h-5 text-red-600" />
              )}
            </div>
            <h3 className={`text-lg font-semibold ${isNetworkError ? 'text-orange-900' : 'text-red-900'
              }`}>
              {errorState.title}
            </h3>
          </div>

        </div>

        {/* Message */}
        <div className="p-5">
          <p className="text-gray-700 text-sm leading-relaxed">
            {errorState.message}
          </p>
        </div>

        {/* Action Buttons */}
        <div className={`flex items-center ${isLocalStorageError ? 'justify-center' : 'justify-end'} gap-3 px-5 py-4 bg-gray-50 border-t border-gray-200`}>

          <button
            onClick={retryRequest}
            className={`px-4 py-2 text-sm font-medium text-white rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 transition-colors ${isNetworkError
              ? 'bg-orange-600 hover:bg-orange-700 focus:ring-orange-500'
              : 'bg-[#007980] hover:bg-[#006970] focus:ring-[#007980]'
              }`}
          >
            {isLocalStorageError ? 'Refresh Page' : 'Retry'}
          </button>
        </div>
      </div>

      <style jsx>{`
        @keyframes slide-down {
          from {
            opacity: 0;
            transform: translateY(-20px);
          }
          to {
            opacity: 1;
            transform: translateY(0);
          }
        }
        .animate-slide-down {
          animation: slide-down 0.3s ease-out;
        }
      `}</style>
    </div>
  );
};

export default ErrorModal;
