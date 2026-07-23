import { useState } from 'react';
import { Pencil, CirclePlay, CirclePause, Trash, Play, Loader2 } from 'lucide-react';

export const HoveredActions = ({ handleEdit, handleRunNow, handleResume, handlePause, handleDelete, rowData, isPaused }) => {
    const [loadingAction, setLoadingAction] = useState(null);
    const isPluginSystem = rowData.is_plugin_system === true;
    const deleteBlocked = isPluginSystem || rowData.is_protected;
    const systemLockedTitle = "Managed by Tailwatch";

    const withLoader = async (actionType, callback) => {
        setLoadingAction(actionType);
        try {
            await callback();
        } finally {
            setLoadingAction(null);
        }
    };

    return (
        <div className="absolute right-4 top-1/2 transform -translate-y-1/2 flex items-center space-x-2 bg-white py-1 px-2 rounded-md shadow-lg border border-gray-200 z-10 animate-fade-in">
            <button onClick={(e) => { e.stopPropagation(); if (!isPluginSystem) withLoader('edit', () => handleEdit(rowData)); }} className={`text-gray-600 p-1 rounded transition-colors ${isPluginSystem ? 'opacity-50 cursor-not-allowed' : 'hover:text-blue-600'}`} title={isPluginSystem ? systemLockedTitle : "Edit"} disabled={loadingAction === 'edit' || isPluginSystem}>
                {loadingAction === 'edit' ? <Loader2 className="h-5 w-5 animate-spin" /> : <Pencil className="h-5 w-5" />}
            </button>

            <button onClick={(e) => { e.stopPropagation(); if (!isPluginSystem) withLoader('run', () => handleRunNow(rowData.hash)); }} className={`text-gray-600 p-1 rounded transition-colors ${isPaused || isPluginSystem ? 'opacity-50 cursor-not-allowed' : 'hover:text-green-600'} `} title={isPluginSystem ? systemLockedTitle : "Run Now"} disabled={loadingAction === 'run' || isPaused === true || isPluginSystem} >
                {loadingAction === 'run' ? <Loader2 className="h-5 w-5 animate-spin" /> : <Play className="h-5 w-5" />}
            </button>

            <button onClick={(e) => { e.stopPropagation(); if (!isPluginSystem) withLoader(isPaused ? 'resume' : 'pause', () => isPaused ? handleResume(rowData.hash) : handlePause(rowData.hash)); }} className={`text-gray-600 p-1 rounded transition-colors ${isPluginSystem ? 'opacity-50 cursor-not-allowed' : 'hover:text-amber-600'}`} title={isPluginSystem ? systemLockedTitle : (isPaused ? 'Resume' : 'Pause')} disabled={loadingAction === 'pause' || loadingAction === 'resume' || isPluginSystem} >
                {loadingAction === 'pause' || loadingAction === 'resume' ? (
                    <Loader2 className="h-5 w-5 animate-spin" />
                ) : isPaused ? (
                    <CirclePlay className="h-5 w-5" />
                ) : (
                    <CirclePause className="h-5 w-5" />
                )}
            </button>

            <button onClick={(e) => { e.stopPropagation(); if (!deleteBlocked) { withLoader('delete', () => handleDelete(rowData.hash)); } }} className={`text-gray-600 hover:text-red-600 p-1 rounded transition-colors ${deleteBlocked ? 'opacity-50 cursor-not-allowed' : ''}`} title={isPluginSystem ? systemLockedTitle : "Delete"} disabled={loadingAction === 'delete' || deleteBlocked} >
                {loadingAction === 'delete' ? (
                    <Loader2 className="h-5 w-5 animate-spin" />
                ) : (
                    <Trash className="h-5 w-5" />
                )}
            </button>
        </div>
    );
};