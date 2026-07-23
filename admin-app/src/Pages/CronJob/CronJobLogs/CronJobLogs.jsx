import React, { useEffect, useState,useRef } from 'react';
import { fetchLogsCron,handleDeleteCronLogs } from '../CronJobServices/CronJobServices';
import Table from '../../../Components/Table/Table'
import TableControlStrip from '../../../Components/TableControlStrip/TableControlStrip';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton';
import Pagination from '../../../Components/Pagination/Pagination';
import { alertService } from '../../../Components/AlertService/AlertService';
import { CheckboxField } from '../../../Components/Fields/CheckboxField';
import { renderCronLogs } from './renderCronLogs';
import InfoBar from '../../../Components/InfoBar/InfoBar';
const CronJobLogs = ({logsData,loading,fetchLogsData,pagination, setPagination,parentEnable,featureEnable,cronJobRule,setParentEnable,setFeatureEnable,refreshCronJobStatus}) => {
  
  const [selectedLogs, setSelectedLogs] = useState({});
  const [allSelected, setAllSelected] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');     
  const safeLogsData = Array.isArray(logsData) ? logsData : [];
  const isChecking = useRef(false);
  const filteredLogs = safeLogsData.filter(log => {
    if (!searchTerm.trim()) return true;

    const searchLower = searchTerm.toLowerCase();
    const action = log.parsedValue?.action.toLowerCase() || '';
    const message = log.parsedValue?.message?.toLowerCase() || '';    

    return action.includes(searchLower) || message.includes(searchLower);
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
      await handleDeleteCronLogs(deleteData, setIsDeleting, fetchLogsData);
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
    await handleDeleteCronLogs(deleteData, setIsDeleting, fetchLogsData);
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

  const columns = [<CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={filteredLogs.length===0} className='ml-1'/>, "Action", "Hook", "Message", "Created at"];

  const renderRow = (log) => {
    return renderCronLogs(log, selectedLogs, handleSelectRow);
  };

  const checkCronJobStatus = async () => {
    if (isChecking.current) return;
    isChecking.current = true;
    try {
        await refreshCronJobStatus();
        setParentEnable(null);
        setFeatureEnable(null);                
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
        {(parentEnable === false || featureEnable === false ) && (<InfoBar type="info" message="Please Configure your settings"/>)}
          <TableControlStrip handleDeleteAll={handleDeletAll} featureId={cronJobRule?.featureId} disbaled={filteredLogs.length === 0} CheckStatus={checkCronJobStatus} selectedFilesCount={Object.values(selectedLogs).filter(Boolean).length} handleBulkDelete={handleBulkDelete} isDeleting={isDeleting} searchTerm={searchTerm} setSearchTerm={setSearchTerm} hasEligibleFiles={filteredLogs.length > 0} showControls={true} showCanvas={true} />
          <Table columns={columns} data={filteredLogs} renderRow={renderRow} noDataMessage="No Cron job logs found" />
          <Pagination currentPage={pagination.page} totalPages={pagination.total_pages} onPageChange={handlePageChange} hasData={logsData.length > 0} pageSizeOptions={[5, 10, 20, 30, 50, 100]} totalItems={pagination.total_items} showPageSizeFilter={true} pageSize={pagination.limit} onPageSizeChange={handlePageSizeChange}  />
        </>
      )}
      
    </div>
  );
};

export default CronJobLogs;