import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { IoIosArrowDown } from "react-icons/io";
import { alertService } from '../../../../Components/AlertService/AlertService.js';
import { FaEye } from "react-icons/fa";
import axios from 'axios';
import Header from '../../../../Components/Header/Header';
import { Spinner } from '../../../../Components/Spinner/Spinner';
import Table from '../../../../Components/Table/Table';
import PopUpModal from '../../../../Components/Modal/PopUpModal';
import { useNavigate } from 'react-router-dom';
import { viewInfectedContent } from '../../ScannerServices/ScannerServices.jsx';
import { Tooltip } from '../../../../Components/ToolTip/Tooltip';
/* global tailwatch_ajax */

const ScannedDetails = () => {
  const { pid: paramId } = useParams();
  const [scannedData, setScannedData] = useState(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState(null);
  const [expandedRow, setExpandedRow] = useState(null);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [selectedFilePath, setSelectedFilePath] = useState(null);
  const [fileContent, setFileContent] = useState(null);
  const [loadingContent, setLoadingContent] = useState(false);
  const [isRestore, setIsRestore] = useState(null);
  const [isRestoreMessage, setIsRestoreMessage] = useState(null);
  const navigate = useNavigate();
  // Function to extract ID from the URL slug
  const extractIdFromSlug = () => {
    const hash = window.location.hash;
    const match = hash.match(/\/dashboard\/malwarescanner\/malwaredetails\/([^/]+)/);
    return match ? match[1] : null;
  };

  // Extract the ID using the custom function
  const pid = paramId || extractIdFromSlug();

  useEffect(() => {
    const fetchMalwareDetail = async () => {
      setLoading(true);
      try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_get_malware_scanner_report_by_pid');
        formData.append('data', JSON.stringify({ pid }));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
          headers: {
            'Content-Type': 'multipart/form-data'
          }
        });
        
        if (response.data.success) {
          setScannedData(response.data.data.data);
          setIsRestore(response?.data?.data?.can_restore)
          setIsRestoreMessage(response?.data?.data?.restore_reason);
        } else {
          setError('Failed to fetch log data');
        }
      } catch (error) {
        setError('Error fetching log data');
      }
      setLoading(false);
    };

    if (pid) {
      fetchMalwareDetail();
    } else {
      setError('Invalid Scanned ID');
      setLoading(false);
    }
  }, [pid]);

  const startMalwareRestore = async (pid) => {
    const isConfirmed = await alertService.verify(
      "This process may take some time depending on the number of files being deployed. During deployment, your website’s landing page may temporarily enter maintenance mode (though this usually does not occur). Once the process has started, it cannot be undone.",
      "Deploy the files ?",
      "Yess",
      "Cancel"
    );
    if (isConfirmed) {
      try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_continue_malware_restore');
        formData.append('data', JSON.stringify({ pid }));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
          headers: {
            'Content-Type': 'multipart/form-data'
          }
        });

        if (response.data.success) {
          navigate('/dashboard/malwarescanner');
        } else {
          // onBack();
        }
      } catch (error) {
        setError('Error Start Malware Response');
      }
    }
  };

  const getFileStatusBadge = (status) => {
    switch (status) {
      case 'cleaned':
        return { label: 'Cleaned', bg: 'bg-green-100', text: 'text-green-800', pathColor: 'text-green-600' };
      case 'infected':
        return { label: 'Infected', bg: 'bg-red-100', text: 'text-red-800', pathColor: 'text-red-600' };
      case 'manual_review':
        return { label: 'Manual review needed', bg: 'bg-orange-100', text: 'text-orange-800', pathColor: 'text-orange-600' };
      case 'delete_failed':
        return { label: 'Removal failed', bg: 'bg-red-100', text: 'text-red-800', pathColor: 'text-red-600' };
      default:
        return { label: 'Unknown', bg: 'bg-gray-100', text: 'text-gray-800', pathColor: 'text-gray-600' };
    }
  };

  // Toggle row expansion
  const toggleRow = (index) => {
    if (expandedRow === index) {
      setExpandedRow(null);
    } else {
      setExpandedRow(index);
    }
  };

  // Handle view button click
  const handleViewClick = async (filePath) => {
    setSelectedFilePath(filePath);
    setIsModalOpen(true);
    setLoadingContent(true);
    setFileContent(null);

    const content = await viewInfectedContent(pid, filePath);
    if (content) {
      setFileContent(content);
    }
    setLoadingContent(false);
  };

  // Prepare table data from scanned results
  const prepareTableData = () => {
    if (!scannedData || !scannedData.scanned_results) return [];

    const tableData = [];

    // Add Files scan row
    if (scannedData.scanned_results.files) {
      const filesData = scannedData.scanned_results.files;
      tableData.push({
        type: 'Files',
        resourceType: 'File System',
        totalResources: filesData.total_scanned_files || 0,
        totalMalicious: filesData.total_malware_files || 0,
        scanCompletedAt: filesData.scan_completed_at || '-',
        scanningErrors: filesData.scanning_errors || 'None',
        status: filesData.status || '-',
        malwareFiles: filesData.malware_files || [],
        scanningType: filesData.scanning_type || 'file'
      });
    }

    // Add Database scan row
    if (scannedData.scanned_results.database) {
      const dbData = scannedData.scanned_results.database;
      tableData.push({
        type: 'Database',
        resourceType: 'Database',
        totalResources: dbData.database_rows_count || 0,
        totalMalicious: dbData.total_database_malicious || 0,
        scanCompletedAt: dbData.scan_completed_at || '-',
        scanningErrors: dbData.scanning_errors || 'None',
        status: dbData.status || '-',
        malwareReports: dbData.malware_reports || [],
        scanningType: dbData.scanning_type || 'db'
      });
    }

    return tableData;
  };

  // Render table row
  const renderRow = (rowData, index) => {
    return (
      <>
        <td className="px-6 py-4 whitespace-nowrap text-md font-medium text-gray-900">
          {rowData.resourceType}
        </td>
        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
          {rowData.totalResources}
        </td>
        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
          {rowData.totalMalicious}
        </td>
        <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
          <div
            className={`text-[#111418] transition-transform `}
          >
            <IoIosArrowDown />
          </div>
        </td>
      </>
    );
  };

  // Render expanded row content
  const renderExpandedRow = (rowData) => {
    return (
      <div className="space-y-3">
        {rowData.scanningErrors && rowData.scanningErrors !== 'None' && (
          <div>
            <p className="text-[#637588] text-sm font-semibold">Errors:</p>
            <p className="text-red-500 text-sm">{rowData.scanningErrors}</p>
          </div>
        )}

        {/* Display malicious files for file scan */}
        {rowData.type === 'Files' && (
          rowData.malwareFiles && rowData.malwareFiles.length > 0 ? (
            <div className="overflow-x-auto max-h-96 overflow-y-auto border border-red-200 rounded-md">
              <table className="min-w-full divide-y divide-red-200">
                <thead className="bg-red-50 sticky top-0">
                  <tr>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider w-16">
                      #
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                      Date Time
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                      File Path
                    </th>
                    <th className="px-4 py-3 text-left text-xs font-semibold text-gray-700 uppercase tracking-wider">
                      Status
                    </th>
                    <th className="px-4 py-3 text-center text-xs font-semibold text-gray-700 uppercase tracking-wider">
                      Action
                    </th>
                  </tr>
                </thead>
                <tbody className="bg-white divide-y divide-red-100">
                  {rowData.malwareFiles.map((maliciousFile, idx) => {
                    const isObject = typeof maliciousFile === 'object' && maliciousFile !== null;
                    const path = isObject ? maliciousFile.path : maliciousFile;
                    const status = isObject ? maliciousFile.status : 'unknown';
                    const badge = getFileStatusBadge(status);
                    return (
                      <tr key={idx} className="hover:bg-red-50 transition-colors">
                        <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-900 font-medium">
                          {idx + 1}
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap text-sm text-gray-900">
                          {rowData.scanCompletedAt}
                        </td>
                        <td className={`px-4 py-3 text-sm break-all ${badge.pathColor}`}>
                          {path}
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap text-sm">
                          <span className={`px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${badge.bg} ${badge.text}`}>
                            {badge.label}
                          </span>
                        </td>
                        <td className="px-4 py-3 whitespace-nowrap text-center text-sm">
                          <button
                            className="text-gray-600 hover:text-gray-800 transition-colors"
                            onClick={() => handleViewClick(path)}
                          >
                            <FaEye className="inline-block text-lg" />
                          </button>
                        </td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>
            </div>
          ) : (
            <div className="text-center py-4 text-gray-500">
              No Data Found
            </div>
          )
        )}

        {/* Display malware reports for database scan */}
        {rowData.type === 'Database' && (
          rowData.malwareReports && rowData.malwareReports.length > 0 ? (
            <div>
              <p className="text-[#637588] text-sm font-semibold pb-2">
                Malware Reports ({rowData.malwareReports.length}):
              </p>
              {rowData.malwareReports.map((report, idx) => (
                <div key={idx} className="bg-red-50 border border-red-200 rounded-md p-3 mb-2">
                  <div className="grid grid-cols-2 gap-2">
                    <div>
                      <p className="text-xs font-semibold text-gray-700">Status:</p>
                      <p className={`text-sm ${report.status === 'detected' ? 'text-red-600 font-semibold' : 'text-green-600'}`}>
                        {report.status ? report.status.charAt(0).toUpperCase() + report.status.slice(1) : '—'}
                      </p>
                    </div>
                    <div>
                      <p className="text-xs font-semibold text-gray-700">Reason:</p>
                      <p className="text-sm text-gray-900">{report.sigid}</p>
                    </div>
                  </div>
                  {report.snpt && (
                    <div className="mt-2">
                      <p className="text-xs font-semibold text-gray-700">Snippet:</p>
                      <p className="text-xs text-gray-900 bg-white p-2 rounded border border-gray-200 overflow-x-auto">
                        {report.snpt}
                      </p>
                    </div>
                  )}
                  {report.tables && report.tables.length > 0 && (
                    <div className="mt-2">
                      <p className="text-xs font-semibold text-gray-700">Affected Tables:</p>
                      <ul className="list-disc list-inside mt-1">
                        {report.tables.map((table, tIdx) => (
                          <li key={tIdx} className="text-sm text-gray-900">
                            {table.table} ({table.total_rows} rows, {table.queries} queries)
                          </li>
                        ))}
                      </ul>
                    </div>
                  )}
                </div>
              ))}
            </div>
          ) : (
            <div className="text-center py-4 text-gray-500">
              No Data Found
            </div>
          )
        )}
      </div>
    );
  };

  if (error) return <div>{error}</div>;

  const tableData = prepareTableData();
  const columns = ['Resource Type', 'Total Resources', 'Infected Resources', 'View'];

  return (
    <div>
      {/* Custom Back Button */}
      <Header title="View Details" showBackIcon={true} />
      <div className='mt-[15px]'>
        {loading ? (
          <div className="flex justify-center items-center h-[300px]">
            <Spinner />
          </div>
        ) : scannedData ? (
          <div className='px-4'>
            {/* Scan Information */}
            <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4 mb-4">
              <h2 className="text-lg font-semibold text-gray-900 mb-3">Scan Information</h2>
              {scannedData?.requires_manual_action === true ? (
                <div className="mb-4 bg-orange-50 border border-orange-200 rounded-lg p-4">
                  <div className="flex items-start">
                    <svg className="w-5 h-5 text-orange-600 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                      <path fillRule="evenodd" d="M8.485 2.495a1.75 1.75 0 013.03 0l6.28 10.875A1.75 1.75 0 0116.28 16H3.72a1.75 1.75 0 01-1.515-2.63l6.28-10.875zM10 7a.75.75 0 00-.75.75v3.5a.75.75 0 001.5 0v-3.5A.75.75 0 0010 7zm0 6.5a1 1 0 100 2 1 1 0 000-2z" clipRule="evenodd" />
                    </svg>
                    <div>
                      <h3 className="text-sm font-semibold text-orange-900 mb-1">Manual Action Required</h3>
                      <p className="text-sm text-orange-800">
                        Some files could not be cleaned automatically. Review the files flagged below — for files in protected locations, reinstall the plugin or contact support; for files marked “Removal failed,” fix the file permissions and try again.
                      </p>
                    </div>
                  </div>
                </div>
              ) : isRestore ? (
                <div className="mb-4 bg-blue-50 border border-blue-200 rounded-lg p-4">
                  <div className="flex items-start">
                    <svg className="w-5 h-5 text-blue-600 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                      <path fillRule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clipRule="evenodd" />
                    </svg>
                    <div>
                      <h3 className="text-sm font-semibold text-blue-900 mb-1">Files Ready to Deploy</h3>
                      <p className="text-sm text-blue-800">
                        The malicious content has been removed, but the cleaned files have not yet been applied to your system. Click “Deploy Files” to apply the changes. This action will expire upon a new scan.
                      </p>
                    </div>
                  </div>
                </div>
              ) : (
                <div className="mb-4 bg-green-50 border border-green-200 rounded-lg p-4">
                  <div className="flex items-start">
                    <svg className="w-5 h-5 text-green-600 mt-0.5 mr-3 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                      <path fillRule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clipRule="evenodd" />
                    </svg>
                    <div>
                      <h3 className="text-sm font-semibold text-green-900 mb-1">Malware Cleanup Completed</h3>
                      <p className="text-sm text-green-800">
                        All malicious content has been removed, and the cleaned files have been deployed successfully.
                      </p>
                    </div>
                  </div>
                </div>
              )}
              <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                  <p className="text-xs text-gray-500 font-semibold">Process ID</p>
                  {scannedData.pid ? (
                    <Tooltip message={scannedData.pid} className="!max-w-[80ch] !font-mono !text-xs">
                      <p className="text-sm text-gray-900 cursor-help break-all">
                        {scannedData.pid.length > 35 ? `${scannedData.pid.slice(0, 35)}…` : scannedData.pid}
                      </p>
                    </Tooltip>
                  ) : (
                    <p className="text-sm text-gray-900">-</p>
                  )}
                </div>
                <div>
                  <p className="text-xs text-gray-500 font-semibold">Status</p>
                  <p className={`text-sm font-semibold text-green-600`}>
                    {scannedData?.scan_state?.charAt(0).toUpperCase() + scannedData?.scan_state?.slice(1) || '-'}
                  </p>
                </div>
                <div>
                  <p className="text-xs text-gray-500 font-semibold">Started Time</p>
                  <p className="text-sm text-gray-900">{scannedData.started_time || '-'}</p>
                </div>
                <div>
                  <p className="text-xs text-gray-500 font-semibold">Completion Time</p>
                  <p className="text-sm text-gray-900">{scannedData.completion_time || '-'}</p>
                </div>
                <div>
                  <p className="text-xs text-gray-500 font-semibold">Duration</p>
                  <p className="text-sm text-gray-900">{scannedData.duration || '-'}</p>
                </div>
              </div>

              <div className="mt-4 flex justify-end">
                <div className="relative group">
                  <button
                    onClick={() => { startMalwareRestore(scannedData.pid) }}
                    disabled={!isRestore}
                    className={`px-4 py-2 rounded font-medium transition-colors ${isRestore
                      ? 'bg-blue-600 hover:bg-blue-700 text-white cursor-pointer'
                      : 'bg-gray-300 text-gray-500 cursor-not-allowed'
                      }`}
                  >
                    Deploy Files
                  </button>
                  {!isRestore && isRestoreMessage && (
                    <div className="absolute bottom-full mb-2 right-0 hidden group-hover:block w-64 bg-gray-900 text-white text-xs rounded py-2 px-3 z-10">
                      {isRestoreMessage}
                      <div className="absolute top-full right-4 -mt-1 border-4 border-transparent border-t-gray-900"></div>
                    </div>
                  )}
                </div>
              </div>
            </div>

            <div className="bg-white rounded-lg shadow-sm border border-gray-200 p-4">
              <h2 className="text-lg font-semibold text-gray-900 mb-3">Scan Results</h2>
              <Table columns={columns} data={tableData} renderRow={renderRow} expandedRow={expandedRow} renderExpandedRow={renderExpandedRow} onRowClick={toggleRow} noDataMessage="No scan results available"
              />
            </div>
          </div>
        ) : (
          <p className="text-center mt-4 text-gray-500">No data available</p>
        )}
      </div>

      {/* Modal for viewing infected file content */}
      {isModalOpen && (
        <PopUpModal
          title="View File"
          onClose={() => setIsModalOpen(false)}
          showCloseIcon={true}
          width="w-full max-w-6xl"
          height="max-h-[90vh]"
          loading={loadingContent}
        >
          {fileContent ? (
            <div className="space-y-4">
              {(fileContent?.file_status === 'Cleaned' || fileContent?.file_status === 'Clean') && (
                <div className="bg-green-50 border border-green-300 rounded-lg p-4 flex items-start gap-3">
                  <svg className="w-6 h-6 text-green-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                  </svg>
                  <div className="flex-1">
                    <p className="text-sm font-semibold text-green-900">✓ Malware Removed</p>
                    <p className="text-xs text-green-700 mt-1">
                      The malicious code in this file has been removed, and the cleaned version has already been deployed.
                    </p>
                  </div>
                </div>
              )}
              {fileContent?.file_status === 'Infected' && (
                <div className="bg-red-50 border border-red-300 rounded-lg p-4 flex items-start gap-3">
                  <svg className="w-6 h-6 text-red-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M4.93 4.93l14.14 14.14M12 2a10 10 0 100 20 10 10 0 000-20z" />
                  </svg>

                  <div className="flex-1">
                    <p className="text-sm font-semibold text-red-900">⚠ Malware Not Removed</p>
                    <p className="text-xs text-red-700 mt-1">
                      The malicious code in this file was not removed, and you need to deploy the cleaned version of the file.
                    </p>
                  </div>
                </div>
              )}

              {/* File Metadata */}
              <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
                <h3 className="text-md font-semibold text-gray-900 mb-3">File Information</h3>
                <div className="grid grid-cols-2 md:grid-cols-3 gap-4">
                  <div>
                    <p className="text-xs text-gray-500 font-semibold">File Name</p>
                    <p className="text-sm text-gray-900">{fileContent.file_name}</p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500 font-semibold">File Type</p>
                    <p className="text-sm text-gray-900 uppercase">{fileContent.file_type}</p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500 font-semibold">File Size</p>
                    <p className="text-sm text-gray-900">{fileContent.file_size_formatted}</p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500 font-semibold">Last Modified</p>
                    <p className="text-sm text-gray-900">{fileContent.last_modified}</p>
                  </div>
                  <div>
                    <p className="text-xs text-gray-500 font-semibold">Line Count</p>
                    <p className="text-sm text-gray-900">{fileContent.line_count}</p>
                  </div>
                  <div className="col-span-2 md:col-span-3">
                    <p className="text-xs text-gray-500 font-semibold">File Path</p>
                    <p className="text-sm text-gray-900 break-all">{fileContent.file_path}</p>
                  </div>
                </div>
              </div>

              {/* File Content */}
              <div>
                <h3 className="text-md font-semibold text-gray-900 mb-2">File Content</h3>
                <div className="bg-gray-900 text-gray-100 rounded-lg p-4 overflow-x-auto">
                  <pre className="text-sm font-mono whitespace-pre-wrap break-words">
                    {fileContent.content || 'No available content'}
                  </pre>
                </div>
              </div>
            </div>
          ) : (
            !loadingContent && (
              <div className="text-center text-gray-500 py-8">
                <p>No content available</p>
              </div>
            )
          )}
        </PopUpModal>
      )}
    </div>
  );
};

export default ScannedDetails;
