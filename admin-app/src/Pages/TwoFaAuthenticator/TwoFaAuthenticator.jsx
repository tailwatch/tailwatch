import React, { useState, useEffect, useRef } from 'react';
import Header from '../../Components/Header/Header';
import { verifyMethod } from './TwoFaAuthServices/TwoFaAuthServices';
import InfoBar from '../../Components/InfoBar/InfoBar';
import EmailAuthenticator from './EmailAuthenticator/EmailAuthenticator';
import GoogleAuthenticator from './GoogleAuthenticator/GoogleAuthenticator'
import { Spinner } from '../../Components/Spinner/Spinner';
import { TwoFaAuthenticateLoader } from '../../Components/Skeleton/LoaderSkeleton';
import LoadingBar from 'react-top-loading-bar';
import LockCard from '../../Components/LockCard/LockCard';
import { useTwoFaFeature } from '../../Components/Hooks/Features/UseFeatures';
import ShowCanvas from '../../Components/ShowCanvasButton/ShowCanvas';
const TwoFaAuthenticator = () => {
  const { twoFa, refresTwoFaStatus } = useTwoFaFeature();
  const [loading, setLoading] = useState(false);
  const [isverifyMethod, setVerifyMethod] = useState({});
  const [isLicenseConnect, setIsLicenseConnect] = useState(null);
  const [islicenseReq, setIsLicenseReq] = useState(null);
  const isChecking = useRef(false);
  const ref = useRef(null);

  const fetchMethod = async () => {
    const result = await verifyMethod({ setLoading, setIsLicenseConnect, setIsLicenseReq });
    if (result && result.data) {
      setVerifyMethod(result.data);
    }
  };

  useEffect(() => {
    setLoading(true);
    fetchMethod();
  }, []);

  useEffect(() => {
    ref.current.continuousStart();
  }, []);

  useEffect(() => {
    if (!loading) {
      ref.current.complete();
    }
  }, [loading]);

  const checkTwoFaStatus = async () => {
    if (isChecking.current) return;
    isChecking.current = true;
    try {
      await refresTwoFaStatus();
      setIsLicenseConnect(null);
      setIsLicenseReq(null);
      await fetchMethod();
    } finally {
      isChecking.current = false;
    }
  };

  const renderAuthenticator = () => {
    switch (isverifyMethod?.method) {
      case 'Email':
        return <EmailAuthenticator />;
      case 'Google Authenticator':
        return <GoogleAuthenticator />;
      case 'disabled':
        return (
          <InfoBar type="info" message="Two-Factor Authentication is currently disabled for your account. Please configure it first " />
        );
      default:
        return null;
    }
  };

  return (
    <div>
      <LoadingBar ref={ref} height={3} color="#ec5023" />
      <Header title="Role-based 2FA" />

      {loading ? (
        <TwoFaAuthenticateLoader />
      ) : (
        <div className="p-4 mx-auto">
          {(islicenseReq?.code === 402) && (
            <InfoBar type="info" message={islicenseReq?.message} />
          )}

          <div className="bg-white rounded-lg shadow-md p-6 mb-6 border border-gray-300">
            <h2 className="text-2xl font-semibold text-gray-800 mb-2">Authentication Overview</h2>
            <p className="text-sm text-black">
              Two-Factor Authentication (2FA) adds an extra layer of security to your account by requiring
              an additional verification method during login. Choose the method that works best for you.
            </p>
            <div className="mt-4 flex items-center gap-2">
              <span className="inline-block px-3 py-2 text-sm font-medium rounded-md
                bg-[#07C07E1A] text-[#007980] border border-[#007980]">
                Current Method:{" "}
                {isverifyMethod?.method === 'disabled' ? 'None (Disabled)' : isverifyMethod?.method || 'N/A'}
              </span>
              <ShowCanvas featureId={twoFa?.featureId} isDisabled={islicenseReq?.code === 402} onClose={checkTwoFaStatus} />
            </div>
          </div>

          {islicenseReq?.code !== 402 && renderAuthenticator()}
          {((twoFa && twoFa?.isActiveState === '0') || isLicenseConnect === true) && islicenseReq?.code !== 402 && <LockCard
            featureName="Role-based 2FA"
            featureDescription="Enhance login security with two-factor authentication using Google Authenticator"
            featureId={twoFa?.featureId} isLocked={isLicenseConnect} isActive={twoFa?.isActiveState} afterToggleCallback={checkTwoFaStatus} />}
        </div>
      )}
    </div>
  );
};

export default TwoFaAuthenticator;
