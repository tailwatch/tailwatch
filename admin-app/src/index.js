import React from "react";
import ReactDOM from "react-dom/client";
import App from "./App.jsx";
import "./index.css";
import "./App.css";
import { HashRouter as Router, Routes, Route, Navigate } from "react-router-dom";
import Home from "./Pages/Home/Home.jsx";
import Settings from "./Pages/Settings/Settings.jsx";
import Features from "./Pages/Features/Features.jsx";
import Logs from "./Pages/Logs/Logs.jsx";
import Visit from "./Pages/Visit/Visit.jsx";
import VisitFeatures from "./Pages/Visit/VisitFeatures/VisitFeatures.jsx";
import Backup from './Pages/Backup/Backup.jsx';
import InnerFiles from './Pages/Backup/BackupFiles/InnerFiles/InnerFiles.jsx';
import DatabaseOptimizer from "./Pages/DatabaseOptimizer/DatabaseOptimizer.jsx";
import SearchReplace from "./Pages/SearchReplace/SearchReplace.jsx";
import FileMonitering from "./Pages/FileMonitering/FileMonitering.jsx";
import FileMoniteringDetails from "./Pages/FileMonitering/MoniteringFiles/MoniteringDetails/MoniteringDetails.jsx";
import BrokenLink from "./Pages/BrokenLink/BrokenLink.jsx";
import HardeningAudit from "./Pages/HardeningAudit/HardeningAudit.jsx";
import AuditReportDetails from "./Pages/HardeningAudit/AuditReportDetails/AuditReportDetails.jsx";
import Redirection from "./Pages/Redirection/Redirection.jsx";
import TwoFaAuthenticator from "./Pages/TwoFaAuthenticator/TwoFaAuthenticator.jsx";
import CronJob from "./Pages/CronJob/CronJob.jsx";
import ComingSoon from "./Pages/ComingSoon/ComingSoon.jsx";
import MalwareScanner from "./Pages/Scanner/MalwareScanner.jsx";
import ScannedDetails from "./Pages/Scanner/ScannedFiles/ScannedDetails/ScannedDetails.jsx";
import UserManagement from "./Pages/UserManagement/UserManagement.jsx";
import BruteForce from "./Pages/BruteForce/BruteForce.jsx";
import IpDetails from "./Pages/BruteForce/IpDetails/IpDetails.jsx";
import IpManagement from "./Pages/IpManagement/IpManagement.jsx";

ReactDOM.createRoot(document.getElementById("root")).render(
  <React.StrictMode>
    <Router>
      <Routes>
        <Route path="/visit" element={<Visit />} />
        <Route path="/visitfeatures" element={<VisitFeatures />} />
        <Route path="/*" element={<Navigate to="/dashboard" replace />} />
        <Route path="/dashboard" element={<App />}>
          {/* Home Route */}
          <Route index element={<Home />} />

          {/* Features */}
          <Route path="features" element={<Features />} />
          <Route path="features/*" element={<Navigate to="/dashboard/features" replace />} />

          {/* Logs */}
          <Route path="logs/*" element={<Logs />} />
          <Route path="logs/*" element={<Navigate to="/dashboard/logs" replace />} />

          {/* Settings */}
          <Route path="settings/*" element={<Settings />} />
          <Route path="settings/*" element={<Navigate to="/dashboard/settings" replace />} />

          {/* System Logs */}
          <Route path="system-logs/*" element={<ComingSoon title="System Logs" />} />

          {/* Backup */}
          <Route path="backup/*" element={<Backup />} />
          <Route path="backup/backupfiles/:files" element={<InnerFiles />} />
          <Route path="backup/*" element={<Navigate to="/dashboard/backup" replace />} />

          {/* Migration */}
          <Route path="migration/*" element={<ComingSoon title="Migration" />} />

          {/* User Management */}
          <Route path="usermangement/*" element={<UserManagement />} />
          <Route path="usermangement/*" element={<Navigate to="/dashboard/usermangement" replace />} />

          {/* Restore */}
          <Route path="restore/*" element={<ComingSoon title="Restore" />} />

          {/* Database Optimizer */}
          <Route path="database-optimizer" element={<DatabaseOptimizer />} />
          <Route path="database-optimizer/*" element={<Navigate to="/dashboard/database-optimizer" replace />} />

          {/* Malware Scanner */}
          <Route path="malwarescanner" element={<MalwareScanner />} />
          <Route path="malwarescanner/malwaredetails/:pid" element={<ScannedDetails />} />
          <Route path="malwarescanner/*" element={<Navigate to="/dashboard/malwarescanner" replace />} />

          {/* Search & Replace */}
          <Route path="search-replace" element={<SearchReplace />} />
          <Route path="search-replace/*" element={<Navigate to="/dashboard/search-replace" replace />} />

          {/* File Monitoring */}
          <Route path="integrity-watch" element={<FileMonitering />} />
          <Route path="integrity-watch/filemonitering/:moniteringId" element={<FileMoniteringDetails />} />
          <Route path="integrity-watch/*" element={<Navigate to="/dashboard/integrity-watch" replace />} />

          {/*Redirection */}
          <Route path="redirection/*" element={<Redirection />} />
          <Route path="redirection/*" element={<Navigate to="/dashboard/redirection" replace />} />

          {/*BrokenLink */}
          <Route path="broken-links" element={<BrokenLink />} />
          <Route path="broken-links/*" element={<Navigate to="/dashboard/broken-links" replace />} />

          {/*Hardening Audit */}
          <Route path="hardening-audit" element={<HardeningAudit />} />
          <Route path="hardening-audit/report/:reportId" element={<AuditReportDetails />} />
          <Route path="hardening-audit/*" element={<Navigate to="/dashboard/hardening-audit" replace />} />

          {/*Google Authenticator */}
          <Route path="rolebased-twofa" element={<TwoFaAuthenticator />} />
          <Route path="rolebased-twofa/*" element={<Navigate to="/dashboard/rolebased-twofa" replace />} />

          {/*CroneJob */}
          <Route path="cron-jobs/*" element={<CronJob />} />
          <Route path="cron-jobs/*" element={<Navigate to="/dashboard/crone-jobs" replace />} />          

          <Route path="geo-blocking/*" element={<IpManagement />} />
          <Route path="ipmanagement/*" element={<Navigate to="/dashboard/geo-blocking" replace />} />

          <Route path="login-defender/*" element={<BruteForce />} />
          <Route path="login-defender/*" element={<Navigate to="/dashboard/login-defender" replace />} />

          <Route path="login-defender/ip-details" element={<IpDetails />} />
          <Route path="login-defender/ip-details/*" element={<Navigate to="/dashboard/login-defender/ip-details" replace />} />

          {/* Catch-All Route */}
          <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Route>
      </Routes>
    </Router>
  </React.StrictMode>

);
