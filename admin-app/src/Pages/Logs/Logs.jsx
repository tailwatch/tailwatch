import React, { useState, useEffect } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import TableTabs from '../../Components/TableTabs/TableTabs.jsx';
import Header from '../../Components/Header/Header.jsx';
import { tabs } from './TabData/TabData.jsx';
import UserLogs from './UserLogs/UserLogs.jsx';
import ErrorLogs from './ErrorLogs/ErrorLogs.jsx';
import EmailLogs from './EmailLogs/EmailLogs.jsx';

const Logs = () => {
  const [activeTab, setActiveTab] = useState('user');



  const navigate = useNavigate();
  const location = useLocation();

  useEffect(() => {
    const currentPath = location.pathname.split('/').pop();

    if (currentPath === 'user' && activeTab !== 'user') {
      setActiveTab('user');
    } else if (currentPath === 'error' && activeTab !== 'error') {
      setActiveTab('error');
    } else if (currentPath === 'email' && activeTab !== 'email') {
      setActiveTab('email');
    } else if (currentPath !== 'user' && currentPath !== 'error' && currentPath !== 'email') {
      setActiveTab('user');
      navigate('/dashboard/logs/user', { replace: true });
    }
  }, [location.pathname, activeTab, navigate]);

  const onTabChange = (tabKey) => {
    if (tabKey === activeTab) return;
    setActiveTab(tabKey);
    navigate(`/dashboard/logs/${tabKey}`);
  };

  const renderActiveTabContent = () => {
    switch (activeTab) {
      case 'user':
        return <UserLogs key="user" />;
      case 'error':
        return <ErrorLogs key="error" />;
      case 'email':
        return <EmailLogs key="email" />;
      default:
        return <UserLogs key="user" />;
    }
  };

  return (
    <div>
      <Header title="Logs & Activity" />
      <div className="px-4 py-6">
        <TableTabs tabs={tabs} activeTab={activeTab} setActiveTab={onTabChange} isDisabled={false}  // tabs always enabled rahein

 />
        {renderActiveTabContent()}
      </div>
    </div>
  );
};

export default Logs;