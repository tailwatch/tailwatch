import { CirclePause, Trash } from 'lucide-react'
import { HoveredActions } from './HoveredActions';
import { CheckboxField } from '../../../Components/Fields/CheckboxField';
import { Pencil, Loader2 } from 'lucide-react';
export const cronJobColumns = ['', 'Hook', 'Next Run', 'Schedule', 'Action'];
export const scheduleJobColumns = ['Slug', 'Display Name', 'Interval',];

export const renderCronJobRowData = ({
    rowData,
    selectedJobs,
    hoveredRowHash,
    setHoveredRowHash,
    handleRowSelect,
    handleEdit,
    handleRunNow,
    handleResume,
    handlePause,
    handleDelete,
}) => {
    const isPluginSystem = rowData.is_plugin_system === true;
    const isSelectable = !rowData.is_protected && !isPluginSystem;
    const isSelected = selectedJobs.has(rowData.hash);
    const isHovered = hoveredRowHash === rowData.hash;
    const isPaused = rowData.is_paused === true;

    return (
        <>
            <td
                className="p-3"
                onMouseEnter={() => setHoveredRowHash(rowData.hash)}
                onMouseLeave={() => setHoveredRowHash(null)}
            >
                <CheckboxField checked={isSelected}
                    disabled={!isSelectable}
                    onChange={(e) => handleRowSelect(rowData.hash, e.target.checked)} />                
            </td>
            <td
                className="p-3 text-gray-900 text-sm font-medium relative"
                onMouseEnter={() => setHoveredRowHash(rowData.hash)}
                onMouseLeave={() => setHoveredRowHash(null)}
            >
                <div className="flex items-center justify-between">
                    <div className="flex items-center">
                        {isPaused ? (
                            <div className="flex items-center">
                                <CirclePause className="h-5 w-5 text-amber-500 mr-2" />
                                <span className="text-gray-500">{rowData?.hook}</span>
                            </div>
                        ) : (
                            <span>{rowData?.hook}</span>
                        )}
                        {isPluginSystem ? (
                            <span className="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                Managed by Tailwatch
                            </span>
                        ) : rowData.is_protected && (
                            <span className="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                                Protected
                            </span>
                        )}
                        {rowData.created_by_plugin && (
                            <span className="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-teal-100 text-teal-800">
                                You own this
                            </span>
                        )}
                        {isPaused && (
                            <span className="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">
                                Paused
                            </span>
                        )}
                    </div>
                    {isHovered && (
                        <HoveredActions handleEdit={handleEdit} handleRunNow={handleRunNow} handleResume={handleResume} handlePause={handlePause} handleDelete={handleDelete} isPaused={isPaused} rowData={rowData} />
                    )}
                </div>
            </td>
            <td
                className={`p-3 text-sm ${isPaused ? 'text-gray-500' : 'text-gray-900'}`}
                onMouseEnter={() => setHoveredRowHash(rowData.hash)}
                onMouseLeave={() => setHoveredRowHash(null)}
            >
                {rowData?.next_run}
            </td>
            <td
                className={`p-3 text-sm ${isPaused ? 'text-gray-500' : 'text-gray-900'}`}
                onMouseEnter={() => setHoveredRowHash(rowData.hash)}
                onMouseLeave={() => setHoveredRowHash(null)}
            >
                {rowData?.schedule_display}
            </td>
            <td
                className={`p-3 text-sm ${isPaused ? 'text-gray-500' : 'text-gray-900'}`}
                onMouseEnter={() => setHoveredRowHash(rowData.hash)}
                onMouseLeave={() => setHoveredRowHash(null)}
            >
                {rowData?.actions}
            </td>
        </>
    );
};

export const renderScheduleJobRowData = ({
    rowData,
    hoveredRowHash,
    setHoveredRowHash,
    handleDeleteSchedule,
    handleEditSchedule,
    deleting,    
    withLoader,
    loadingAction
}) => {
    const isHovered = hoveredRowHash === rowData.slug;

    return (
        <>
            <td className="p-3 text-gray-900 text-sm relative  w-1/4" onMouseEnter={() => setHoveredRowHash(rowData.slug)} onMouseLeave={() => setHoveredRowHash(null)} >
                <span className="font-mono bg-gray-100 px-2 py-1 rounded">
                    {rowData.slug}
                </span>
                {isHovered && (
                    <div className="absolute right-4 top-1/2 transform -translate-y-1/2 flex items-center space-x-2 bg-white py-1 px-2 rounded-md shadow-lg border border-gray-200 z-10 animate-fade-in">
                        <button 
                            onClick={(e) => { 
                                e.stopPropagation(); 
                                withLoader('edit', () => handleEditSchedule(rowData)); 
                            }} 
                            className={`text-gray-600 hover:text-blue-600 p-1 rounded transition-colors ${rowData.is_protected ? 'opacity-50 cursor-not-allowed' : '' } `}
                            title="Edit" 
                            disabled={rowData.is_protected || loadingAction === 'edit'}
                        >
                            {loadingAction === 'edit' ? <Loader2 className="h-5 w-5 animate-spin" /> : <Pencil className="h-5 w-5" />}
                        </button>

                        <button onClick={(e) => { e.stopPropagation(); withLoader('delete', () => handleDeleteSchedule(rowData.slug)); }}
                            className={`text-gray-600 hover:text-red-600 p-1 rounded transition-colors ${rowData.is_protected || deleting ? 'opacity-50 cursor-not-allowed' : '' }`}
                            title="Delete" 
                            disabled={rowData.is_protected || deleting || loadingAction === 'delete'} 
                        >
                            {loadingAction === 'delete' ? <Loader2 className="h-5 w-5 animate-spin" /> : <Trash className="h-5 w-5" />}
                        </button>
                    </div>
                )}
            </td>
            <td className="p-3 text-gray-900 text-sm w-1/4" onMouseEnter={() => setHoveredRowHash(rowData.slug)} onMouseLeave={() => setHoveredRowHash(null)} >
                {rowData.display}
            </td>
            <td
                className="p-3 text-gray-900 text-sm w-1/4"
                onMouseEnter={() => setHoveredRowHash(rowData.slug)}
                onMouseLeave={() => setHoveredRowHash(null)}
            >
                <span className="inline-flex items-center px-2.5 py-0.5 text-black">
                    {rowData.interval}
                </span>
            </td>
        </>
    );
};