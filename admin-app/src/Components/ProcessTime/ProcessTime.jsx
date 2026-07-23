import React, { useState, useEffect } from 'react';
import { Play, Clock, Timer, TrendingUp, HardDrive } from 'lucide-react';

const ProcessTime = ({ startedTime, currentTime, progress, isCompleted, backupSize, backupSection = false, timeZone,estimationTime }) => {
    const [realTimeCurrentTime, setRealTimeCurrentTime] = useState(Date.now());
    
    useEffect(() => {
        if (isCompleted) return;
        const interval = setInterval(() => {
            setRealTimeCurrentTime(Date.now());
        }, 1000);
        return () => clearInterval(interval);
    }, [isCompleted]);
    
    const formatTime = (timestamp) => {
        if (!timestamp) return '--:--:-- --';
        // WordPress current_time('timestamp') returns timezone-adjusted timestamp
        // So we treat it as UTC and display without timezone conversion
        const date = new Date(timestamp * 1000);
        return date.toLocaleTimeString('en-US', {
            timeZone: 'UTC',
            hour12: true,
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit'
        });
    };

    // Helper function to format file size
    const formatSize = (sizeInBytes) => {
        if (!sizeInBytes || sizeInBytes === 0) return '0 B';
        const bytes = parseInt(sizeInBytes);
        if (isNaN(bytes)) return '--';
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(1024));
        if (i === 0) return `${bytes} B`;
        const size = (bytes / Math.pow(1024, i)).toFixed(1);
        return `${size} ${sizes[i]}`;
    };

    // Helper function to calculate runtime duration
    const calculateRuntime = (start, current) => {
        if (!start) return '--m --s';
        
        const startMs = start * 1000;
        let currentMs;
        
        if (isCompleted) {
            // When completed, use the provided currentTime
            currentMs = current ? current * 1000 : startMs;
        } else {
            // When running, use currentTime if provided, otherwise use real-time
            // This allows for server-side time tracking
            currentMs = current ? current * 1000 : realTimeCurrentTime;
        }
        
        const diffMs = currentMs - startMs;
        if (diffMs < 0) return '--m --s';
        
        const totalSeconds = Math.floor(diffMs / 1000);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;
        
        if (minutes > 0) {
            return `${minutes}m ${seconds}s`;
        } else {
            return `${seconds}s`;
        }
    };

    const getStartTime = () => {
        return startedTime ? formatTime(startedTime) : '--:--:-- --';
    };

    const getCurrentTime = () => {
        return estimationTime ? formatTime(estimationTime) : '--:--:-- --';
    };

    const getRuntime = () => {
        return calculateRuntime(startedTime, currentTime);
    };

    const getProgress = () => {
        return progress ? `${100 - progress}%` : '--';
    };

    const getBackupSize = () => {
        return formatSize(backupSize);
    };

    return (
        <div className="mt-4 border border-gray-300 rounded-2xl p-[4px]">
            <div className="bg-white rounded-xl p-4">
                <div className="flex flex-wrap items-center justify-between gap-6">
                    <div className="flex items-center gap-2">
                        <div className="w-8 h-8 bg-cyan-500 rounded-lg flex items-center justify-center">
                            <Play className="w-4 h-4 text-white fill-white" />
                        </div>
                        <div>
                            <div className="text-xs text-gray-500">Started</div>
                            <div className="text-sm font-bold text-gray-900">{getStartTime()}</div>
                        </div>
                    </div>
                    
                    <div className="h-8 w-px bg-gray-300"></div>
                    
                    <div className="flex items-center gap-2">
                        <div className="w-8 h-8 bg-green-500 rounded-lg flex items-center justify-center">
                            <Clock className="w-4 h-4 text-white" />
                        </div>
                        <div>
                            <div className="text-xs text-gray-500">Estimation Time</div>
                            <div className="text-sm font-bold text-gray-900">{getCurrentTime()}</div>
                        </div>
                    </div>
                    
                    <div className="h-8 w-px bg-gray-300"></div>
                    
                    <div className="flex items-center gap-2">
                        <div className="w-8 h-8 bg-orange-500 rounded-lg flex items-center justify-center">
                            <Timer className="w-4 h-4 text-white" />
                        </div>
                        <div>
                            <div className="text-xs text-gray-500">Runtime</div>
                            <div className="text-sm font-bold text-gray-900">{getRuntime()}</div>
                        </div>
                    </div>
                    
                    <div className="h-8 w-px bg-gray-300"></div>
                    
                    <div className="flex items-center gap-2">
                        <div className="w-8 h-8 bg-purple-500 rounded-lg flex items-center justify-center">
                            <TrendingUp className="w-4 h-4 text-white" />
                        </div>
                        <div>
                            <div className="text-xs text-gray-500">Remaining</div>
                            <div className="text-sm font-bold text-gray-900">{getProgress()}</div>
                        </div>
                    </div>
                    
                    {backupSection && (
                        <>
                            <div className="h-8 w-px bg-gray-300"></div>
                            <div className="flex items-center gap-2">
                                <div className="w-8 h-8 bg-blue-500 rounded-lg flex items-center justify-center">
                                    <HardDrive className="w-4 h-4 text-white" />
                                </div>
                                <div>
                                    <div className="text-xs text-gray-500">Size</div>
                                    <div className="text-sm font-bold text-gray-900">{getBackupSize()}</div>
                                </div>
                            </div>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
};

export default ProcessTime;