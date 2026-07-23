import {ExternalLink, Plus } from 'lucide-react';
import { Tooltip } from '../../../Components/ToolTip/Tooltip';
import { Link } from 'react-router-dom';
import IconButton from '../../../Components/Buttons/IconButton';
import { safeUrl } from '../../../Components/Utils/HelperFunctions/urlSafety';

export const LogsData = ({ log, selectedLogs, handleSelectLog, handleGetSourceUrl, redirectionsFeature }) => {
    const truncateUrl = (url) => {
        return url.length > 30 ? url.substring(0, 30) + '...' : url;
    };

    // Redirection feature is enabled only when both flags from the API are true.
    // Until the response loads (redirectionsFeature is null), default to disabled.
    const redirectionFeatureEnabled = !!(redirectionsFeature && redirectionsFeature.parent_enable === true && redirectionsFeature.feature_enable === true);
    const createDisabled = !redirectionFeatureEnabled;
    const createTooltip = createDisabled
        ? "Please activate the Redirection Rule Feature"
        : "Create Rule";

    return (
        <>
            <td className="p-3">
                <input type="checkbox" checked={selectedLogs[log.id] || false} onChange={() => handleSelectLog(log.id)} className="h-5 w-5 rounded border-indigo-300 text-indigo-600 focus:ring-indigo-500" />
            </td>

            <td className="p-3 ">
                <div className="flex flex-col space-y-2">
                    <div className="flex items-center">
                        <Tooltip message={log.Url} position="right" className='w-full !bg-[#ec5023]'>
                            <Link to={safeUrl(log.Url)} target="_blank" rel="noopener noreferrer" className="text-black hover:text-blue-800 hover:underline flex items-center">
                                {truncateUrl(log.Url)}
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
                    </div>
                </div>
            </td>
            <td className="p-3" title={log.errorMessage}>            
                {truncateUrl(log.errorMessage)}
                </td>
            <td className="p-3">{log.parentType}</td>
            <td className="p-3">{log.lastChecked}</td>
            <td className="p-3">{log.anchorText}</td>
            <td className="p-3">
                <Tooltip message={createTooltip}>
                <IconButton disabled={createDisabled} text="Create" icon={Plus} onClick={() => handleGetSourceUrl(log) } textColor='text-white' bgColor='bg-[#007980]' hoverBgColor='hover:bg-opacity-90' className='!py-2' />
                </Tooltip>
            </td>
        </>
    );
};