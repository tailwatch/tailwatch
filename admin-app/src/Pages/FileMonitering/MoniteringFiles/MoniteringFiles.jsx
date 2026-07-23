import React, { useState, useEffect, useMemo } from "react";
import LoaderSkeleton from "../../../Components/Skeleton/LoaderSkeleton.jsx";
import Table from '../../../Components/Table/Table.jsx';
import Pagination from "../../../Components/Pagination/Pagination.jsx";
import { fetchFileLogs, handleDelete } from '../FileMoniterServices/FileMoniterServices.jsx';
import TableControlStrip from '../../../Components/TableControlStrip/TableControlStrip.jsx';
import { CheckboxField } from '../../../Components/Fields/CheckboxField.jsx';
import { alertService } from "../../../Components/AlertService/AlertService.js";
import ActionButton from "../../../Components/Buttons/ActionButton.jsx";
import { useNavigate } from "react-router-dom";

const MoniteringFiles = ({ onSummaryDataUpdate }) => {
  const [loading, setLoading] = useState(true);
  const [tabData, setTabData] = useState([]);
  const [currentPage, setCurrentPage] = useState(0);
  const [pageSize, setPageSize] = useState(10);
  const [pagination, setPagination] = useState({ page: 1, limit: 10, total_pages: 0, total_items: 0 });
  const [deletingFileId, setDeletingFileId] = useState(null);
  const [selectedFiles, setSelectedFiles] = useState([]);
  const [isDeleting, setIsDeleting] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const navigate = useNavigate();

  // Filter tabData based on search term for local search
  const filteredTabData = useMemo(() => {
    if (!searchTerm.trim()) {
      return tabData;
    }

    const searchLower = searchTerm.toLowerCase();
    return tabData.filter(log => {
      const scanTime = log.scan_time?.toLowerCase() || '';
      const folderName = log.folder_name?.toLowerCase() || '';

      return scanTime.includes(searchLower) || folderName.includes(searchLower);
    });
  }, [tabData, searchTerm]);

  const loadMoniteringFiles = async () => {
    try {
      const apiPage = currentPage + 1;
      await fetchFileLogs(setLoading, setTabData, setPagination, apiPage, pageSize);
    } catch (error) {
    }
  };

  useEffect(() => {
    loadMoniteringFiles();
  }, [currentPage, pageSize]);

  useEffect(() => {
    if (tabData.length > 0) {
      const latestLog = tabData[0];
      const newScanFiles = latestLog.new_scan_file || 0;
      const totalCapturedFiles = latestLog.total_captured_file || 0;
      onSummaryDataUpdate({ newScanFiles, totalCapturedFiles });
    }
  }, [tabData, onSummaryDataUpdate]);

  useEffect(() => {
    if (currentPage !== 0) {
      setCurrentPage(0);
    }
  }, [searchTerm]);

  useEffect(() => {
    setSelectedFiles([]);
  }, [currentPage, searchTerm, pageSize]);

  const allSelected = filteredTabData.length > 0 && filteredTabData.every(log => selectedFiles.includes(log.id));

  const handleSelectFile = (fileId) => {
    if (selectedFiles.includes(fileId)) {
      setSelectedFiles(selectedFiles.filter(id => id !== fileId));
    } else {
      setSelectedFiles([...selectedFiles, fileId]);
    }
  };

  const handleSelectAll = () => {
    const currentPageIds = filteredTabData.map(f => f.id);
    if (allSelected) {
      setSelectedFiles(selectedFiles.filter(id => !currentPageIds.includes(id)));
    } else {
      setSelectedFiles([...selectedFiles, ...currentPageIds.filter(id => !selectedFiles.includes(id))]);
    }
  };

  const handleDeleteAll = async () => {
    const confirmed = await alertService.confirm("This can't be undone. it removes all data from the database", "Are you sure to delete all data ?");
    if (!confirmed) {
      return;
    }
    setIsDeleting(true);
    let deleteData = { ids: [], is_delete: true };

    try {
      await handleDelete(deleteData, setTabData, setDeletingFileId);
      setSelectedFiles([]);
      setCurrentPage(0); // Reset to first page after deleting all
      // Reload data to reflect changes
      loadMoniteringFiles();
    } catch (error) {
    } finally {
      setIsDeleting(false);
    }
  };

  const handleBulkDelete = async () => {
    const confirmed = await alertService.confirm("This can't be undone.", "Are you sure to delete ?");
    if (!confirmed) {
      return;
    }
    if (selectedFiles.length > 0) {
      setIsDeleting(true);
      let deleteData = { ids: selectedFiles, is_delete: false };

      try {
        await handleDelete(deleteData, setTabData, setDeletingFileId);
        setSelectedFiles([]);
        const remainingItems = pagination.total_items - selectedFiles.length;
        const maxPage = Math.ceil(remainingItems / pageSize) - 1;
        if (currentPage > maxPage && maxPage >= 0) {
          setCurrentPage(maxPage);
        } else {
          // Reload current page data
          loadMoniteringFiles();
        }
      } catch (error) {
      } finally {
        setIsDeleting(false);
      }
    }
  };

  const handlePageChange = (newPage) => {
    setCurrentPage(newPage);
  };

  const handlePageSizeChange = (newPageSize) => {
    setPageSize(newPageSize);
    setCurrentPage(0);
  };

  const handleSearchChange = (newSearchTerm) => {
    setSearchTerm(newSearchTerm);
  };

  const trimTitle = (path) => {
    if (!path) return "-";
    const maxLength = 20;
    return path.length > maxLength ? path.slice(0, maxLength) + '.....' : path;
  };

  const columns = [<CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={filteredTabData.length === 0} />, "Sr", "Date Time", "Folder Name", "Path", "Total Files", "Captured Files", "Malware Status", "Suspicious File Handling", "View"];

  const renderSuspiciousHandling = (raw) => {
    if (raw == null || raw === '') {
      return <span className="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full bg-gray-100 text-gray-700">—</span>;
    }
    const value = String(raw).toLowerCase();
    const isYes = value === 'yes' || value === 'true' || value === '1' || value === 'enabled';
    const isNo = value === 'no' || value === 'false' || value === '0' || value === 'disabled';
    const label = isYes ? 'Yes' : isNo ? 'No' : String(raw).charAt(0).toUpperCase() + String(raw).slice(1);
    const classes = isYes
      ? 'bg-emerald-100 text-emerald-800'
      : isNo
        ? 'bg-rose-100 text-rose-800'
        : 'bg-gray-100 text-gray-700';
    return (
      <span className={`px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${classes}`}>
        {label}
      </span>
    );
  };

  const renderRow = (log, index) => (
    <>
      <td className="px-3 py-4 whitespace-nowrap text-sm text-black">
        <CheckboxField checked={selectedFiles.includes(log.id)} onChange={() => handleSelectFile(log.id)} />
      </td>
      <td className="px-3 py-4 whitespace-nowrap text-sm text-black">
        {log.sr}
      </td>
      <td className="px-3 py-4 whitespace-nowrap text-sm text-black">
        {log.scan_time}
      </td>
      <td className="px-3 py-4 whitespace-nowrap text-sm text-black">
        {log.folder_name}
      </td>
      <td className="px-3 py-4 whitespace-nowrap text-sm text-black" title={log.path}>
        {trimTitle(log.path)}
      </td>
      <td className="px-3 py-4 whitespace-nowrap text-sm">
        <span className={`px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${log.new_scan_file > 0
          ? 'bg-purple-100 text-purple-800'
          : 'bg-gray-100 text-gray-800'
          }`}>
          {log.new_scan_file || 0}
        </span>
      </td>
      <td className="px-3 py-4 whitespace-nowrap text-sm">
        <span className={`px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${log.total_captured_file > 0
          ? 'bg-indigo-100 text-indigo-800'
          : 'bg-gray-100 text-gray-800'
          }`}>
          {log.total_captured_file || 0}
        </span>
      </td>
      <td className="px-3 py-4 whitespace-nowrap text-sm">
        <span className={`px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${log.malware_status === 'Clean' || log.malware_status === 'Safe'
          ? 'bg-green-100 text-green-800'
          : log.malware_status === 'Infected' || log.malware_status === 'Malicious'
            ? 'bg-red-100 text-red-800'
            : log.malware_status === 'Suspicious' || log.malware_status === 'Warning'
              ? 'bg-yellow-100 text-yellow-800'
              : 'bg-gray-100 text-gray-800'
          }`}>
          {log.malware_status || '-'}
        </span>
      </td>
      <td className="px-3 py-4 whitespace-nowrap text-sm">
        {renderSuspiciousHandling(log.suspicious_handling)}
      </td>
      <td className="px-3 py-4 whitespace-nowrap text-sm text-black">
        <div className="flex items-center space-x-2">
          <ActionButton defaultText="View" isDisabled={deletingFileId} onClick={() => navigate(`/dashboard/integrity-watch/filemonitering/${encodeURIComponent(log.id)}`)} />
        </div>
      </td>
    </>
  );

  return (
    <div className="px-4">
      {loading ? (
        <LoaderSkeleton count={0} />
      ) : (
        <>
          <TableControlStrip handleDeleteAll={handleDeleteAll} disbaled={filteredTabData.length === 0} selectedFilesCount={selectedFiles.length} handleBulkDelete={handleBulkDelete} isDeleting={isDeleting} searchTerm={searchTerm} setSearchTerm={handleSearchChange} hasEligibleFiles={filteredTabData.length > 0} />
          <Table columns={columns} data={filteredTabData} renderRow={renderRow} noDataMessage="No file logs found" />
          {pagination.total_items > 0 && (
            <Pagination currentPage={currentPage} totalPages={pagination.total_pages} onPageChange={handlePageChange} hasData={filteredTabData.length > 0} showPageSizeFilter={true} pageSize={pageSize} onPageSizeChange={handlePageSizeChange} totalItems={pagination.total_items} pageSizeOptions={[5, 10, 20, 50]} />
          )}
        </>
      )}
    </div>
  );
};

export default MoniteringFiles;
