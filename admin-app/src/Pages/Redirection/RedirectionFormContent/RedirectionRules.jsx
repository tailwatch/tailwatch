import ToggleSwitch from '../../../Components/ToggleSwitcher/ToggleSwitch';
import { Edit, ChevronsRight, ExternalLink,Plus } from 'lucide-react';
import { Tooltip } from '../../../Components/ToolTip/Tooltip';
import { Link } from 'react-router-dom';
import IconButton from '../../../Components/Buttons/IconButton';
import {CheckboxField} from '../../../Components/Fields/CheckboxField';
import { safeUrl } from '../../../Components/Utils/HelperFunctions/urlSafety';

export const renderRow = ({ rowData, selectedRules, handleRuleSelect, handleToggle, isUpdating, IconButton, handleEdit }) => {
    try {
        const parsedValue = JSON.parse(rowData.value);
        const isSelected = selectedRules.includes(rowData.id.toString());
        const truncateUrl = (url) => {
            return url.length > 30 ? url.substring(0, 30) + '...' : url;
        };

        const baseUrl = window.wptw_ajax?.base_url || '';
        const buildFullUrl = (slug) => {
            if (!slug) return '';
            if (/^https?:\/\//i.test(slug)) return slug;
            const cleanBase = baseUrl.endsWith('/') ? baseUrl : baseUrl + '/';
            const cleanSlug = slug.startsWith('/') ? slug.slice(1) : slug;
            return cleanBase + cleanSlug;
        };
        const sourceFullUrl = buildFullUrl(parsedValue.source_url);

        return (
            <>
                <td className="p-3">
                    <CheckboxField checked={isSelected} onChange={() => handleRuleSelect(rowData.id.toString())}/>                    
                </td>
                <td className="p-3">
                    <div className="flex flex-col space-y-2">
                        <div className="flex items-center">
                            <Tooltip message={sourceFullUrl} position="right" className='w-full '>
                                <Link to={safeUrl(sourceFullUrl)} target="_blank" rel="noopener noreferrer" className="text-black hover:text-blue-800 hover:underline flex items-center">
                                    {truncateUrl(parsedValue.source_url)}
                                    <ExternalLink size={14} className="ml-1" />
                                </Link>
                            </Tooltip>
                            <ChevronsRight size={26} strokeWidth={3} className="mx-2 text-[#007980]" />
                            <Tooltip message={parsedValue.target_url} position="right" className=''>
                                <Link to={safeUrl(parsedValue.target_url)} target="_blank" rel="noopener noreferrer" className="text-black hover:text-blue-800 hover:underline flex items-center"> {truncateUrl(parsedValue.target_url)}
                                    <ExternalLink size={14} className="ml-1" />
                                </Link>
                            </Tooltip>
                        </div>

                        <div className="flex items-center space-x-4 text-sm text-black">
                            <span className="flex items-center">
                                <span className={`px-2 py-0.5 rounded-md ${parsedValue.status_code >= 200 && parsedValue.status_code < 300 ? 'bg-green-100 text-green-800' : parsedValue.status_code >= 300 && parsedValue.status_code < 400 ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800'}`}>
                                    {parsedValue.status_code}
                                </span>
                            </span>
                            <span className="flex items-center">
                                <span className="px-2 py-0.5 rounded-md bg-green-100 text-green-800">
                                    {parsedValue.match_type}
                                </span>
                            </span>
                        </div>
                    </div>
                </td>
                <td className="p-3 text-sm text-gray-700">{parsedValue.last_count}</td>
                <td className="p-3 text-sm text-gray-700">{parsedValue.last_access ? parsedValue.last_access : "--"}</td>
                <td className="p-3 text-sm text-gray-700">
                    <ToggleSwitch checked={parsedValue.enabled} onChange={() => handleToggle(rowData.id, !parsedValue.enabled)} disabled={isUpdating} />
                </td>
                <td className="p-3 text-sm">
                    <div className="flex space-x-2">
                        <IconButton tooltip="Edit" icon={Edit} onClick={() => handleEdit(rowData)} bgColor="bg-gray-200" hoverBgColor="hover:bg-gray-100" textColor="text-[#007980]" roundedFull={true} className="p-2 transition !rounded-[5px] duration-200 hover:shadow-sm" />
                    </div>
                </td>
            </>
        );
    } catch (error) {
        console.error("Error parsing redirection rule data:", error);
        return (
            <>
                <td className="p-3"></td>
                <td className="p-3 text-sm text-red-500">Error parsing data</td>
                <td className="p-3 text-sm text-gray-700">Error</td>
                <td className="p-3 text-sm text-gray-700">Error</td>
                <td className="p-3 text-sm text-gray-700">Error</td>
                <td className="p-3 text-sm text-gray-700">Error</td>
            </>
        );
    }
};

export const renderLogsRow = ({ log, selectedLogs, handleSelectLog }) => {
    const truncateUrl = (url) => {
        return url.length > 30 ? url.substring(0, 30) + '...' : url;
    };
    return (
        <>
            <td className="p-3">
                <CheckboxField checked={selectedLogs[log.id] || false} onChange={() => handleSelectLog(log.id)}/>                
            </td>

            <td className="p-3 ">
                <div className="flex flex-col space-y-2">
                    <div className="flex items-center">
                        <Tooltip message={log.sourceUrl} position="right" className='w-full '>
                            <Link to={safeUrl(log.sourceUrl)} target="_blank" rel="noopener noreferrer" className="text-black hover:text-blue-800 hover:underline flex items-center">
                                {truncateUrl(log.sourceUrl)}
                                <ExternalLink size={14} className="ml-1" />
                            </Link>
                        </Tooltip>
                        <ChevronsRight size={26} strokeWidth={3} className="mx-2 text-[#007980]" />
                        <Tooltip message={log.targetUrl} position="right" className=''>
                            <Link to={safeUrl(log.targetUrl)} target="_blank" rel="noopener noreferrer" className="text-black hover:text-blue-800 hover:underline flex items-center"> {truncateUrl(log.targetUrl)}
                                <ExternalLink size={14} className="ml-1" />
                            </Link>
                        </Tooltip>
                    </div>

                    <div className="flex items-center space-x-4 text-sm text-black">
                        <span className="flex items-center">
                            <span className={`px-2 py-0.5 rounded-md ${log.statusCode >= 200 && log.statusCode < 300 ? 'bg-green-100 text-green-800' : log.statusCode >= 300 && log.statusCode < 400 ? 'bg-blue-100 text-blue-800' : 'bg-red-100 text-red-800'}`}>
                                {log.statusCode}
                            </span>
                        </span>
                        <span className="flex items-center">
                            <span className="px-2 py-0.5 rounded-md bg-green-100 text-green-800">
                                {log.match_type}
                            </span>
                        </span>
                    </div>
                </div>
            </td>
            <td className="p-3">{log.client_ip}</td>
            <td className="p-3">{log.referrer ? log.referrer : "--"}</td>
            <td className="p-3">{log.timestamp}</td>
        </>
    );
};

export const renderErrorLogsRow = (log, selectedLogs, handleSelectRow,showRedirectModal,handleGetSourceUrl) => {
    if (!log || !log.parsedValue) {
        console.error("Invalid log data:", log);
        return null;
    }
    const truncateUrl = (url) => {
        return url.length > 30 ? url.substring(0, 30) + '...' : url;
    };

    const { parsedValue, date_created, id } = log;
    let userData = {};

    try {
        userData = typeof parsedValue.user_data === 'string' ? JSON.parse(parsedValue.user_data) : parsedValue.user_data;
    } catch (e) {
        console.error("Error parsing user data:", e);
    }

    return (
        <>
            <td className="p-3">
                <CheckboxField checked={!!selectedLogs[id]} onChange={() => handleSelectRow(id)}/>                
            </td>
            <td className="p-3 text-sm">
                <div className="flex flex-col space-y-2">
                    <div className="flex items-center">
                        <Tooltip message={parsedValue?.url || '--'} position="right" className='w-full '>
                            <Link to={safeUrl(parsedValue?.url, '#')} target="_blank" rel="noopener noreferrer" className="text-black hover:text-blue-800 hover:underline flex items-center">
                                {truncateUrl(parsedValue?.url || '--')}
                                <ExternalLink size={14} className="ml-1" />
                            </Link>
                        </Tooltip>
                    </div>
                </div>                
            </td>
            <td className="p-3 text-sm">
                <div className="flex flex-col space-y-2">
                    <div className="flex items-center">
                        <Tooltip message={parsedValue?.domain || '--'} position="right" className='w-full '>
                            <Link to={safeUrl(parsedValue?.domain, '#')} target="_blank" rel="noopener noreferrer" className="text-black hover:text-blue-800 hover:underline flex items-center">
                                {truncateUrl(parsedValue?.domain || '--')}
                                <ExternalLink size={14} className="ml-1" />
                            </Link>
                        </Tooltip>
                    </div>
                </div>                
            </td>
            <td className="p-3 text-sm">
                <span className="inline-flex items-center px-2.5 py-0.5 rounded-md text-xs font-medium bg-red-100 text-red-800">
                    {parsedValue?.status_code || '--'}
                </span>
            </td>
            <td className="p-3 text-sm" title={parsedValue?.log_message}>                
                {truncateUrl(parsedValue?.log_message || '--')}                
            </td>
            <td className="p-3 text-sm">
                {new Date(date_created).toLocaleString()}
            </td>
            <td className="p-3 text-sm">
                {userData?.ip_address || '--'}
            </td>
            <td className="p-3 text-sm">
            <IconButton text="Create" icon={Plus} onClick={() => handleGetSourceUrl(log) } textColor='text-white' bgColor='bg-[#007980]' hoverBgColor='hover:bg-opacity-90' className='!py-2' />
            </td>
            {/* <td className="p-3 border-t text-sm">
                <Tooltip message={userData?.browser || '--'} position="left" className='w-full '>
                    {truncateUrl(userData?.browser || '--')}
                </Tooltip>
            </td> */}
        </>
    );
};