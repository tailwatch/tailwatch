import React, { createContext, useContext, useRef, useState } from 'react';

const LoadingContext = createContext();

export const LoadingProvider = ({ children }) => {
  const loadingRef = useRef(null);
  const [progress, setProgress] = useState(0);

  const startLoading = () => {
    setProgress(10); // Start with 10%
    loadingRef.current?.continuousStart();
  };

  const incrementProgress = (amount = 10) => {
    setProgress(prev => Math.min(prev + amount, 90)); // Don't complete until finishLoading is called
  };

  const finishLoading = () => {
    setProgress(100);
    loadingRef.current?.complete();
  };

  return (
    <LoadingContext.Provider value={{ loadingRef, progress, startLoading, incrementProgress, finishLoading }}>
      {children}
    </LoadingContext.Provider>
  );
};

export const useLoading = () => useContext(LoadingContext);