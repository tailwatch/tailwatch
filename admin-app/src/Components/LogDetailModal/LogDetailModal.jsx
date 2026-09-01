import React, { useState } from 'react';
import PopUpModal from '../Modal/PopUpModal';
import { AlertCircle, Info, FileText, Calendar, Tag, Activity, CheckCircle, XCircle, ArrowRight, Database } from 'lucide-react';
import { formatLabel } from '../Utils/formatText/formatText.js';
import { safeUrl } from '../Utils/HelperFunctions/urlSafety';

const LogDetailModal = ({ log, onClose }) => {
    const [activeTab, setActiveTab] = useState('headers');
    
    if (!log) return null;

    const isAjaxLog = log?.isAjax === true;
    const isErrorLog = log?.isError === true;
    const isUserLog = log?.isUser === true;
    const ajaxData = log?.parsedValue || {};
    const errorData = log?.parsedValue || {};
    const userLogData = log?.parsedValue || {};

    const getTypeBadgeColor = (type) => {
        if (type === 'error_logs') {
            return 'bg-red-100 text-red-800';
        } else if (type === 'activity_logs') {
            return 'bg-blue-100 text-blue-800';
        }
        return 'bg-gray-100 text-gray-800';
    };

    const getPriorityBadgeColor = (priority) => {
        if (priority === 'high') {
            return 'bg-red-100 text-red-800';
        } else if (priority === 'medium') {
            return 'bg-yellow-100 text-yellow-800';
        } else if (priority === 'low') {
            return 'bg-green-100 text-green-800';
        }
        return 'bg-gray-100 text-gray-800';
    };

    const formatDate = (dateString) => {
        if (!dateString) return 'N/A';
        try {
            return new Date(dateString).toLocaleString();
        } catch (e) {
            return dateString;
        }
    };

    const formatKey = (key) => {
        return formatLabel(key);
    }
    const formatBytes = (bytes) => {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    };


    const renderValue = (value, key) => {
        if (value === null || value === undefined) {
            return <span className="text-gray-400">N/A</span>;
        }

        if (typeof value === 'boolean') {
            return value ? (
                <span className="inline-flex items-center gap-1 text-green-600">
                    <CheckCircle className="w-4 h-4" />
                    <span>Enabled</span>
                </span>
            ) : (
                <span className="inline-flex items-center gap-1 text-red-500">
                    <XCircle className="w-4 h-4" />
                    <span>Disabled</span>
                </span>
            );
        }

        if (typeof value === 'number') {
            if (key && (key.includes('memory') || key.includes('Memory'))) {
                return <span className="font-mono text-gray-700">{formatBytes(value)}</span>;
            }
            return <span className="font-mono text-gray-700">{value.toLocaleString()}</span>;
        }

        if (typeof value === 'string') {
            //  body 
            if (key === 'body') {
                if (value.startsWith('http://') || value.startsWith('https://')) {
                    return <span className="text-blue-600 break-all">{value}</span>;
                }
                return <span className="text-gray-700 break-all">{value}</span>;
            }

            try {
                const parsed = JSON.parse(value);
                if (typeof parsed === 'object' && parsed !== null) {
                    return <span className="text-gray-700 break-all font-mono text-xs">
                        {JSON.stringify(parsed, null, 2)}
                    </span>;
                }
            } catch (e) { /* normal string  */ }

            if (value.startsWith('http://') || value.startsWith('https://')) {
                return <span className="text-blue-600 break-all">{value}</span>;
            }
            return <span className="text-gray-700 break-all">{value}</span>;
        }

        if (Array.isArray(value)) {
            return <span className="text-gray-700 break-all font-mono text-xs">
                {JSON.stringify(value, null, 2)}
            </span>;
        }

        if (typeof value === 'object') {
            return <span className="text-gray-700 break-all font-mono text-xs">
                {JSON.stringify(value, null, 2)}
            </span>;
        }

        return <span className="text-gray-700">{String(value)}</span>;
    };

    const renderNestedTable = (obj) => {
        if (!obj || Object.keys(obj).length === 0) return <span className="text-gray-400">N/A</span>;

        return (
            <table className="w-full text-xs border border-gray-200 rounded">
                <tbody className="divide-y divide-gray-100">
                    {Object.entries(obj).map(([k, v], i) => {
                        if (v === null || v === undefined || v === '') return null;

                        return (
                            <tr key={i} className="hover:bg-gray-50">
                                <td className="py-1.5 px-2 font-medium text-gray-500 w-1/3 whitespace-nowrap border-r border-gray-200">
                                    {formatKey(k)}
                                </td>
                                <td className="py-1.5 px-2 text-gray-700">
                                    {Array.isArray(v) ? (
                                        v.length === 0 ? (
                                            <span className="text-gray-400">Empty</span>
                                        ) : typeof v[0] === 'object' ? (
                                            <div className="space-y-2">
                                                {v.map((item, idx) => (
                                                    <div key={idx} className="border border-gray-200 rounded p-1">
                                                        {typeof item === 'object'
                                                            ? renderNestedTable(item)
                                                            : <span>{String(item)}</span>
                                                        }
                                                    </div>
                                                ))}
                                            </div>
                                        ) : (
                                            <span>{v.join(', ')}</span>
                                        )
                                    ) : typeof v === 'object' && !Array.isArray(v) ? (
                                        renderNestedTable(v)
                                    ) : typeof v === 'boolean' ? (
                                        v
                                            ? <span className="text-green-600">Enabled</span>
                                            : <span className="text-red-500">Disabled</span>
                                    ) : (
                                        String(v)
                                    )}
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        );
    };

    const renderChangesArray = (changes) => {
        return (
            <div className="space-y-2 mt-2">
                {changes.map((change, index) => (
                    <div key={index} className="bg-white rounded-lg p-3 border border-gray-200 shadow-sm">
                        <div className="flex items-center justify-between mb-2">
                            <span className="font-medium text-gray-800">{change.label || change.field_id}</span>
                            {change.type && (
                                <span className="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded capitalize">{change.type}</span>
                            )}
                        </div>
                        <div className="flex items-center gap-2 text-sm">
                            <span className={`${change.old_value === false || change.old_value === 'false' ? 'text-red-500' : 'text-green-500'}`}>
                                {typeof change.old_value === 'boolean' ? (change.old_value ? 'Enabled ' : 'Disabled') : String(change.old_value)}
                            </span>
                            <ArrowRight className="w-4 h-4 text-gray-400" />
                            <span className={`${change.new_value === true || change.new_value === 'true' ? 'text-green-500 font-medium' : 'text-red-500'}`}>
                                {typeof change.new_value === 'boolean' ? (change.new_value ? 'Enabled' : 'Disabled') : String(change.new_value)}
                            </span>
                        </div>
                        {change.readable_change && (
                            <p className="text-xs text-gray-500 mt-1">{change.readable_change}</p>
                        )}
                    </div>
                ))}
            </div>
        );
    };

    const renderObjectAsTable = (obj, title) => {
        if (!obj || Object.keys(obj).length === 0) return null;

        const simpleEntries = [];
        const arrayEntries = [];

        Object.entries(obj).forEach(([key, value]) => {
            if (value === null || value === undefined || value === '') return;
            if (key === 'body_truncated') return;
            if (key === 'status_code') return;

            if (key === 'body') {
                simpleEntries.push([key, value]);
                return;
            }

            if (Array.isArray(value)) {
                if (value.length === 0) return;
                arrayEntries.push([key, value]);
            } else {
                simpleEntries.push([key, value]);
            }
        });

        if (simpleEntries.length === 0 && arrayEntries.length === 0) return null;

        return (
            <div className="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <h4 className="text-sm font-semibold text-gray-700 mb-3 flex items-center">
                    <FileText className="w-4 h-4 mr-2" />
                    {title}
                </h4>

                {simpleEntries.length > 0 && (
                    <div className="">
                        {simpleEntries.map(([key, value], index) => (
                            <React.Fragment key={index}>
                                {typeof value === 'object' && value !== null && !Array.isArray(value) ? (
                                    <div>


                                        <h4 className="text-md font-semibold text-gray-700 mb-3 flex items-center mt-5">
                                            {formatKey(key)}                </h4>

                                        <table className="w-full text-sm ">
                                            <tbody className="">
                                                {Object.entries(value).map(([k, v], i) => {
                                                    if (v === null || v === undefined || v === '') return null;
                                                    return (
                                                        <tr key={i} className="hover:bg-gray-100 border-t   border-gray-200 ">
                                                            <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 align-top"
                                                                style={{ width: '30%' }}>
                                                                {formatKey(k)}
                                                            </td>
                                                            <td className="py-1.5 px-2 text-gray-700 break-all">
                                                                {renderValue(v, k)}
                                                            </td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>

                                ) : key === 'body' ? (
                                    <div>
                                        <p className="text-sm font-bold text-gray-600 uppercase py-2 px-1">
                                            {formatKey(key)}
                                        </p>
                                        <div className="bg-white border border-gray-200 rounded p-2 text-xs text-gray-700 break-all font-mono">
                                            {typeof value === 'object'
                                                ? JSON.stringify(value, null, 2)
                                                : String(value)
                                            }
                                        </div>
                                    </div>

                                ) : (
                                    <table className="w-full text-sm">
                                        <tbody>
                                            <tr className="hover:bg-gray-100 border-t   border-gray-200 ">
                                                <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 align-top"
                                                    style={{ width: '30%' }}>
                                                    {formatKey(key)}
                                                </td>
                                                <td className="py-2 px-3 text-gray-700">
                                                    {renderValue(value, key)}
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                )}
                            </React.Fragment>
                        ))}
                    </div>
                )}



                {arrayEntries.map(([key, value], index) => (
                    <div key={index} className={simpleEntries.length > 0 ? 'mt-4 pt-4 border-t border-gray-200' : ''}>
                        <h5 className="text-sm font-medium text-gray-700 mb-2">{formatKey(key)}</h5>
                        {key === 'changes' ? (
                            renderChangesArray(value)
                        ) : (
                            <div className="space-y-2 ">
                                {value.map((item, idx) => (
                                    <div key={idx} className="bg-white rounded p-2  border-gray-200 text-sm">
                                        {typeof item === 'object' ? (
                                            <table className="w-full text-xs">
                                                <tbody className="divide-y divide-gray-100">
                                                    {Object.entries(item).map(([k, v], i) => (
                                                        <tr key={i}>
                                                            <td className="py-1 px-2 font-medium text-gray-500 border-r border-gray-100"
                                                                style={{ width: '30%' }}>
                                                                {formatKey(k)}
                                                            </td>
                                                            <td className="py-1 px-2 text-gray-700 break-all">
                                                                {renderValue(v, k)}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        ) : (
                                            <span className="text-gray-700">{String(item)}</span>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                ))}
            </div>
        );
    };

    const renderKeyValueTable = (obj, excludeKeys = [], options = {}) => {
        if (!obj || Object.keys(obj).length === 0) {
            return <p className="text-gray-400 text-sm">No data available</p>;
        }

        const { rawKeys = false } = options;

        return (
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <tbody className="divide-y divide-gray-200">
                        {Object.entries(obj).map(([key, value], index) => {
                            if (excludeKeys.includes(key) || value === null || value === undefined || value === '') {
                                return null;
                            }

                            return (
                                <tr key={index} className="hover:bg-gray-50 transition-colors">
                                    <td className={`py-2 px-3 font-medium text-gray-600 border-r border-gray-200 align-top w-1/3 ${rawKeys ? 'font-mono text-xs' : ''}`}>
                                        {rawKeys ? key : formatKey(key)}
                                    </td>
                                    <td className="py-2 px-3 text-gray-700 break-all">
                                        {renderValue(value, key)}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
        );
    };

    const renderHeadersTab = () => {
        if (!isAjaxLog) return <p className="text-gray-400">No headers available</p>;

        const endpoint = ajaxData?.endpoint || {};
        const request = ajaxData?.request || {};
        const response = ajaxData?.response || {};
        const meta = ajaxData?.meta || {};
        const requestHeaders = request.headers || {};

        return (
            <div className="space-y-6">
                <div>
                    <h4 className="text-sm font-semibold text-gray-700 mb-3 pb-2 border-b border-gray-200 flex items-center">
                        <Info className="w-4 h-4 mr-2" />
                        General
                    </h4>
                    <div className="bg-gray-50 rounded p-4 overflow-x-auto">
                        <table className="w-full text-sm">
                            <tbody className="divide-y divide-gray-200">
                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">Request URL:</td>
                                    <td className="py-2 px-3 text-gray-700 break-all font-mono">{endpoint.url || 'N/A'}</td>
                                </tr>

                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">Request Method:</td>
                                    <td className="py-2 px-3 text-gray-700">
                                        <span className="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs font-semibold">
                                            {endpoint.method || 'N/A'}
                                        </span>
                                    </td>
                                </tr>

                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">Status Code:</td>
                                    <td className="py-2 px-3 text-gray-700">
                                        <span className={`px-2 py-1 rounded text-xs font-semibold ${response.status_code >= 200 && response.status_code < 300
                                            ? 'bg-green-100 text-green-800'
                                            : response.status_code >= 400
                                                ? 'bg-red-100 text-red-800'
                                                : 'bg-yellow-100 text-yellow-800'
                                            }`}>
                                            {response.status_code || 'N/A'}
                                        </span>
                                    </td>
                                </tr>

                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">Remote Address:</td>
                                    <td className="py-2 px-3 text-gray-700 font-mono">
                                        {meta.ip_address
                                            ? `${meta.ip_address}:${meta.remote_port || '80'}`
                                            : 'N/A'}
                                    </td>
                                </tr>

                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">Referrer Policy:</td>
                                    <td className="py-2 px-3 text-gray-700">
                                        {requestHeaders['Referrer-Policy'] || 'strict-origin-when-cross-origin'}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div>
                    <h4 className="text-sm font-semibold text-gray-700 mb-3 pb-2 border-b border-gray-200 flex items-center">
                        <FileText className="w-4 h-4 mr-2" />
                        Request Headers
                    </h4>
                    <div className="bg-gray-50 rounded p-4">
                        {renderKeyValueTable(requestHeaders)}
                    </div>
                </div>
            </div>
        );
    };

    const renderPayloadTab = () => {
        if (!isAjaxLog) return <p className="text-gray-400">No payload available</p>;

        const request = ajaxData?.request || {};
        const postData = request.post_data || {};
        const queryParams = request.query_params || {};
        const body = request.raw_body || '';

        // query_params and post_data can come back as either an object {key: value}
        // or an empty array []. Normalize length checks accordingly.
        const queryParamsCount = Array.isArray(queryParams) ? queryParams.length : Object.keys(queryParams).length;
        const postDataCount = Array.isArray(postData) ? postData.length : Object.keys(postData).length;

        const hasNothing = queryParamsCount === 0 && postDataCount === 0 && !body;

        return (
            <div className="space-y-6">
                {queryParamsCount > 0 && (
                    <div>
                        <h4 className="text-sm font-semibold text-gray-700 mb-3 pb-2 border-b border-gray-200 flex items-center">
                            <Tag className="w-4 h-4 mr-2" />
                            Query String Parameters
                        </h4>
                        <div className="bg-gray-50 rounded p-4">
                            {renderKeyValueTable(queryParams, [], { rawKeys: true })}
                        </div>
                    </div>
                )}

                {postDataCount > 0 && (
                    <div>
                        <h4 className="text-sm font-semibold text-gray-700 mb-3 pb-2 border-b border-gray-200 flex items-center">
                            <Database className="w-4 h-4 mr-2" />
                            Form Data
                        </h4>
                        <div className="bg-gray-50 rounded p-4">
                            {renderKeyValueTable(postData, ['body'])}
                        </div>
                    </div>
                )}

                {body && (
                    <div>
                        <h4 className="text-sm font-semibold text-gray-700 mb-3 pb-2 border-b border-gray-200 flex items-center">
                            <FileText className="w-4 h-4 mr-2" />
                            Request Body
                        </h4>
                        <div className="bg-gray-900 text-gray-100 rounded p-4 font-mono text-xs overflow-x-auto">
                            <pre className="whitespace-pre-wrap break-words">
                                {typeof body === 'object' ? JSON.stringify(body, null, 2) : body}
                            </pre>
                        </div>
                    </div>
                )}

                {hasNothing && (
                    <p className="text-gray-400 text-sm">No payload data</p>
                )}
            </div>
        );
    };

    const renderErrorLogContent = () => {
        const userData = typeof errorData.user_data === 'string'
            ? (() => { try { return JSON.parse(errorData.user_data); } catch { return {}; } })()
            : (errorData.user_data || {});

        const statusCodeNum = parseInt(errorData.status_code, 10);
        const statusBadgeClass = statusCodeNum >= 200 && statusCodeNum < 300
            ? 'bg-green-100 text-green-800'
            : statusCodeNum >= 400
                ? 'bg-red-100 text-red-800'
                : 'bg-yellow-100 text-yellow-800';

        return (
            <div className="space-y-6">
                <div>
                    <h4 className="text-sm font-semibold text-gray-700 mb-3 pb-2 border-b border-gray-200 flex items-center">
                        <FileText className="w-4 h-4 mr-2" />
                        User Data
                    </h4>
                    <div className="bg-gray-50 rounded p-4 overflow-x-auto">
                        <table className="w-full text-sm">
                            <tbody className="divide-y divide-gray-200">
                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">Request URL:</td>
                                    <td className="py-2 px-3 text-gray-700 break-all font-mono">
                                        {errorData.url ? (
                                            <a href={safeUrl(errorData.url)} target="_blank" rel="noopener noreferrer" className="text-blue-600 hover:underline">
                                                {errorData.url}
                                            </a>
                                        ) : 'N/A'}
                                    </td>
                                </tr>
                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">Status Code:</td>
                                    <td className="py-2 px-3 text-gray-700">
                                        <span className={`px-2 py-1 rounded text-xs font-semibold ${statusBadgeClass}`}>
                                            {errorData.status_code || 'N/A'}
                                        </span>
                                    </td>
                                </tr>
                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">Message:</td>
                                    <td className="py-2 px-3 text-gray-700 break-all">
                                        {errorData.log_message || 'N/A'}
                                    </td>
                                </tr>
                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">Domain:</td>
                                    <td className="py-2 px-3 text-gray-700 break-all font-mono">
                                        {errorData.domain || 'N/A'}
                                    </td>
                                </tr>
                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">IP Address:</td>
                                    <td className="py-2 px-3 text-gray-700 break-all font-mono">
                                        {userData?.ip_address || userData?.ip || 'N/A'}
                                    </td>
                                </tr>
                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">Browser:</td>
                                    <td className="py-2 px-3 text-gray-700 break-all">
                                        {userData?.browser || userData?.user_agent || 'N/A'}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        );
    };

    const renderUserLogContent = () => {
        const userData = typeof userLogData.user_data === 'string'
            ? (() => { try { return JSON.parse(userLogData.user_data); } catch { return {}; } })()
            : (userLogData.user_data || {});

        return (
            <div className="space-y-6">
                <div>
                    <h4 className="text-sm font-semibold text-gray-700 mb-3 pb-2 border-b border-gray-200 flex items-center">
                        <FileText className="w-4 h-4 mr-2" />
                        User Data
                    </h4>
                    <div className="bg-gray-50 rounded p-4 overflow-x-auto">
                        <table className="w-full text-sm">
                            <tbody className="divide-y divide-gray-200">
                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">Title:</td>
                                    <td className="py-2 px-3 text-gray-700 break-all">
                                        {userLogData.title || 'N/A'}
                                    </td>
                                </tr>
                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">Type:</td>
                                    <td className="py-2 px-3 text-gray-700">
                                        <span className="bg-blue-100 text-blue-800 px-2 py-1 rounded text-xs font-semibold capitalize">
                                            {userLogData.type || 'N/A'}
                                        </span>
                                    </td>
                                </tr>
                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">Domain:</td>
                                    <td className="py-2 px-3 text-gray-700 break-all font-mono">
                                        {userLogData.domain || 'N/A'}
                                    </td>
                                </tr>
                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">Username:</td>
                                    <td className="py-2 px-3 text-gray-700 break-all">
                                        {userData?.username || 'N/A'}
                                    </td>
                                </tr>
                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">IP Address:</td>
                                    <td className="py-2 px-3 text-gray-700 break-all font-mono">
                                        {userData?.ip_address || userData?.ip || 'N/A'}
                                    </td>
                                </tr>
                                <tr className="hover:bg-gray-100 transition-colors">
                                    <td className="py-2 px-3 font-medium text-gray-600 border-r border-gray-200 w-1/3">Browser:</td>
                                    <td className="py-2 px-3 text-gray-700 break-all">
                                        {userData?.browser || userData?.user_agent || 'N/A'}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        );
    };

    const TabButton = ({ id, label, active }) => (
        <button
            onClick={() => setActiveTab(id)}
            className={`px-4 py-2 text-sm font-medium border-b-2 transition-colors ${active
                ? 'border-[#007980] text-[#007980]'
                : 'border-transparent text-gray-600 hover:text-[#007980]'
                }`}
        >
            {label}
        </button>
    );

    return (
        <PopUpModal
            title="Log Details"
            onClose={onClose}
            cancelButtonText="Close"
            width="w-full max-w-5xl"
            height="max-h-[90vh]"
            showExpandIcon={true}
            initiallyExpanded={true}
        >
            <div className="">
                <div className="bg-white  p-4 border border-gray-200 shadow-sm">
                    <div className="flex items-start space-x-3">
                        <div className="p-2 rounded-lg bg-[#007980] bg-opacity-10">
                            <Info className="w-5 h-5 text-[#007980]" />
                        </div>
                        <div className="flex-1">
                            {isAjaxLog ? (
                                <>
                                    <h3 className="text-sm font-semibold text-gray-700 mb-1">
                                        Request
                                    </h3>
                                    <p className="text-sm text-gray-900">
                                        <span className="font-mono bg-blue-50 px-2 py-1 rounded mr-2">
                                            {ajaxData?.endpoint?.method || "N/A"}{" "}
                                            {ajaxData?.endpoint?.url || ""}
                                        </span>
                                    </p>
                                </>
                            ) : isErrorLog ? (
                                <>
                                    <h3 className="text-sm font-semibold text-gray-900 break-all">
                                        {errorData?.status_code ? `${errorData.status_code} Found` : "N/A"}
                                    </h3>
                                </>
                            ) : isUserLog ? (
                                <>
                                    <h3 className="text-sm font-semibold text-gray-700 mb-1">
                                        {userLogData?.title || "Activity"}
                                    </h3>
                                    <p className="text-sm text-gray-900 break-all">
                                        {userLogData?.body || "N/A"}
                                    </p>
                                </>
                            ) : (
                                <>
                                    <h3 className="text-sm font-semibold text-gray-700 mb-1">
                                        Message
                                    </h3>
                                    <p className="text-sm text-gray-900">{log.message || ""}</p>
                                </>
                            )}

                            <p className="text-xs text-gray-500 mt-2">
                                {isAjaxLog
                                    ? ajaxData?.meta?.request_time || "N/A"
                                    : formatDate(log.date_created || log.date)}
                            </p>
                        </div>
                    </div>
                </div>

                {isAjaxLog && (
                    <div className="border-b border-gray-200 bg-white rounded-t-lg sticky top-0 z-10">
                        <div className="flex space-x-1 px-4 pt-4">
                            <TabButton id="headers" label="Headers" active={activeTab === 'headers'} />
                            <TabButton id="payload" label="Payload" active={activeTab === 'payload'} />
                        </div>
                    </div>
                )}

                <div className="bg-white rounded-b-lg p-4 border border-t-0 border-gray-200 ">
                    {isAjaxLog ? (
                        <>
                            {activeTab === 'headers' && renderHeadersTab()}
                            {activeTab === 'payload' && renderPayloadTab()}
                        </>
                    ) : isErrorLog ? (
                        renderErrorLogContent()
                    ) : isUserLog ? (
                        renderUserLogContent()
                    ) : (
                        <>
                            <div className="space-y-4 ">
                                {log.context && Object.keys(log.context).length > 0 && (
                                    <div>
                                        {renderObjectAsTable(log.context, 'Context')}
                                    </div>
                                )}
                                {log.extra && Object.keys(log.extra).length > 0 && (
                                    <div>
                                        {renderObjectAsTable(log.extra, 'Additional Information')}
                                    </div>
                                )}
                                {log.trace && (
                                    <div>
                                        <div className="bg-red-50 rounded-lg p-4 border border-red-200">
                                            <h4 className="text-sm font-semibold text-red-700 mb-2 flex items-center">
                                                <AlertCircle className="w-4 h-4 mr-2" />
                                                Stack Trace
                                            </h4>
                                            <pre className="text-xs text-red-800 overflow-x-auto whitespace-pre-wrap font-mono">
                                                {log.trace}
                                            </pre>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </>
                    )}
                </div>

            </div>
        </PopUpModal>
    );
};

export default LogDetailModal;