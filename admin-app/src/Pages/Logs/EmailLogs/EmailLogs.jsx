import React, { useEffect, useRef, useState } from 'react';
import { FiSend } from 'react-icons/fi';
import Table from '../../../Components/Table/Table.jsx';
import { EmailModal, ErrorModal, SendTestEmailModal } from "../EmailModal/EmailModal.jsx";
import TableRows from '../TableRows/TableRows.jsx';
import { useLogsActivity } from '../../../Components/Hooks/useLogsActivity/useLogsActivity.jsx';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton';
import Pagination from '../../../Components/Pagination/Pagination.jsx';
import InfoBar from '../../../Components/InfoBar/InfoBar.jsx';
import LockCard from '../../../Components/LockCard/LockCard.jsx';
import LoadingBar from 'react-top-loading-bar';
import TableControlStrip from '../../../Components/TableControlStrip/TableControlStrip.jsx';
import { CheckboxField } from '../../../Components/Fields/CheckboxField.jsx';

const EmailLogs = () => {
  const activeTab = 'email';
  const key = 'default_feature_logs';
  const option = 'default_email_logs';

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
  const [isTestEmailModalOpen, setIsTestEmailModalOpen] = useState(false);

  const columns = [
    <CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={tabData.length === 0 || isTabDisabled} />,
    "Subject",
    "Sent To",
    "Delivery Time",
    "Status",
    "Details"
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

  const featureMessage = getFeatureStatusMessage();

  return (
    <div>
      <LoadingBar ref={loadingBarRef} height={3} color="#ec5023" />

      {loading ? (
        <LoaderSkeleton count="0" />
      ) : (
        <>
          <div className="flex justify-end mb-3">
            <button
              type="button"
              onClick={() => setIsTestEmailModalOpen(true)}
              className="flex items-center gap-2 px-3 py-2 text-sm text-white rounded bg-[#007980] hover:bg-opacity-80 transition-all"
            >
              <FiSend className="w-4 h-4" />
              Send Test Email
            </button>
          </div>

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
              />
            </div>

            {parentEnable === false && (
              <LockCard
                featureName="Email Logs"
                featureDescription="Track all site emails, successful or failed, with real-time delivery logs and alerts."

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

      {isTestEmailModalOpen && (
        <SendTestEmailModal
          handleModalClose={() => setIsTestEmailModalOpen(false)}
        />
      )}
    </div>
  );
};

export default EmailLogs;
