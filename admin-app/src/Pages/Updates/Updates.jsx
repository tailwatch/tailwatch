import React, { useEffect, useRef, useState } from "react";
import Header from "../../Components/Header/Header.jsx";
import TableTabs from "../../Components/TableTabs/TableTabs.jsx";
import { useUpdatesRollback } from "../../Components/Hooks/useUpdatesRollback/useUpdatesRollback.jsx";
import LoadingBar from 'react-top-loading-bar';
import LoaderSkeleton from "../../Components/Skeleton/LoaderSkeleton.jsx";
import LockCard from "../../Components/LockCard/LockCard.jsx";
import TableControlStrip from "../../Components/TableControlStrip/TableControlStrip.jsx";
import { PluginUpdatesContainer } from "./Containers/PluginUpdatesContainer.jsx";
import { ThemeUpdatesContainer } from "./Containers/ThemeUpdatesContainer.jsx";
import { CoreUpdatesContainer } from "./Containers/CoreUpdatesContainer.jsx";
import ViewHistoryModal from "./ViewHistoryModal/ViewHistoryModal.jsx";

const Updates = () => {
  const {
    tabs,
    loading,
    isValidTab,
    handleTabClick,
    activeTab,
    searchTerm,
    setSearchTerm,
    updateRollback,
    refreshUpdateData,
    selectedPlugins,
    handleSelectAll,
    handleSelectPlugin,
    handleBulkActivate,
    handleBulkDeactivate,
    handleBulkDelete,
    registerRefetch,
    isHistoryModalOpen,
    historyData,
    historyLoading,
    hasMoreHistory,
    isLoadingMore,
    formatActionType,
    handleViewHistoryClick,
    handleHistoryScroll,
    handleCloseHistoryModal,
  } = useUpdatesRollback();

  const [paginatedData, setPaginatedData] = useState([]);
  const [allPluginsData, setAllPluginsData] = useState([]);
  const [parentEnable, setParentEnable] = useState(null);
  const loadingBarRef = useRef(null);
  const [isBarLoading, setIsBarLoading] = useState(false);

  // Loading Bar Logic (same as Features module)
  useEffect(() => {
    if (!loadingBarRef.current) return;

    const isAnyLoading = loading;

    if (isAnyLoading && !isBarLoading) {
      loadingBarRef.current.continuousStart();
      setIsBarLoading(true);
    } else if (!isAnyLoading && isBarLoading) {
      loadingBarRef.current.complete();
      setIsBarLoading(false);
    }
  }, [loading, isBarLoading]);

  const handleFeatureStatusChange = (feature, parent) => {
    setParentEnable(parent);
  };

  const handleDataChange = (data) => {
    setPaginatedData(data);

    setAllPluginsData(prev => {
      const newData = [...prev];
      data.forEach(plugin => {
        const existingIndex = newData.findIndex(p => p.plugin === plugin.plugin);
        if (existingIndex !== -1) {
          newData[existingIndex] = plugin;
        } else {
          newData.push(plugin);
        }
      });
      return newData;
    });
  };

  const handleRefreshUpdateData = async () => {
    await refreshUpdateData();
    setParentEnable(null);
  };

  useEffect(() => {
    setPaginatedData([]);
    setAllPluginsData([]);
    setParentEnable(null);
  }, [activeTab]);

  const selectedPluginData = allPluginsData
    .filter(plugin => selectedPlugins.includes(plugin.plugin))
    .map(plugin => ({
      plugin: plugin.plugin,
      name: plugin.name,
      is_active: plugin.is_active,
      is_protected: plugin.is_protected,
      can_activate: plugin.can_activate,
      can_deactivate: plugin.can_deactivate,
      can_delete: plugin.can_delete,
      activation_blocked_reason: plugin.activation_blocked_reason,
      deactivation_blocked_reason: plugin.deactivation_blocked_reason,
      deletion_blocked_reason: plugin.deletion_blocked_reason,
    }));

  return (
    <div>
      <LoadingBar
        ref={loadingBarRef}
        height={3}
        color="#ec5023"
        shadow={true}
      />

      <Header title="Updates & Rollback" />

      <div className="p-4">
        <TableTabs tabs={tabs} activeTab={activeTab} setActiveTab={handleTabClick} />      
        <TableControlStrip
          showCanvas={true}
          featureId={updateRollback?.featureId}
          CheckStatus={handleRefreshUpdateData}
          showControls={false}
          searchTerm={searchTerm}
          setSearchTerm={setSearchTerm}
          showPluginModalNames={selectedPluginData || []}
          hasEligibleFiles={paginatedData.length > 0}
          showPluginBulkActions={activeTab === 'plugin'}
          selectedFilesCount={selectedPlugins.length}
          handleBulkActivate={handleBulkActivate}
          handleBulkDeactivate={handleBulkDeactivate}
          handleBulkDelete={handleBulkDelete}
          showViewHistory={true}
          onViewHistoryClick={handleViewHistoryClick}
        />

        {!isValidTab ? (
          <div>
            <LoaderSkeleton count="0" />
          </div>
        ) : (
          <div>
            {activeTab === 'plugin' && (
              <PluginUpdatesContainer
                searchTerm={searchTerm}
                selectedPlugins={selectedPlugins}
                handleSelectPlugin={handleSelectPlugin}
                handleSelectAll={handleSelectAll}
                onDataChange={handleDataChange}
                onFeatureStatusChange={handleFeatureStatusChange}
                registerRefetch={registerRefetch}
              />
            )}
            {activeTab === 'theme' && (
              <ThemeUpdatesContainer
                searchTerm={searchTerm}
                onDataChange={handleDataChange}
                onFeatureStatusChange={handleFeatureStatusChange}
                registerRefetch={registerRefetch}
              />
            )}
            {activeTab === 'coreUpdates' && (
              <CoreUpdatesContainer
                searchTerm={searchTerm}
                onDataChange={handleDataChange}
                onFeatureStatusChange={handleFeatureStatusChange}
                registerRefetch={registerRefetch}
              />
            )}
          </div>
        )}

        {parentEnable === false && (
          <LockCard
            featureName="Updates & Rollback"
            featureDescription="Manage WordPress updates, rollbacks, and actions with history and instant alerts."
            featureId={updateRollback?.featureId}
            isActive={updateRollback?.isActive}
            afterToggleCallback={handleRefreshUpdateData}
          />
        )}
      </div>

      <ViewHistoryModal
        isOpen={isHistoryModalOpen}
        onClose={handleCloseHistoryModal}
        activeTab={activeTab}
        historyData={historyData}
        historyLoading={historyLoading}
        hasMoreHistory={hasMoreHistory}
        isLoadingMore={isLoadingMore}
        formatActionType={formatActionType}
        onScroll={handleHistoryScroll}
      />
    </div>
  );
};

export default Updates;