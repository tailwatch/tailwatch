import { useState, useEffect } from "react";
import { FaWordpressSimple } from "react-icons/fa";
import "react-loading-skeleton/dist/skeleton.css";
import { SiGoogleauthenticator } from "react-icons/si";

import { LayoutDashboard, Users, Network, MoveRight, ExternalLink, ShieldCheck, EarthLock, Unlink, ListOrdered,Clock, FileTerminal, MonitorCog, Database, Search, FileSearch, Lightbulb, Settings, Menu, X, Star, Zap, Smartphone, Wifi, Bell, Shield, RefreshCw, HardDriveDownload } from "lucide-react";
import IconButton from "../Buttons/IconButton";
import { useSidebar } from "../Hooks/useSidebar/useSidebar";
import { bgprimary, sidebarColor } from "../Utils/Colors/Colors";
import { tailwatchLogo } from "../Hooks/useImages/useImages";
import { useUser } from "../Context/UserContext";
import MobileConnectCTA from "./MobileConnectCTA";
import { useLicenseProvider } from "../Context/LicenseProvider";
import UserCard from "./UserCard";
/* global wptw_ajax */

const Sidebar = () => {
  const [isOpen, setIsOpen] = useState(false);  
  const { isMalwareStarted, renderNavButton, location, renderSectionHeader, expandedMenu } = useSidebar();
  const { userData, loading } = useUser();
  const { planName, devices, loading: licenseLoading } = useLicenseProvider();

  // Check if pathnames are active
  const isDashboardActive = location.pathname === "/dashboard";
  const isFeaturesActive = location.pathname.startsWith("/dashboard/features");
  // Security  
  const isMalwareActive = location.pathname.startsWith("/dashboard/malwarescanner");
  const isFileMonitoringActive = location.pathname.startsWith("/dashboard/integrity-watch");
  const isGeoBlockingActive = location.pathname.startsWith("/dashboard/geo-blocking");
  const isBruteForceActive = location.pathname.startsWith("/dashboard/login-defender");
  const isFireWallActive = location.pathname.startsWith("/dashboard/firewall-logs");
  const isTwoFAActive = location.pathname.startsWith("/dashboard/rolebased-twofa");
  const isHardeningAuditActive = location.pathname.startsWith("/dashboard/hardening-audit");
  // Optimization
  const isDatabaseOptimizerActive = location.pathname.startsWith("/dashboard/database-optimizer");
  const isCronJobActive = location.pathname.startsWith("/dashboard/cron-jobs");
  const isRedirectionActive = location.pathname.startsWith("/dashboard/redirection");
  const isBrokenLinksActive = location.pathname.startsWith("/dashboard/broken-links");
  const isSearchReplaceActive = location.pathname.startsWith("/dashboard/search-replace");
  // Performance  
  const isUpdatesActive = location.pathname.startsWith("/dashboard/updates");
  // Recovery
  const isBackupActive = location.pathname.startsWith("/dashboard/backup");
  const isMigrationActive = location.pathname.startsWith("/dashboard/migration");
  // Logs & Activity
  const isLogsActive = location.pathname.startsWith("/dashboard/logs");
  const isSystemLogsActive = location.pathname.startsWith("/dashboard/system-logs");
  // User Management
  const isUserManagementActive = location.pathname.startsWith("/dashboard/usermangement");  
  // Settings
  const isSettingsActive = location.pathname.startsWith("/dashboard/settings");

  // Section actives
  const isSecurityActive = isMalwareActive || isFileMonitoringActive || isGeoBlockingActive || isBruteForceActive || isFireWallActive || isTwoFAActive || isHardeningAuditActive;
  const isOptimizationActive = isDatabaseOptimizerActive || isCronJobActive || isRedirectionActive || isBrokenLinksActive || isSearchReplaceActive;
  const isPerformanceActive = isUpdatesActive;
  const isRecoveryActive = isBackupActive || isMigrationActive;
  const isLogsActivityActive = isLogsActive;




  const toggleSidebar = () => setIsOpen(!isOpen);

  useEffect(() => {
    const handleResize = () => { if (window.innerWidth >= 1000) setIsOpen(true); else setIsOpen(false); };
    handleResize();
    window.addEventListener("resize", handleResize);
    return () => window.removeEventListener("resize", handleResize);
  }, []);

  const handleBackToWordPress = () => {
    window.location.href = wptw_ajax.admin_url;
  };

  useEffect(() => {
    if (window.innerWidth < 1000) setIsOpen(false);
  }, [location.pathname]);

  const mobileFeatures = [{ icon: Wifi, text: "Cross-Device Sync" }, { icon: Bell, text: "Real time Notifications" }];

  return (
    <>
      <div className="fixed top-5 right-20 z-[100] xl:hidden">
        <button onClick={toggleSidebar} className="bg-white text-gray-900 p-1 rounded-md focus:outline-none shadow-lg" >
          {isOpen ? <X size={20} /> : <Menu size={20} />}
        </button>
      </div>
      <div className={`fixed xl:relative h-full z-[100] ${isOpen ? "translate-x-0" : "-translate-x-full"} xl:translate-x-0 transition-transform duration-300 ease-in-out w-64 ${sidebarColor} flex flex-col shadow-xl`} >
        <div className="flex justify-center items-center py-6">
          <div className="flex items-center space-x-3">
            <img className="h-14" src={tailwatchLogo} alt="tailwatchLogo" />
          </div>
        </div>
        <div className="flex justify-center items-center mb-4 px-4">
          <IconButton icon={FaWordpressSimple} text="Back to WordPress" onClick={handleBackToWordPress} disabled={isMalwareStarted} bgColor={bgprimary} hoverBgColor="hover:bg-teal-600" textColor="text-white" className="w-full justify-center py-3 transition-all duration-300" />
        </div>

        {/* ── Nav Links ── */}
        {/* Scrollable Navigation Container */}
        <div className="flex-1 overflow-y-auto custom-scroll-bar">
          {/* Main Nav */}
          <div className="flex flex-col justify-start items-center px-4 w-full border-gray-600 border-b space-y-1 pb-4">
            {renderNavButton("/dashboard", <LayoutDashboard size={20} />, "Dashboard", isDashboardActive)}
            {renderNavButton("/dashboard/features", <ListOrdered size={20} />, "Features", isFeaturesActive)}
          </div>
          {/* Security Section */}
          <div className="flex flex-col justify-start items-center px-4 border-b border-gray-600 w-full">
            {renderSectionHeader("Security", isSecurityActive, 'security')}
            {expandedMenu === 'security' && (
              <div className="flex flex-col w-full space-y-1 items-start pb-1 pl-2">                
                {renderNavButton("/dashboard/malwarescanner", <ShieldCheck size={20} />, "Malware Guard", isMalwareActive)}
                {renderNavButton("/dashboard/integrity-watch", <FileSearch size={20} />, "File Integrity Watch", isFileMonitoringActive)}                
                {renderNavButton("/dashboard/geo-blocking", <ShieldCheck size={20} />, "Geo-blocking", isGeoBlockingActive)}
                {renderNavButton("/dashboard/login-defender", <FileSearch size={20} />, "Login Defender", isBruteForceActive)}       
                {renderNavButton("/dashboard/rolebased-twofa", <SiGoogleauthenticator size={20} />, "Role-based 2FA", isTwoFAActive)}
                {renderNavButton("/dashboard/hardening-audit", <Shield size={20} />, "Hardening Audit", isHardeningAuditActive)}
              </div>
            )}
          </div>
          {/* Optimization Section */}
          <div className="flex flex-col justify-start items-center px-4 border-b border-gray-600 w-full">
            {renderSectionHeader("Optimization", isOptimizationActive, 'optimization')}
            {expandedMenu === 'optimization' && (
              <div className="flex flex-col w-full space-y-1 items-start pb-1 pl-2">
                {renderNavButton("/dashboard/database-optimizer", <Database size={20} />, "Database Optimizer", isDatabaseOptimizerActive)}
                {renderNavButton("/dashboard/cron-jobs", <Clock size={20} />, "Cron Jobs", isCronJobActive)}
                {renderNavButton("/dashboard/redirection", <ExternalLink size={20} />, "301 Redirection", isRedirectionActive)}
                {renderNavButton("/dashboard/broken-links", <Unlink size={20} />, "Broken Links", isBrokenLinksActive)}
                {renderNavButton("/dashboard/search-replace", <Search size={20} />, "Search & Replace", isSearchReplaceActive)}
              </div>
            )}
          </div>
          {/* Performance Section */}
          <div className="flex flex-col justify-start items-center px-4 border-b border-gray-600 w-full">
            {renderSectionHeader("Performance", isPerformanceActive, 'performance')}
            {expandedMenu === 'performance' && (
              <div className="flex flex-col space-y-1 w-full items-start pb-1 pl-2">
                {renderNavButton("/dashboard/updates", <Lightbulb size={20} />, "Updates & Rollback", isUpdatesActive)}
              </div>
            )}
          </div>
          {/* Recovery Section */}
          <div className="flex flex-col justify-start items-center px-4 border-b border-gray-600 w-full">
            {renderSectionHeader("Recovery", isRecoveryActive, 'recovery')}
            {expandedMenu === 'recovery' && (
              <div className="flex flex-col space-y-1 w-full items-start pb-1 pl-2">
                {renderNavButton("/dashboard/backup/files", <HardDriveDownload size={20} />, "Backup Vault", isBackupActive)}
                {renderNavButton("/dashboard/migration", <MoveRight size={20} />, "Migration", isMigrationActive)}
              </div>
            )}
          </div>
          {/* Logs & Activity Section */}
          <div className="flex flex-col justify-start items-center px-4 border-b border-gray-600 w-full">
            {renderSectionHeader("Logs & Activity", isLogsActivityActive, 'logsactivity')}
            {expandedMenu === 'logsactivity' && (
              <div className="flex flex-col space-y-1 w-full items-start pb-1 pl-2">
                {renderNavButton("/dashboard/logs", <FileTerminal size={20} />, "Logs", isLogsActive)}
                {renderNavButton("/dashboard/system-logs", <MonitorCog size={20} />, "System Logs", isSystemLogsActive)}
              </div>
            )}
          </div>
          {/* User Management, ASK GPT, Settings */}
          <div className="flex flex-col justify-start items-center px-4 w-full space-y-1 py-4">
            {renderNavButton("/dashboard/usermangement", <Users size={20} />, "User Management", isUserManagementActive)}            
            {renderNavButton("/dashboard/settings", <Settings size={20} />, "Settings", isSettingsActive)}
          </div>
        </div>

        {/* ── Divider ── */}
        <div className="mx-3 mb-3 h-px" style={{ background: "rgba(255,255,255,0.06)" }} />

        <MobileConnectCTA devices={devices} loading={licenseLoading} planName={planName} mobileFeatures={mobileFeatures} />
        {/* ── User Card ── */}
        <UserCard loading={loading} userData={userData} />
        <div className="text-center text-xs text-white pb-2">
          🚀 Beta v1.0.0
        </div>
      </div>

      {/* Overlay */}
      {isOpen && (<div className="fixed inset-0 bg-black bg-opacity-50 z-30 xl:hidden backdrop-blur-sm" onClick={toggleSidebar} />)}
    </>
  );
};

export default Sidebar;
