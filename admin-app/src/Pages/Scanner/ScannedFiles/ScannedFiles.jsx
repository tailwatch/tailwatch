import React, { useState, useEffect } from "react";
import LoaderSkeleton from "../../../Components/Skeleton/LoaderSkeleton.jsx";
import Table from '../../../Components/Table/Table.jsx';
import Pagination from "../../../Components/Pagination/Pagination.jsx";
import TableControlStrip from "../../../Components/TableControlStrip/TableControlStrip.jsx";
import { toast } from 'react-toastify';
import Skeleton from "react-loading-skeleton";
import { fetchMalwareData, handleBulkDeleteMalware } from "../ScannerServices/ScannerServices.jsx";
import { UseScannerFeature } from "../../../Components/Hooks/Features/UseFeatures.jsx";
import ActionButton from "../../../Components/Buttons/ActionButton.jsx";
import { useSelector } from 'react-redux';
import { useNavigate } from "react-router-dom";
import { alertService } from "../../../Components/AlertService/AlertService.js";
const CheckboxField = ({ checked, onChange, disabled }) => (
  <input
    type="checkbox"
    checked={checked}
    onChange={onChange}
    disabled={disabled}
    className="w-4 h-4 border-gray-400 rounded cursor-pointer focus:ring-2 focus:ring-[#2271b1] disabled:opacity-50 disabled:cursor-not-allowed"
  />
);

// WordPress-style Button Component
const WPButton = ({ children, onClick, disabled }) => (
  <button
    onClick={onClick}
    disabled={disabled}
    className="text-[#2271b1] hover:text-[#135e96] text-sm font-normal hover:underline disabled:text-gray-400 disabled:no-underline disabled:cursor-not-allowed"
  >
    {children}
  </button>
);
const ScannedFiles = ({ onScannedDataUpdate, isScanInProgress, confirms, setIsLicenseConnect, setIsLicenseReq, onViewDetails }) => {
  const [loading, setLoading] = useState(true);
  const [malwareData, setMalwareData] = useState([]);
  const navigate = useNavigate();
  const [deletingFileId, setDeletingFileId] = useState(null);
  const [currentPage, setCurrentPage] = useState(0);
  const [pagination, setPagination] = useState({ page: 1, limit: 10, total: 0, total_pages: 0 });
  const [selectedFiles, setSelectedFiles] = useState([]);
  const [searchTerm, setSearchTerm] = useState("");
  const [isDeleting, setIsDeleting] = useState(false);
  const [infectedData, setInfectedData] = useState([]);
  const isMalwareStarted = useSelector((state) => state.scan.malwareStarted);

  const loadMalwareData = async (page = 1, limit = pagination.limit) => {
    setLoading(true);
    try {
      await fetchMalwareData(setLoading, setMalwareData, page, limit, setPagination, setIsLicenseConnect, setIsLicenseReq);
    } catch (error) {
    } finally {
      setLoading(false);
    }
  };
  useEffect(() => {
    if (malwareData.length > 0) {
      const latestLog = malwareData[0];
      const maliciousFiles = latestLog.total_malicious || 0;
      const totalResources = latestLog.total_resources || 0;
      onScannedDataUpdate({ maliciousFiles, totalResources });
    }
  }, [malwareData, onScannedDataUpdate]);

  useEffect(() => {
    loadMalwareData(1);
  }, []);

  const handlePageChange = (page) => {
    setCurrentPage(page);
    setSelectedFiles([]);
    loadMalwareData(page + 1, pagination.limit);
  };

  const handlePageSizeChange = (newLimit) => {
    setPagination(prev => ({ ...prev, limit: newLimit }));
    setCurrentPage(0);
    setSelectedFiles([]);
    loadMalwareData(1, newLimit);
  };

  const handleDeleteAll = async () => {
    const confirmed = await alertService.confirm(
      confirms?.delete_all?.body || "This will permanently delete all scanned files from the database. This action cannot be undone.",
      confirms?.delete_all?.title || "Delete All Scanned Files?",
      "Delete All",
      "Cancel"
    );

    if (confirmed) {
      setIsDeleting(true);
      try {
        const success = await handleBulkDeleteMalware(true, []);
        if (success) {
          setSelectedFiles([]);
          loadMalwareData(1);
        }
      } catch (error) {
        toast.error("Error deleting files");
      } finally {
        setIsDeleting(false);
      }
    }
  };

  const handleBulkDelete = async () => {
    if (selectedFiles.length === 0) return;

    const confirmed = await alertService.confirm(
      (confirms?.delete_selected?.body || `This will permanently delete {count} selected file(s) from the database. This action cannot be undone.`).replace("{count}", selectedFiles.length),
      confirms?.delete_selected?.title || "Delete Selected Files?",
      "Delete",
      "Cancel"
    );

    if (confirmed) {
      setIsDeleting(true);
      try {
        const success = await handleBulkDeleteMalware(false, selectedFiles);
        if (success) {
          setSelectedFiles([]);
          loadMalwareData(currentPage + 1);
        }
      } catch (error) {
        toast.error("Error deleting selected files");
      } finally {
        setIsDeleting(false);
      }
    }
  };

  const handleSelectAll = (e) => {
    if (e.target.checked) {
      const allPids = malwareData.map((file) => file.pid);
      setSelectedFiles(allPids);
    } else {
      setSelectedFiles([]);
    }
  };

  const handleSelectRow = (pid) => {
    setSelectedFiles((prev) => {
      if (prev.includes(pid)) {
        return prev.filter((id) => id !== pid);
      } else {
        return [...prev, pid];
      }
    });
  };

  const isAllSelected = malwareData.length > 0 && selectedFiles.length === malwareData.length;

  const columns = [
    <CheckboxField checked={isAllSelected} onChange={handleSelectAll} disabled={isDeleting || isMalwareStarted || malwareData.length === 0} />,
    "Start Time",
    "Total Resources",
    "Infected Resources",
    "Event",
    "Deployment",
    "Status",
    "Actions"
  ];

  const renderRow = (log) => {
    const isSelected = selectedFiles.includes(log.pid);
    return (
      <>
        <td className="px-3 py-3">
          <CheckboxField
            checked={isSelected || false}
            onChange={() => handleSelectRow(log.pid)}
            disabled={isDeleting || isMalwareStarted}
          />
        </td>

        <td className="px-3 py-3">
          <div className="text-sm text-gray-900">
            {new Date(log.start_time).toLocaleDateString('en-US', {
              month: 'short',
              day: 'numeric',
              year: 'numeric'
            })}
          </div>
          <div className="text-xs text-gray-600 mt-0.5">
            {new Date(log.start_time).toLocaleTimeString('en-US', {
              hour: '2-digit',
              minute: '2-digit'
            })}
          </div>
        </td>

        <td className="px-3 py-3">
          <div className="text-sm text-gray-900">
            <strong>{log?.total_files_scanned?.toLocaleString() || 0}</strong>
            <span className="text-gray-600 ml-1">Files</span>
          </div>
          <div className="text-sm text-gray-900 mt-1">
            <strong>{log?.total_db_rows_scanned?.toLocaleString() || 0}</strong>
            <span className="text-gray-600 ml-1">DB Rows</span>
          </div>
        </td>

        <td className="px-3 py-3">
          <div className="text-sm">
            <span className={log?.total_files_malicious > 0 ? "text-[#d63638] font-semibold" : "text-gray-900"}>
              {log?.total_files_malicious?.toLocaleString() || 0}
            </span>
            <span className={log?.total_files_malicious > 0 ? "text-[#d63638] ml-1" : "text-gray-600 ml-1"}>
              Files
            </span>
          </div>
          <div className="text-sm mt-1">
            <span className={log?.total_db_malicious > 0 ? "text-[#d63638] font-semibold" : "text-gray-900"}>
              {log?.total_db_malicious?.toLocaleString() || 0}
            </span>
            <span className={log?.total_db_malicious > 0 ? "text-[#d63638] ml-1" : "text-gray-600 ml-1"}>
              DB Rows
            </span>
          </div>
        </td>

        <td className="px-3 py-3 space-y-2">

          {/* Scan Type */}
          <div className="flex items-center gap-2">
            <span className="text-xs font-medium bg-blue-100 text-blue-800 px-2 py-1 rounded">
              {log?.scan_type
                ? log.scan_type.charAt(0).toUpperCase() + log.scan_type.slice(1)
                : '- -'} Run
            </span>
          </div>

        </td>

        <td className="px-3 py-3">
          <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-normal border bg-[#f3f1f5] text-[#2c3338] border-[#d5cfe1]">
            {log?.can_restore ? 'Not Deployed' : 'Already Deployed'}
          </span>
        </td>
        <td className="px-3 py-3">
          <div className="flex flex-col gap-1 items-start">
            <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-normal border bg-[#f3f1f5] text-[#2c3338] border-[#d5cfe1]">
              {log?.scan_state ? log?.scan_state.charAt(0).toUpperCase() + log?.scan_state.slice(1) : '-'}
            </span>
            {log?.requires_manual_action === true && (
              <span className="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium border bg-orange-100 text-orange-800 border-orange-200">
                Manual action required
              </span>
            )}
          </div>
        </td>

         <td className="px-4 py-4 whitespace-nowrap">
          <div className="flex items-center space-x-2">
            {deletingFileId === log.pid ? (
              <Skeleton height={40} width={80} />
            ) : (
              <>
                <ActionButton defaultText="View" isDisabled={isMalwareStarted}
                  onClick={() => {
                    if (!(deletingFileId || isMalwareStarted)) {
                      navigate(`/dashboard/malwarescanner/malwaredetails/${encodeURIComponent(log.pid)}`);
                    }
                  }}
                />
              </>
            )}
          </div>
        </td>
      </>
    );
  };

  return (
    <div className="px-4">
      <TableControlStrip deleteButton={true} disbaled={isDeleting || malwareData.length === 0 || isMalwareStarted}
        selectedFilesCount={selectedFiles.length} handleDeleteAll={handleDeleteAll} handleBulkDelete={handleBulkDelete} isDeleting={isDeleting} searchTerm={searchTerm} setSearchTerm={setSearchTerm}
        showDeleteButton={true} showControls={true}
      />
      {loading ? (
        <LoaderSkeleton count={0} />
      ) : (
        <>
          <Table columns={columns} data={malwareData} renderRow={renderRow} noDataMessage="No data available" />
          <Pagination currentPage={currentPage} totalPages={pagination.total_pages} hasData={malwareData.length > 0} onPageChange={handlePageChange} onPageSizeChange={handlePageSizeChange} totalItems={pagination.total} pageSize={pagination.limit} showPageSizeFilter={true} />
        </>
      )}
    </div>
  );
}

export default ScannedFiles