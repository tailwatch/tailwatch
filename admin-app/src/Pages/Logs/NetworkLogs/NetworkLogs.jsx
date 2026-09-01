import React, { useEffect, useRef, useState } from 'react';
import Table from '../../../Components/Table/Table.jsx';
import { EmailModal, ErrorModal } from "../EmailModal/EmailModal.jsx";
import TableRows from '../TableRows/TableRows.jsx';
import { useLogsActivity } from '../../../Components/Hooks/useLogsActivity/useLogsActivity.jsx';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton';
import Pagination from '../../../Components/Pagination/Pagination.jsx';
import LockCard from '../../../Components/LockCard/LockCard.jsx';
import LoadingBar from 'react-top-loading-bar';
import TableControlStrip from '../../../Components/TableControlStrip/TableControlStrip.jsx';
import { CheckboxField } from '../../../Components/Fields/CheckboxField.jsx';
import InfoBar from '../../../Components/InfoBar/InfoBar.jsx';
import LogDetailModal from '../../../Components/LogDetailModal/LogDetailModal.jsx';

const NetworkLogs = () => {
  const activeTab = 'network';
  const key = 'default_feature_logs';
  // Log GROUP the entries are stored under (shared with the Database Optimizer's
  // retention); same slug as the feature toggle.
  const option = 'default_network_logs';
  const [selectedLog, setSelectedLog] = useState(null);
  const [isModalOpen, setIsModalOpen] = useState(false);

  const {
    isErrorModalOpen,
    isDetailModalOpen,
    setFeatureEnable,
    setParentEnable,
    currentError,
    currentDetail,
    handleErrorModalOpen,
    handleDetailModalOpen,
    handleModalClose,
    isFeatureEnabled,
    getFeatureStatusMessage,
    loading,
    tabData,
    initialMount,
    refreshFeaturesStatus,
    handlePageChange,
    handlePageSizeChange,
    getFeatureInfo,
    featureEnable,
    parentEnable,
    fetchTabData,
    handleSelectLog,
    handleSelectAll,
    handleBulkDelete,
    allSelected,
    isDeleting,
    searchTerm,
    setSearchTerm,
    selectedLogs,
    pagination,
    handleDeleteAll,
    filterOptions,
    selectedFilters,
    applyFilters,
    clearFilters
  } = useLogsActivity(activeTab, key, option);

  const isTabDisabled = parentEnable === false;

  const columns = [
    <CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={tabData.length === 0 || isTabDisabled} />,
    "Action Type",
    "Method",
    "Status Code",
    "IP Address",
    "Response Time"
  ];

  const loadingBarRef = useRef(null);
  const previousLoadingState = useRef(false);

  useEffect(() => {
    if (loadingBarRef.current) {
      if (loading) {
        loadingBarRef.current.continuousStart();
        previousLoadingState.current = true;
      } else if (previousLoadingState.current) {
        loadingBarRef.current.complete();
        previousLoadingState.current = false;
      }
    }
  }, [loading]);

  useEffect(() => {
    if (initialMount.current) {
      initialMount.current = false;
    }
    fetchTabData();
  }, []);

  const handleRowClick = (index) => {
    const log = tabData[index];
    if (log) {
      setSelectedLog({ ...log, isAjax: true });
      setIsModalOpen(true);
    }
  };

  const renderRow = (rowData) => {
    return (
      <TableRows
        activeTab={activeTab}
        rowData={rowData}
        handleErrorModalOpen={handleErrorModalOpen}
        handleDetailModalOpen={handleDetailModalOpen}
        handleSelectLog={handleSelectLog}
        selectedLogs={selectedLogs}
      />
    );
  };

  return (
    <div>
      <LoadingBar ref={loadingBarRef} height={3} color="#ec5023" />

      {loading ? (
        <LoaderSkeleton count="0" />
      ) : (
        <>
          <TableControlStrip
            showControls={true}
            showLogFilter={isFeatureEnabled()}
            logFilterOptions={filterOptions}
            logSelectedFilters={selectedFilters}
            onApplyLogFilters={applyFilters}
            onResetLogFilters={clearFilters}
            handleDeleteAll={handleDeleteAll}
            disbaled={tabData.length === 0 || isTabDisabled}
            selectedFilesCount={selectedLogs.length}
            handleBulkDelete={handleBulkDelete}
            isDeleting={isDeleting}
            searchTerm={searchTerm}
            setSearchTerm={setSearchTerm}
            hasEligibleFiles={tabData.length > 0 && !isTabDisabled}
            showCanvas={true}
            featureId={getFeatureInfo().featureId}
            CheckStatus={refreshFeaturesStatus}
            isDisabled={isTabDisabled}
          />
          {(featureEnable === false && parentEnable === true) && (
            <InfoBar
              type="info"
              message={"Configure your settings first"}
            />
          )}

          <div className="min-h-[400px] relative">
            <div className={parentEnable === false ? "blur-sm" : ""}>
              <Table
                columns={columns}
                data={isFeatureEnabled() ? tabData || [] : []}
                renderRow={renderRow}
                noDataMessage={`No ${activeTab} logs found.`}
                onRowClick={handleRowClick}
              />
            </div>

            {parentEnable === false && (
              <LockCard
                featureName="Network Logs"
                featureDescription="Monitor internal REST, AJAX, cron, and XML-RPC requests — endpoint, method, status, and response time. Credentials are redacted and request bodies are never stored."
                featureId={getFeatureInfo().featureId}
                isActive={getFeatureInfo().isActive}
                afterToggleCallback={refreshFeaturesStatus}
                showOverlay={false}
              />
            )}

            {tabData.length > 0 && isFeatureEnabled() && (
              <Pagination
                currentPage={pagination.page}
                totalPages={pagination.total_pages}
                onPageChange={handlePageChange}
                hasData={tabData.length > 0}
                pageSizeOptions={[5, 10, 20, 30, 50, 100]}
                totalItems={pagination.total_items}
                showPageSizeFilter={true}
                pageSize={pagination.limit}
                onPageSizeChange={handlePageSizeChange}
              />
            )}
          </div>
        </>
      )}

      {isErrorModalOpen && (
        <ErrorModal
          handleModalClose={handleModalClose}
          currentError={currentError}
        />
      )}

      {isDetailModalOpen && (
        <EmailModal
          handleModalClose={handleModalClose}
          currentDetail={currentDetail}
        />
      )}

      {isModalOpen && selectedLog && (
        <LogDetailModal
          log={selectedLog}
          onClose={() => {
            setIsModalOpen(false);
            setSelectedLog(null);
          }}
        />
      )}
    </div>
  );
};

export default NetworkLogs;
