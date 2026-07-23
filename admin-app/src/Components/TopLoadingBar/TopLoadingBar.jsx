import React from 'react';
import LoadingBar from 'react-top-loading-bar';
import { useLoading } from '../Context/LoadingContext.jsx';

const TopLoadingBar = () => {
  const { loadingRef, progress } = useLoading();

  return (
    <LoadingBar
      ref={loadingRef}
      color="#ee5121" // Tailwind blue-600
      progress={progress}
      height={3}
      shadow={true}
    />
  );
};

export default TopLoadingBar;