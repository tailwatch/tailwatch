import React from 'react';
import ActionButton from '../../../Components/Buttons/ActionButton';
import { CheckboxField } from '../../../Components/Fields/CheckboxField';
import { MdCheckCircle, MdError, MdBlock, MdSyncAlt, MdWarning, MdOutlineAccessTime } from 'react-icons/md';
import { BsFillLightningFill } from 'react-icons/bs';

const getMethodBadgeColor = (method) => {
  switch (method) {
    case 'GET':
      return 'bg-green-100 text-green-800';
    case 'POST':
      return 'bg-blue-100 text-blue-800';
    case 'PUT':
      return 'bg-yellow-100 text-yellow-800';
    case 'DELETE':
      return 'bg-red-100 text-red-800';
    case 'PATCH':
      return 'bg-purple-100 text-purple-800';
    default:
      return 'bg-gray-100 text-gray-800';
  }
};

const getDurationBadge = (ms) => {
  if (ms === null || ms === undefined || isNaN(ms)) {
    return { label: 'N/A', icon: null, classes: 'bg-gray-100 text-gray-600' };
  }
  if (ms >= 5000) {
    return { label: `${ms}ms`, icon: <MdError className="inline-block ml-1" />, classes: 'bg-red-100 text-red-800' };
  }
  if (ms >= 3000) {
    return { label: `${ms}ms`, icon: <MdWarning className="inline-block ml-1" />, classes: 'bg-orange-100 text-orange-800' };
  }
  if (ms >= 1000) {
    return { label: `${ms}ms`, icon: <MdOutlineAccessTime className="inline-block ml-1" />, classes: 'bg-yellow-100 text-yellow-800' };
  }
  return { label: `${ms}ms`, icon: <BsFillLightningFill className="inline-block ml-1" />, classes: 'bg-green-100 text-green-800' };
};


const parseUserData = (userData) => {
  if (!userData) return {};
  try {
    return JSON.parse(userData);
  } catch (e) {
    return {};
  }
};

const getStatusFlag = (statusCode) => {
  const code = parseInt(statusCode, 10);
  if (isNaN(code)) return { label: 'N/A', icon: null, classes: 'bg-gray-100 text-gray-600' };

  if (code >= 200 && code < 300) {
    return { label: `${code} Success`, icon: <MdCheckCircle className="inline-block ml-1" />, classes: 'bg-green-100 text-green-800' };
  }
  if (code === 301 || code === 302 || code === 307 || code === 308) {
    return { label: `${code} Redirect`, icon: <MdSyncAlt className="inline-block ml-1" />, classes: 'bg-blue-100 text-blue-700' };
  }
  if (code === 401 || code === 403) {
    return { label: `${code} Blocked`, icon: <MdBlock className="inline-block ml-1" />, classes: 'bg-purple-100 text-purple-800' };
  }
  if (code >= 400 && code < 500) {
    return { label: `${code} Failed`, icon: <MdError className="inline-block ml-1" />, classes: 'bg-red-100 text-red-700' };
  }
  if (code >= 500) {
    return { label: `${code} Error`, icon: <MdError className="inline-block ml-1" />, classes: 'bg-red-200 text-red-900' };
  }
  return { label: `${code}`, icon: null, classes: 'bg-gray-100 text-gray-600' };
};


const TableRows = ({ activeTab, rowData, handleErrorModalOpen, handleDetailModalOpen, handleSelectLog, selectedLogs }) => {
  const logData = rowData?.value
    ? (typeof rowData.value === 'string' ? JSON.parse(rowData.value) : rowData.value)
    : rowData;

  const userData = logData?.user_data ? parseUserData(logData.user_data) : {};

  const trimTitle = (path) => {
    if (!path) return "N/A";
    const maxLength = 50;
    return path.length > maxLength ? path.slice(0, maxLength) + '.......' : path;
  };

  const commonColumns = (
    <td className="p-3 text-sm text-black" onClick={(e) => e.stopPropagation()}>
      <CheckboxField checked={selectedLogs.includes(rowData.id)} onChange={() => handleSelectLog(rowData.id)} />
    </td>
  );

  switch (activeTab) {
    case "user":
      return (
        <>
          {commonColumns}
          <td className="p-3 text-sm text-black">{logData?.title || 'N/A'}</td>
          <td className="p-3 text-sm text-black">{logData?.body || 'N/A'}</td>
          <td className="p-3 text-sm text-black" title={userData?.ip_address ? `IP: ${userData.ip_address}, Browser: ${userData.browser || 'Unknown'}` : 'N/A'}>
            {trimTitle(userData?.ip_address ? `IP: ${userData.ip_address}, Browser: ${userData.browser || 'Unknown'}` : 'N/A')}
          </td>
          <td className="p-3 text-sm text-black">
            {logData?.type ? (
              <span className="inline-flex items-center bg-blue-100 text-blue-800 border border-blue-200 px-2.5 py-1 rounded-full text-xs font-semibold whitespace-nowrap">
                {logData.type.charAt(0).toUpperCase() + logData.type.slice(1)}
              </span>
            ) : (
              'N/A'
            )}
          </td>
        </>
      );
    case "email":
      const emailStatus = (logData?.status || '').toLowerCase();
      const isSuccess = emailStatus === 'success';
      const isFailed = emailStatus === 'failed';
      const emailBadgeClass = isSuccess
        ? 'bg-green-100 text-green-800 border-green-200'
        : isFailed
          ? 'bg-red-100 text-red-800 border-red-200'
          : 'bg-gray-100 text-gray-700 border-gray-200';
      return (
        <>
          {commonColumns}
          <td className="p-3 text-sm text-black">{logData?.subject || 'N/A'}</td>
          <td className="p-3 text-sm text-black">{logData?.sent_to || 'N/A'}</td>
          <td className="p-3 text-sm text-black">{logData?.delivery_time || 'N/A'}</td>
          <td className="p-3 text-sm whitespace-nowrap">
            {logData?.status ? (
              <span className={`inline-flex items-center border px-2.5 py-1 rounded-full text-xs font-semibold capitalize ${emailBadgeClass}`}>
                {logData.status}
              </span>
            ) : (
              'N/A'
            )}
            {isFailed && (
              <button onClick={() => handleErrorModalOpen(logData.error)} className="ml-[10px] underline text-blue-700 hover:text-blue-800 focus:outline-none">
                View
              </button>
            )}
          </td>
          <td className="p-3 border-b">
            <ActionButton onClick={() => handleDetailModalOpen(logData)} defaultText="View" />
          </td>
        </>
      );
    case "error":
      const errorStatusFlag = getStatusFlag(logData?.status_code);
      return (
        <>
          {commonColumns}
          <td className="p-3 text-sm text-black">{logData?.log_message || 'N/A'}</td>
          <td className="p-3 text-sm text-black">
            <span className={`inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-xl whitespace-nowrap ${errorStatusFlag.classes}`}>
              {errorStatusFlag.icon}
              {logData?.status_code || 'N/A'}
            </span>
          </td>
        </>
      );
    case "network": {
      const rawDuration = logData?.meta?.duration_ms;
      const durationMs = parseFloat(String(rawDuration).replace(/[^0-9.]/g, ''));
      const durationBadge = getDurationBadge(isNaN(durationMs) ? null : Math.round(durationMs));
      const networkStatusFlag = getStatusFlag(logData?.response?.status_code);

      return (
        <>
          {commonColumns}
          <td className="p-3 text-sm text-black">
            {logData?.request?.post_data?.action_type || logData?.endpoint?.action || 'N/A'}
          </td>
          <td className="p-3 text-sm text-black">
            <span
              className={`px-2 py-1 text-xs font-medium rounded-xl ${getMethodBadgeColor(logData?.endpoint?.method)} whitespace-nowrap`}
              title={logData?.endpoint?.method || 'N/A'}
            >
              {logData?.endpoint?.method || 'N/A'}
            </span>
          </td>
          <td className="p-3 text-sm text-black">
            <span className={`inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-xl whitespace-nowrap ${networkStatusFlag.classes}`}>
              {networkStatusFlag.icon}
              {networkStatusFlag.label}
            </span>
          </td>
          <td className="p-3 text-sm text-black">
            {logData?.meta?.ip_address || 'N/A'}
          </td>
          <td className="p-3 text-sm text-black">
            <span className={`inline-flex items-center gap-1 px-2 py-1 text-xs font-medium rounded-xl whitespace-nowrap ${durationBadge.classes}`}>
              {durationBadge.icon}
              {durationBadge.label}
            </span>
          </td>
        </>
      );
    }
    default:
      return <td className="p-3 text-sm text-black">No data available</td>;
  }
};

export default TableRows;