import React, { useEffect, useRef, useState } from "react";
import LoadingBar from "react-top-loading-bar";
import Header from "../../Components/Header/Header";
import LicenseTab from "../Settings/LicenseTab/LicenseTab.jsx";
import RestoredProSettingsNotice from "../../Components/SavedProSettings/RestoredProSettingsNotice.jsx";

/**
 * Connect Account: the site's link to its Tailwatch account, app and dashboard.
 *
 * Its own place in the sidebar rather than a tab of Settings. Not connected, it offers the
 * free account; connected, it shows the plan, the connected devices and how to disconnect.
 */
const Account = () => {
  const [loading, setLoading] = useState(false);
  const loadingBarRef = useRef(null);
  const [isBarLoading, setIsBarLoading] = useState(false);

  useEffect(() => {
    if (!loadingBarRef.current) return;
    if (loading && !isBarLoading) {
      loadingBarRef.current.continuousStart();
      setIsBarLoading(true);
    } else if (!loading && isBarLoading) {
      loadingBarRef.current.complete();
      setIsBarLoading(false);
    }
  }, [loading, isBarLoading]);

  return (
    <div className="relative min-h-screen pb-16">
      <LoadingBar ref={loadingBarRef} height={3} color="#ec5023" shadow={true} />
      <Header title="Connect Account" />
      <div className="p-4">
        <RestoredProSettingsNotice />
        <LicenseTab activeTab="account" loading={loading} setLoading={setLoading} />
      </div>
    </div>
  );
};

export default Account;
