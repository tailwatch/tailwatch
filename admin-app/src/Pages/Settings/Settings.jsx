import React, { useState, useEffect, useRef } from "react";
import Header from "../../Components/Header/Header";
import TableTabs from "../../Components/TableTabs/TableTabs.jsx";
import LicenseTab from "./LicenseTab/LicenseTab.jsx";
import SystemSettings from "./SystemSettings/SystemSettings.jsx";
import { useNavigate, useLocation } from "react-router-dom";
import LoadingBar from 'react-top-loading-bar';
import GeneralTab from "./GeneralTab/GeneralTab.jsx";
import IntegrationTab from "./IntegrationTab/IntegrationTab.jsx";

const Settings = () => {
  const [activeTab, setActiveTab] = useState("general");
  const [loading, setLoading] = useState(false);
  const [isRunning, setIsRunning] = useState(false);
  const navigate = useNavigate();
  const location = useLocation();
  const loadingBarRef = useRef(null);
  const [isBarLoading, setIsBarLoading] = useState(false);
  const [isInitializing, setIsInitializing] = useState(true);

  useEffect(() => {
    if (!loadingBarRef.current) return;

    const isAnyLoading = loading || isRunning;

    if (isAnyLoading && !isBarLoading) {
      loadingBarRef.current.continuousStart();
      setIsBarLoading(true);
    } else if (!isAnyLoading && isBarLoading) {
      loadingBarRef.current.complete();
      setIsBarLoading(false);
    }
  }, [loading, isRunning, isBarLoading]);

  useEffect(() => {
    const currentPath = location.pathname.split('/').pop();

    if (currentPath === "license" && activeTab !== "license") {
      setActiveTab("license");
    }
    else if (currentPath === "general" && activeTab !== "general") {
      setActiveTab("general");
    }
    else if (currentPath === "system" && activeTab !== "system") {
      setActiveTab("system");
    }
    else if (currentPath === "integration" && activeTab !== "integration") {
      setActiveTab("integration");
    }
    else if (currentPath !== "general" && currentPath !== "license" && currentPath !== "system" && currentPath !== "integration") {
      setActiveTab("general");
      navigate("/dashboard/settings/general", { replace: true });
    }
    if (isInitializing) {
      const timer = setTimeout(() => {
        setIsInitializing(false);
      }, 500);

      return () => clearTimeout(timer);
    }
  }, [location.pathname, activeTab, navigate, isInitializing]);

  const handleTabClick = (tab) => {
    if (tab === activeTab) return;
    setActiveTab(tab);
    navigate(`/dashboard/settings/${tab}`);
  };

  const tabs = [
    { key: 'general', label: 'General Settings' },
    { key: 'license', label: 'License' },
    { key: 'integration', label: 'Integrations' },
    { key: 'system', label: 'System Settings' },
  ];
  return (
    <div className="relative min-h-screen pb-16">
      <LoadingBar ref={loadingBarRef} height={3} color="#ec5023" shadow={true} />
      <Header title="Settings" />
      <div className="p-4">
        <TableTabs tabs={tabs} activeTab={activeTab} setActiveTab={handleTabClick} />
        <div>
          {activeTab === 'general' && (
            <GeneralTab activeTab={activeTab} isRunning={isRunning} setIsRunning={setIsRunning} setIsInitializing={setIsInitializing} />
          )}
          {activeTab === "license" && (
            <LicenseTab activeTab={activeTab} loading={loading} setLoading={setLoading} />
          )}
          {activeTab === "integration" && (
            <IntegrationTab />
          )}
          {activeTab === "system" && (
            <SystemSettings loading={loading} setLoading={setLoading} />
          )}
        </div>
      </div>
    </div>
  );
};

export default Settings;