import React, { useEffect, useState, useMemo } from 'react';
import { useParams } from 'react-router-dom';
import { IoIosArrowDown } from "react-icons/io";
import { Spinner } from '../../../../Components/Spinner/Spinner';
import LoaderSkeleton from '../../../../Components/Skeleton/LoaderSkeleton';
import TableControlStrip from '../../../../Components/TableControlStrip/TableControlStrip';
import Table from '../../../../Components/Table/Table';
import { Tooltip } from '../../../../Components/ToolTip/Tooltip';
import Pagination from '../../../../Components/Pagination/Pagination';
import { fetchLogData } from '../../FileMoniterServices/FileMoniterServices';
import { useNavigate } from 'react-router-dom';
import Header from '../../../../Components/Header/Header';
const MoniteringDetails = ({ logId }) => {

  const navigate = useNavigate();
  const { id: paramId } = useParams();
  const [logData, setLogData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [expandedRow, setExpandedRow] = useState(null);
  const [searchTerm, setSearchTerm] = useState('');
  const [currentPage, setCurrentPage] = useState(0);
  const [pageSize, setPageSize] = useState(5);
  const [paginationData, setPaginationData] = useState(null);
  const [fileStatus, setFileStatus] = useState('all');

  const extractIdFromSlug = () => {
    const hash = window.location.hash;
    const match = hash.match(/\/dashboard\/integrity-watch\/filemonitering\/(\d+)/);
    return match ? match[1] : null;
  };

  const id = paramId ?? extractIdFromSlug() ?? logId;

  const formatBytes = (bytes) => {
    if (!bytes || isNaN(bytes)) return "-";
    const sizes = ["Bytes", "KB", "MB", "GB", "TB"];
    const i = Math.floor(Math.log(bytes) / Math.log(1024));
    return `${(bytes / Math.pow(1024, i)).toFixed(2)} ${sizes[i]}`;
  };

  const fetchData = (page = 0, limit = 5) => {
    if (!id) {
      setError('Invalid log ID');
      setLoading(false);
      return;
    }

    const customSetLogData = (responseData) => {
      if (responseData?.data && Array.isArray(responseData.data)) {
        setLogData(responseData.data);
      } else {
        setLogData(responseData.data || []);
      }
      if (responseData?.pagination) {
        setPaginationData(responseData.pagination);
      }
    };

    const customSetError = (error) => {
      setError(error);
      setPaginationData(null);
    };
    fetchLogData(customSetLogData, setLoading, customSetError, id, page + 1, limit, fileStatus);
  };

  useEffect(() => {
    fetchData(currentPage, pageSize);
  }, [id, currentPage, pageSize, fileStatus]);

  const filteredFiles = useMemo(() => {
    if (!logData || !Array.isArray(logData)) return [];

    if (!searchTerm.trim()) {
      return logData;
    }

    const searchLower = searchTerm.toLowerCase().trim();

    return logData.filter(file => {
      const fileName = file.file_name?.toLowerCase() || '';
      const filePath = file.file_path?.toLowerCase() || '';
      const status = file.status?.toLowerCase() || '';

      return fileName.includes(searchLower) ||
        filePath.includes(searchLower) ||
        status.includes(searchLower);
    });
  }, [logData, searchTerm]);

  useEffect(() => {
    setExpandedRow(null);
  }, [searchTerm, currentPage]);

  const trimPath = (path) => {
    const maxLength = 80;
    if (path && path.length > maxLength) {
      return path.slice(0, maxLength) + '......';
    }
    return path;
  };

  const columns = ['Name', 'Path', 'Status', 'Action'];

  const renderRow = (file, index) => {
    return (
      <>
        <td className="p-3">
          <Tooltip message={file.file_name} position='right' >
            {trimPath(file.file_name)}
          </Tooltip>
        </td>

        <td className="p-3">
          <Tooltip message={file.file_path} position='right' >
            {trimPath(file.file_path)}
          </Tooltip>
        </td>

        <td className="p-3">
          <span className={`px-2 py-1 rounded-full text-xs font-medium ${file.status === 'new' ? 'bg-green-100 text-green-800' :
            file.status === 'modified' ? 'bg-yellow-100 text-yellow-800' :
              file.status === 'deleted' ? 'bg-red-100 text-red-800' :
                'bg-gray-100 text-gray-800'
            }`}>
            {file.status.charAt(0).toUpperCase() + file.status.slice(1)
            }
          </span>
        </td>

        <td className="p-3">
          <div className="text-[#111418] hover:text-blue-600 transition-colors cursor-pointer">
            <IoIosArrowDown className={`transform transition-transform ${expandedRow === index ? 'rotate-180' : ''}`} />
          </div>
        </td>
      </>
    );
  };

  const renderExpandedRow = (file, index) => {
    return (
      <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <p className="text-black text-sm font-normal leading-normal pb-2">
            <span className='font-medium'>Full Path:</span> {file.file_path || '-'}
          </p>
          <p className="text-black text-sm font-normal leading-normal pb-2">
            <span className='font-medium'>Folder Name:</span> {file.path_prefix || '-'}
          </p>
          <p className="text-black text-sm font-normal leading-normal pb-2">
            <span className='font-medium'>Status:</span> {file.status.charAt(0).toUpperCase() + file.status.slice(1) || '-'}
          </p>
          <p className="text-black text-sm font-normal leading-normal pb-2">
            <span className='font-medium'>Previous Size:</span> {formatBytes(file.previous_size)}
          </p>

          <p className="text-black text-sm font-normal leading-normal pb-2">
            <span className='font-medium'>Current Size:</span> {formatBytes(file.current_size)}
          </p>
        </div>
        <div>
          <p className="text-black text-sm font-normal leading-normal pb-2">
            <span className='font-medium'>Previous Modified:</span> {file.previous_modified || '-'}
          </p>
          <p className="text-black text-sm font-normal leading-normal pb-2">
            <span className='font-medium'>Current Modified:</span> {file.current_modified || '-'}
          </p>
          <p className="text-black text-sm font-normal leading-normal pb-2">
            <span className='font-medium'>Previous Permission:</span> {file.previous_permission || '-'}
          </p>
          <p className="text-black text-sm font-normal leading-normal pb-2">
            <span className='font-medium'>Current Permission:</span> {file.current_permission || '-'}
          </p>
        </div>
      </div>
    );
  };

  const handleRowClick = (index) => {
    if (expandedRow === index) {
      setExpandedRow(null);
    } else {
      setExpandedRow(index);
    }
  };

  const handlePageChange = (page) => {
    setCurrentPage(page);
  };

  const handlePageSizeChange = (size) => {
    setPageSize(size);
    setCurrentPage(0);
  };

  const renderFilesTable = (files) => {
    const noDataMessage = searchTerm
      ? `No files found matching "${searchTerm}"`
      : fileStatus !== 'all'
        ? `No ${fileStatus} files found`
        : "No files available";

    return (
      <div>
        <TableControlStrip filterTitle={true} showControls={false} searchTerm={searchTerm} setSearchTerm={setSearchTerm} showIntegrityDropdown={true} file_status={fileStatus} onintegrityStatusChange={(value) => { setFileStatus(value); setCurrentPage(0); }} />
        <div className="overflow-x-auto custom-scroll-bar">
          <Table columns={columns} data={files} renderRow={renderRow} renderExpandedRow={renderExpandedRow} expandedRow={expandedRow} onRowClick={handleRowClick} noDataMessage={noDataMessage} />
        </div>
        {paginationData && filteredFiles.length > 0 && (
          <Pagination currentPage={currentPage} totalPages={paginationData.total_pages} onPageChange={handlePageChange} hasData={filteredFiles.length > 0} showPageSizeFilter={true} pageSize={pageSize} onPageSizeChange={handlePageSizeChange} totalItems={paginationData.total} pageSizeOptions={[5, 10, 20, 50]} />
        )}
      </div>
    );
  };

  const hasActiveFilter = fileStatus !== 'all' || searchTerm.trim() !== '';
  const hasData = Array.isArray(logData) && logData.length > 0;

  // ✅ Professional Error State
  if (error) return (
    <div>
      <Header title="View Detail" showBackIcon={true} />
      <div className="flex flex-col items-center justify-center min-h-[calc(90vh-80px)] px-4">
        <div className="flex flex-col items-center gap-3 bg-orange-50 border border-orange-200 rounded-2xl px-10 py-8 max-w-md w-full text-center shadow-sm">
          <div className="flex items-center justify-center w-14 h-14 rounded-full bg-orange-100">
            <svg xmlns="http://www.w3.org/2000/svg" className="w-7 h-7 text-orange-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={2}>
              <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" />
            </svg>
          </div>
          <h3 className="text-base font-semibold text-orange-700">Something Went Wrong</h3>
          <p className="text-sm text-orange-500 leading-relaxed">{error || "An unexpected error occurred while loading the data. Please try again later."}</p>
          <button
            onClick={() => window.location.reload()}
            className="mt-2 px-5 py-2 text-sm font-medium text-white bg-orange-500 hover:bg-orange-600 rounded-lg transition-colors duration-200"
          >
            Try Again
          </button>
        </div>
      </div>
    </div>
  );

  return (
    <div>
      <Header title="View Detail" showBackIcon={true} />
      <div className='px-4 mt-4'>
        {loading ? (
            <LoaderSkeleton count='0' />
        ) : hasData || hasActiveFilter ? (
          <div>
            {renderFilesTable(filteredFiles)}
          </div>
        ) : (
          // ✅ Professional Empty State
          <div className="flex flex-col items-center justify-center min-h-[calc(100vh-80px)]">
            <div className="flex flex-col items-center gap-3 bg-orange-50 border border-orange-200 rounded-2xl px-10 py-8 max-w-md w-full text-center shadow-sm">
              <div className="flex items-center justify-center w-14 h-14 rounded-full bg-orange-100">
                <svg xmlns="http://www.w3.org/2000/svg" className="w-7 h-7 text-orange-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" strokeWidth={1.5}>
                  <path strokeLinecap="round" strokeLinejoin="round" d="M3 7h18M3 12h18M3 17h10" />
                </svg>
              </div>
              <h3 className="text-base font-semibold text-orange-700">Nothing Captured</h3>
              <p className="text-sm text-orange-400 leading-relaxed">
                {searchTerm
                  ? `No files matched your search for "${searchTerm}". Try adjusting your filters or search term.`
                  : "No files changes detected in this comparison."}
              </p>
            </div>
          </div>
        )}
      </div>
    </div>
  );
};

export default MoniteringDetails;