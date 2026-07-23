import React, { useEffect, useState,useRef } from 'react';
import { fetchLogsErrorData } from '../RedirectionServices/RedirectionServices';
import Table from '../../../Components/Table/Table'
import TableControlStrip from '../../../Components/TableControlStrip/TableControlStrip';
import { handleDeleteErrorLogs } from '../RedirectionServices/RedirectionServices'
import { renderErrorLogsRow } from '../RedirectionFormContent/RedirectionRules';
import { useRedirection } from '../../../Components/Hooks/useRedirection/useRedirection';
import PopUpModal from '../../../Components/Modal/PopUpModal';
import { RedirectionFormContent } from '../RedirectionFormContent/RedirectionFormContent';
import { redirectCodes, matchTypeOptions } from '../RedirectionFormContent/FormData';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton';
import Pagination from '../../../Components/Pagination/Pagination';
import { alertService } from '../../../Components/AlertService/AlertService';
import { CheckboxField } from '../../../Components/Fields/CheckboxField';
import InfoBar from '../../../Components/InfoBar/InfoBar';
import { useMoniteringFeature } from '../../../Components/Hooks/Features/UseFeatures';
import LockCard from '../../../Components/LockCard/LockCard';
const FourLogs = () => {
  const {moniteringFeature,refreshMoniteringStatus} = useMoniteringFeature()
  const [loading, setLoading] = useState(true);
  const [logsData, setLogsData] = useState([]);
  const [selectedLogs, setSelectedLogs] = useState({});
  const [allSelected, setAllSelected] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const [featureEnable, setFeatureEnable] = useState(null);
  const [parentEnable, setParentEnable] = useState(null);
  const [pagination, setPagination] = useState({ page: 0, limit: 10, total_pages: 1, total_items: 0 });
  const isChecking = useRef(false);
  const { showAdvancedOptions, showRedirectModal, userRoles, isLoading, control, handlePostChange, handleSubmit, onSubmit, handleRoleSelection,
    errors, setShowModal, showModal, formValues, setValue, setShowAdvancedOptions, showInclusionRules, setShowInclusionRules, handleGetSourceUrl, preselectedValues, handleSelectionChange
  } = useRedirection();

  const fetchLogsData = async (page = pagination.page, limit = pagination.limit) => {
    await fetchLogsErrorData({
      setLoading, setLogsData, page: page + 1, limit,setFeatureEnable,setParentEnable, 
      setPagination: (paginationData) => {
        setPagination({ page: paginationData.page - 1, limit: paginationData.limit, total_pages: paginationData.total_pages,total_items: paginationData.total_items || 0  });
      }
    });
  };
  useEffect(() => {
    fetchLogsData();
  }, []);


  const safeLogsData = Array.isArray(logsData) ? logsData : [];

  const filteredLogs = safeLogsData.filter(log => {
    if (!searchTerm.trim()) return true;

    const searchLower = searchTerm.toLowerCase();
    const url = log.parsedValue?.url?.toLowerCase() || '';
    const message = log.parsedValue?.log_message?.toLowerCase() || '';
    const domain = log.parsedValue?.domain?.toLowerCase() || '';

    return url.includes(searchLower) || message.includes(searchLower) || domain.includes(searchLower);
  });

  const handleSelectAll = () => {
    if (allSelected) {
      setSelectedLogs({});
      setAllSelected(false);
    } else {
      const newSelected = {};
      filteredLogs.forEach(log => {
        if (log && log.id) {
          newSelected[log.id] = true;
        }
      });
      setSelectedLogs(newSelected);
      setAllSelected(true);
    }
  };
  const handleSelectRow = (id) => {
    setSelectedLogs(prev => {
      const newSelected = { ...prev, [id]: !prev[id] };
      const allItemsSelected = filteredLogs.every(log => newSelected[log.id]);
      setAllSelected(allItemsSelected);
      return newSelected;
    });
  };

  const handleDeletAll = async () => {
    const confirmed = await alertService.confirm("This can't be undone. it removes all data from database", "Are you sure to delete all this data?");
    if (!confirmed) {
      return;
    }
    setIsDeleting(true);
    try {
      const deleteData = { ids: [], is_delete: true, };
      await handleDeleteErrorLogs(deleteData, setIsDeleting, fetchLogsData);
      setSelectedLogs({});
      setAllSelected(false);
    } catch (error) {
      console.error('Error deleting items:', error);
    } finally {
      setIsDeleting(false);
    }
  }

  const handleBulkDelete = async () => {
    const confirmed = await alertService.confirm("This can't be undone. ", "Are you sure to delete ?");
    if (!confirmed) {
      return;
    }
    const selectedIds = Object.keys(selectedLogs).filter(id => selectedLogs[id]).map(id => parseInt(id));
    const deleteData = { ids: selectedIds, is_delete: false, };
    await handleDeleteErrorLogs(deleteData, setIsDeleting, fetchLogsData);
    setSelectedLogs({});
    setAllSelected(false);
  };

  const handlePageChange = (newPage) => {
    setPagination(prev => ({ ...prev, page: newPage }));
    fetchLogsData(newPage, pagination.limit);
    // Clear selections when changing pages
    setSelectedLogs([]);
    setAllSelected(false);
  };

  const handlePageSizeChange = (newPageSize) => {
        setPagination(prev => ({ ...prev, limit: newPageSize, page: 0 }));
        fetchLogsData(0, newPageSize);        
        setSelectedLogs([]);
        setAllSelected(false);
    };

  const columns = [<CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={filteredLogs.length===0} className='ml-1'/>, "URL", "Domain", "Status Code", "Log Message", "Date", "IP Address", "Create"];

  const renderRow = (log) => {
    return renderErrorLogsRow(log, selectedLogs, handleSelectRow, showRedirectModal, handleGetSourceUrl);
  };

   const checkMoniteringStatus = async () => {
        if (isChecking.current) return;
        isChecking.current = true;
        try {
            await refreshMoniteringStatus();
            setFeatureEnable(null);
            setParentEnable(null);            
            await fetchLogsData();
        } finally {
            isChecking.current = false;
        }
    };

  return (
    <div>
      {loading ? (
        <LoaderSkeleton count="0" />
      ) : (
        <>
          { (featureEnable===false || parentEnable === false) && (<InfoBar type="info" message="Please configure your settings first"/>)}
          <TableControlStrip featureId={moniteringFeature?.featureId} CheckStatus={checkMoniteringStatus} handleDeleteAll={handleDeletAll} disbaled={filteredLogs.length === 0} selectedFilesCount={Object.values(selectedLogs).filter(Boolean).length} handleBulkDelete={handleBulkDelete} isDeleting={isDeleting} searchTerm={searchTerm} setSearchTerm={setSearchTerm} hasEligibleFiles={filteredLogs.length > 0} showControls={true} showCanvas={true} />
          <Table columns={columns} data={filteredLogs} renderRow={renderRow} noDataMessage="No 404 error logs found" />
          {(parentEnable===false) && <LockCard featureId={moniteringFeature?.featureId} isActive={moniteringFeature?.isActiveState} afterToggleCallback={checkMoniteringStatus} showOverlay={false}/>}
          <Pagination currentPage={pagination.page} totalPages={pagination.total_pages} onPageChange={handlePageChange} hasData={logsData.length > 0} pageSizeOptions={[5, 10, 20, 30, 50, 100]} totalItems={pagination.total_items} showPageSizeFilter={true} pageSize={pagination.limit} onPageSizeChange={handlePageSizeChange}  />
        </>
      )}

      {showModal && (
        <PopUpModal disabled={isLoading} isLoading={isLoading} title="Add a redirect" onClose={() => setShowModal(false)} onSave={handleSubmit(onSubmit)} saveButtonText="Add this redirect!" cancelButtonText="Cancel" width="w-full max-w-4xl" height="max-h-[90vh]" showExpandIcon={true}>
          <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
            <RedirectionFormContent control={control} errors={errors} formValues={formValues} isLoading={isLoading} userRoles={userRoles} setValue={setValue} handleRoleSelection={handleRoleSelection} handlePostChange={handlePostChange} showAdvancedOptions={showAdvancedOptions} setShowAdvancedOptions={setShowAdvancedOptions} showInclusionRules={showInclusionRules} setShowInclusionRules={setShowInclusionRules} redirectCodes={redirectCodes} matchTypeOptions={matchTypeOptions} initialPostType={preselectedValues.postType} initialPostId={preselectedValues.postId} initialCustomUrl={preselectedValues.customUrl} handleSelectionChange={handleSelectionChange} />
          </form>
        </PopUpModal>
      )}
    </div>
  );
};

export default FourLogs;