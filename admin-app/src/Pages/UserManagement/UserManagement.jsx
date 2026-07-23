import React, { useState, useEffect, useRef } from 'react';
import Header from '../../Components/Header/Header';
import TableTabs from '../../Components/TableTabs/TableTabs';
import { useNavigate, useLocation } from "react-router-dom";
import { userTabs } from '../Logs/TabData/TabData';
import UsersTab from './UsersTab/UsersTab';
import PasswordLessLogin from './PasswordLessLogin/PasswordLessLogin';
import LoadingBar from 'react-top-loading-bar';

const UserManagement = () => {
  const [activeTab, setActiveTab] = useState("users");
  const navigate = useNavigate();
  const location = useLocation();
  const [usersLoading, setUsersLoading] = useState(true);
  const [passwordlessLoading, setPasswordlessLoading] = useState(true);
  const loadingBarRef = useRef(null);
  const currentPath = location.pathname.split('/').pop();

  const activeLoading = activeTab === "users" ? usersLoading : passwordlessLoading;

  useEffect(() => {
    if (activeLoading) {
      loadingBarRef.current?.continuousStart();
    } else {
      loadingBarRef.current?.complete();
    }
  }, [activeLoading]);

  useEffect(() => {
    const validPaths = ["users", "passwordless"];
    if (!validPaths.includes(currentPath)) {
      navigate("/dashboard/usermangement/users");
    } else {
      setActiveTab(currentPath);
    }
  }, [currentPath, navigate]);

  const handleTabClick = (tab) => {
    if (tab === activeTab) return;
    // Reset the loading state for the tab we're switching to so skeleton shows fresh
    if (tab === "users") setUsersLoading(true);
    if (tab === "passwordless") setPasswordlessLoading(true);
    setActiveTab(tab);
    navigate(`/dashboard/usermangement/${tab}`);
  };

  const renderTabContent = () => {
    switch (activeTab) {
      case "users":
        return <UsersTab loading={usersLoading} setLoading={setUsersLoading} />;
      case "passwordless":
        return <PasswordLessLogin loading={passwordlessLoading} setLoading={setPasswordlessLoading} />;
      default:
        return <UsersTab loading={usersLoading} setLoading={setUsersLoading} />;
    }
  };

  return (
    <div>
      <LoadingBar ref={loadingBarRef} height={3} color="#ec5023" />
      <Header title="User Management" />
      <div className="p-4">
        <TableTabs tabs={userTabs} activeTab={activeTab} setActiveTab={handleTabClick} />
        {renderTabContent()}
      </div>
    </div>
  );
};

export default UserManagement;
