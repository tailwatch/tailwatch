import React, { createContext, useContext, useState } from 'react';
import axios from 'axios';

const ErrorModalContext = createContext();

export const useErrorModal = () => {
  const context = useContext(ErrorModalContext);
  if (!context) {
    throw new Error('useErrorModal must be used within ErrorModalProvider');
  }
  return context;
};

export const ErrorModalProvider = ({ children }) => {
  const [errorState, setErrorState] = useState({
    isOpen: false,
    type: null, // 'network' or 'general'
    title: '',
    message: '',
    retryConfig: null,
    isLocalStorageError: false // Flag to identify localStorage errors
  });

  const showError = (type, retryConfig = null, customTitle = null, customMessage = null, isLocalStorageError = false, isUnclosable = false) => {
    if (type === 'network') {
      setErrorState({
        isOpen: true,
        type: 'network',
        title: customTitle || 'Connection Lost',
        message: customMessage || "You're offline. Please check your internet connection and try again.",
        retryConfig,
        isLocalStorageError: false,
        isUnclosable,
      });
    } else {
      setErrorState({
        isOpen: true,
        type: 'general',
        title: customTitle || 'Something went wrong',
        message: customMessage || "Your session may have expired or the network connection was lost. Please refresh the page and try again.",
        retryConfig,
        isLocalStorageError,
        isUnclosable
      });
    }
  };

  const closeError = () => {
    // Don't allow closing localStorage error modals
    if (errorState.isLocalStorageError) {
      return;
    }
    setErrorState({
      isOpen: false,
      type: null,
      title: '',
      message: '',
      retryConfig: null,
      isLocalStorageError: false,
      isUnclosable: false
    });
  };

  const retryRequest = async () => {
    // For localStorage errors, reload the page
    if (errorState.isLocalStorageError) {
      window.location.reload();
      return;
    }

    if (errorState.retryConfig) {
      try {
        window.location.reload();
        return;
      } catch (error) {
        // If retry fails, show error again
        const isNetworkError = !navigator.onLine || error.message === 'Network Error';
        showError(isNetworkError ? 'network' : 'general', errorState.retryConfig);
      }
    }
  };

  return (
    <ErrorModalContext.Provider value={{ errorState, showError, closeError, retryRequest }}>
      {children}
    </ErrorModalContext.Provider>
  );
};
