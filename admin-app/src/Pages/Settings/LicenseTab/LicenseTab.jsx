import React, { useState, useEffect } from "react";
import { LicenseTabSkeleton } from "../../../Components/Skeleton/LoaderSkeleton";
import { handleConfirmDisconnect, getSecretKeys, fetchData, handleConnect,getCookies } from './LicenseTabServices/LicenseTabServices';
import { ConnectedLicenseView, NoLicenseView } from "./LicenseComponents/LicenseComponents";
import { useFeaturesData } from "../../../Components/Context/FeaturesDataContext";
import { alertService } from "../../../Components/AlertService/AlertService";
import { useUser } from "../../../Components/Context/UserContext";
import { useLicenseProvider } from "../../../Components/Context/LicenseProvider";

/* global wptw_ajax */

const LicenseTab = ({ activeTab, loading: parentLoading, setLoading }) => {    
  const [email, setEmail] = useState(null);
  const [connectionDateTime, setConnectionDateTime] = useState(null);
  const [startData, setStartData] = useState(null);
  const [isExpiry, setIsExpiry] = useState(null);
  const [endData, setEndData] = useState(null);
  const [licenseKey, setLicenseKey] = useState(null);
  const [role, setRole] = useState(null);
  const { fetchFeaturesData } = useFeaturesData();
  const [connecting, setConnecting] = useState(false);
  const [isFetching, setIsFetching] = useState(true);

  const { planName, setPlanName, devices, setDevices, loading: licenseLoading } = useLicenseProvider(); 
  const isLoading = licenseLoading || parentLoading || isFetching;

  const handleDirectDisconnect = async () => {        
    const isConfirmed = await alertService.verify(
      "Are you sure you want to disconnect your license? This will remove all connections to our service and you'll need to reconnect to use premium features.",
      "Disconnect License?",
      "Disconnect",
      "Cancel"
    );
    
    if (isConfirmed) {
      handleConfirmDisconnect(email, setEmail, setLicenseKey, setConnectionDateTime, setPlanName, setDevices, setLoading, fetchFeaturesData);
    }
  };
  
  const handleDisconnect = () => {
    handleDirectDisconnect();
  };

  useEffect(() => {
    setIsFetching(true);
    fetchData({
      setEmail, setLicenseKey, setConnectionDateTime, setPlanName,
      setDevices, setStartData, setEndData, setLoading, setIsExpiry, setRole
    }).finally(() => {
      setIsFetching(false);
    });
  }, [activeTab]);

  const handleConnectClick = async () => {
    const secretKeys = await getSecretKeys({ setConnecting });    
    const cookies = await getCookies({ setConnecting });
    await handleConnect({
      setLoading: setLoading,
      wptw_ajax,
      ctaId: secretKeys.cta_id,
      ctaSecret: secretKeys.cta_secret,
      authHeaderKey: secretKeys.auth_header_key,
      cookieName: cookies.name,
      cookieValue: cookies.value,
      successCallback: async () => {
        await fetchFeaturesData();
        await fetchData({setEmail, setLicenseKey, setConnectionDateTime, setPlanName, setDevices, setStartData, setEndData, setLoading,setIsExpiry, setRole});
      },
    });
  };

  if (isLoading) {
    return (
      <div className="skeleton-loader">
        <LicenseTabSkeleton />
      </div>
    );
  }

  return (
    <div className="mx-auto">
      {email ? (
        <ConnectedLicenseView isExpiry={isExpiry} startData={startData} endData={endData} email={email} licenseKey={licenseKey} connectionDateTime={connectionDateTime} planName={planName} devices={devices} role={role} onDisconnect={handleDisconnect} />
      ) : (
        <NoLicenseView handleConnectClick={handleConnectClick} connecting={connecting} />
      )}
    </div>
  );
};

export default LicenseTab;