import React, { useState, useMemo } from 'react';
import Table from '../../../../Components/Table/Table';
import IconButton from '../../../../Components/Buttons/IconButton.jsx';
import { BiExport } from "react-icons/bi";
import { MdSettingsBackupRestore } from "react-icons/md";
import { Tooltip as ReactTooltip } from 'react-tooltip';
import TableControlStrip from '../../../../Components/TableControlStrip/TableControlStrip.jsx';
import { alertService } from '../../../../Components/AlertService/AlertService.js';
import { CheckboxField } from '../../../../Components/Fields/CheckboxField.jsx';
import ActionButton from '../../../../Components/Buttons/ActionButton.jsx';
import { useNavigate } from 'react-router-dom';

const BackupContent = ({ activeTab, currentPageData, handleDelete, deletingFileId, backupStatus }) => {
  const navigate = useNavigate();

  const [selectedFiles, setSelectedFiles] = useState([]);
  const [isDeleting, setIsDeleting] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');

  // Create a map of backup statuses by backup_id for quick lookup
  const backupStatusMap = useMemo(() => {
    if (!backupStatus || !Array.isArray(backupStatus)) return {};
    return backupStatus.reduce((acc, status) => {
      acc[status.backup_id] = status;
      return acc;
    }, {});
  }, [backupStatus]);

  // Merge currentPageData with backupStatus
  const enrichedData = useMemo(() => {
    return (currentPageData || []).map(file => {
      const statusInfo = backupStatusMap[file.id];
      if (statusInfo) {
        return { ...file, progress_percentage: statusInfo.progress_percentage, status: statusInfo.status,
          completed_files: statusInfo.completed_files, total_files: statusInfo.total_files, downloaded_size: statusInfo.downloaded_size, total_size: statusInfo.total_size,
          is_completed: statusInfo.is_completed, hasProgressData: true
        };
      }
      return {
        ...file,
        hasProgressData: false
      };
    });
  }, [currentPageData, backupStatusMap]);

  const filteredData = searchTerm ? enrichedData.filter(file => file.folder_name.toLowerCase().includes(searchTerm.toLowerCase())) : enrichedData;

  // Determine eligible files for selection
  const eligibleFiles = filteredData.filter(file => file.status !== 'in-progress' && file.status !== 'pause' && file.status !== 'in_progress' );
  const allSelected = eligibleFiles.length > 0 && eligibleFiles.every(file => selectedFiles.includes(file.id));

  const handleSelectFile = (fileId) => {
    if (selectedFiles.includes(fileId)) {
      setSelectedFiles(selectedFiles.filter(id => id !== fileId));
    } else {
      setSelectedFiles([...selectedFiles, fileId]);
    }
  };

  const handleSelectAll = () => {
    const eligibleIds = eligibleFiles.map(f => f.id);
    if (allSelected) {
      setSelectedFiles(selectedFiles.filter(id => !eligibleIds.includes(id)));
    } else {
      setSelectedFiles([...selectedFiles, ...eligibleIds.filter(id => !selectedFiles.includes(id))]);
    }
  };

  const handleDelteAll = async () => {
    const confirmed = await alertService.confirm("This can't be undone.", "Are you sure to delete this?");
    if (!confirmed) {
      return;
    }
    setIsDeleting(true);
    let deleteData;
    deleteData = { ids: [], is_delete: true };
    await handleDelete(deleteData);
    setSelectedFiles([]);
    setIsDeleting(false);
  };

  const handleBulkDelete = async () => {
    const confirmed = await alertService.confirm("This can't be undone.", "Are you sure to delete this?");
    if (!confirmed) {
      return;
    }
    if (selectedFiles.length > 0) {
      setIsDeleting(true);
      let deleteData;
      const ids = selectedFiles;
      deleteData = { ids: ids, is_delete: false };
      await handleDelete(deleteData);
      setSelectedFiles([]);
      setIsDeleting(false);
    }
  };

  const handleViewClick = (file) => {
    if (file.status === 'in-progress' || file.status === 'pause' || file.status === 'in_progress') {
      return;
    }
    
    // Navigate with backupType as state
    navigate(`/dashboard/backup/backupfiles/${encodeURIComponent(file.folder_name)}&${encodeURIComponent(file.id)}&${encodeURIComponent(activeTab.toLowerCase())}`, {
      state: { backupType: file.backupType, fileId: file.id }
    });
  };

  const formatStatus = (status) => {
    if (!status) return 'Unknown';    
    return status.replace('_', '-');
  };

  const getColumns = () => {
    const columns = [ <CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={filteredData.length === 0}/>, 'Folder Name', 'Folder Size', 'Creation Time', 'Backup Type'];
        
    if (backupStatus && Array.isArray(backupStatus) && backupStatus.length > 0) {
      columns.push('Progress');
    }
    
    columns.push('Status', 'Action');
    return columns;
  };

  const renderRow = (file, index) => {
    const isInProgress = file.status === 'in-progress' || file.status === 'pause' || file.status === 'in_progress';
    const progressPercentage = file.progress_percentage ?? 0;
    
    return (
      <>
        <td className="px-3 py-4 whitespace-nowrap text-sm text-black">
          <CheckboxField checked={selectedFiles.includes(file.id)} onChange={() => handleSelectFile(file.id)} disabled={isInProgress}/>        
        </td>
        <td className="px-3 py-4 whitespace-nowrap text-sm text-black">{file.folder_name}</td>
        <td className="px-3 py-4 whitespace-nowrap text-sm text-black">
          {file.downloaded_size && file.total_size 
            ? `${file.downloaded_size} / ${file.total_size}` 
            : file.folder_size}
        </td>
        <td className="px-3 py-4 whitespace-nowrap text-sm text-black">{file.creation_time}</td>
        <td className="px-3 py-4 whitespace-nowrap text-sm text-black">{file.backupType}</td>
        
        {/* Progress Column - Only render if backupStatus exists */}
        {backupStatus && Array.isArray(backupStatus) && backupStatus.length > 0 && (
          <td className="px-3 py-4 whitespace-nowrap text-sm text-black">
            {file.hasProgressData ? (
              <div>
                <div className="flex items-center space-x-2">
                  <div className="w-24 bg-gray-200 rounded-full h-2.5">
                    <div className={`h-2.5 rounded-full transition-all duration-300 ${ progressPercentage === 100 ? 'bg-green-500' : 'bg-blue-500'}`} style={{ width: `${progressPercentage}%` }} ></div>
                  </div>
                  <span className="text-xs font-medium">{progressPercentage}%</span>
                </div>
                {file.completed_files !== undefined && file.total_files !== undefined && (
                  <div className="text-xs text-gray-500 mt-1">
                    {file.completed_files}/{file.total_files} files
                  </div>
                )}
                <div className="text-xs text-blue-600 mt-1 italic">
                  Migration backup in progress
                </div>
              </div>
            ) : (
              <span className="text-gray-400 text-lg">----</span>
            )}
          </td>
        )}

        {/* Status Column */}
        <td className="px-3 py-4 whitespace-nowrap text-sm">
          <span className={`px-2 py-1 rounded text-xs font-medium ${ file.status === 'completed' ? 'bg-green-100 text-green-800' : file.status === 'in_progress' || file.status === 'in-progress' ? 'bg-blue-100 text-blue-800' : file.status === 'pause' ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800' }`}>
            {formatStatus(file.status)}
          </span>
        </td>
        
        <td className="px-3 py-4 whitespace-nowrap text-sm text-black">
          <div className="flex items-center space-x-2">
            <ActionButton defaultText="View" isDisabled={isInProgress || deletingFileId} onClick={() => handleViewClick(file)}/>
            <span data-tooltip-id="export-coming-soon-tooltip" data-tooltip-content="Coming Soon" data-tooltip-place="top" className="cursor-not-allowed inline-block">
              <IconButton icon={BiExport} text="Export" disabled={true} />
            </span>
            <span data-tooltip-id="restore-coming-soon-tooltip" data-tooltip-content="Coming Soon" data-tooltip-place="top" className="cursor-not-allowed inline-block">
              <IconButton icon={MdSettingsBackupRestore} text="Restore" disabled={true} />
            </span>
            <ReactTooltip id="export-coming-soon-tooltip" className="!bg-black !text-white !py-2 !px-4 !rounded" />
            <ReactTooltip id="restore-coming-soon-tooltip" className="!bg-black !text-white !py-2 !px-4 !rounded" />
          </div>
        </td>
      </>
    );
  };

  return (
    <div>
      <TableControlStrip handleDeleteAll={handleDelteAll} disbaled={filteredData.length === 0 || filteredData.some(file => file.status === 'in-progress' || file.status === 'pause' || file.status === 'in_progress')} 
        selectedFilesCount={selectedFiles.length} handleBulkDelete={handleBulkDelete} isDeleting={isDeleting} searchTerm={searchTerm} setSearchTerm={setSearchTerm} hasEligibleFiles={eligibleFiles.length > 0} />
      <Table columns={getColumns()} data={filteredData} renderRow={renderRow} noDataMessage="No data available" />
    </div>
  );
};

export default BackupContent;