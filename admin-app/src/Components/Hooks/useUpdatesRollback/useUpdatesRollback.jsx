import { useEffect, useState, useCallback } from "react";
import { useNavigate, useLocation } from "react-router-dom";
import { getTotalUpdates, bulkPluginAction, getViewDetails } from '../../../Pages/Updates/UpdateServices/UpdateServices';
import { toast } from 'react-toastify';
import { useUpdateRollbackFeature } from "../Features/UseFeatures";
import { alertService } from '../../../Components/AlertService/AlertService';

export const useUpdatesRollback = () => {
    const { updateRollback, refreshUpdateRollback } = useUpdateRollbackFeature();
    const [activeTab, setActiveTab] = useState("theme");
    const [themeUpdateCount, setThemeUpdateCount] = useState(0);
    const [pluginUpdateCount, setPluginUpdateCount] = useState(0);
    const [coreUpdateCount, setCoreUpdateCount] = useState(0);
    const [error, setError] = useState(null);
    const [selectedPlugins, setSelectedPlugins] = useState([]);
    const navigate = useNavigate();
    const location = useLocation();
    const currentPath = location.pathname.split('/').pop();
    const [searchTerm, setSearchTerm] = useState('');
    const [refetchCallbacks, setRefetchCallbacks] = useState({
        plugin: null,
        theme: null,
        coreUpdates: null
    });
    
    const [isHistoryModalOpen, setIsHistoryModalOpen] = useState(false);
    const [historyData, setHistoryData] = useState([]);
    const [historyLoading, setHistoryLoading] = useState(false);
    const [historyPage, setHistoryPage] = useState(1);
    const [hasMoreHistory, setHasMoreHistory] = useState(true);
    const [isLoadingMore, setIsLoadingMore] = useState(false);
    const [loading, setLoading] = useState(false);

    useEffect(() => {
        const validPaths = ["theme", "plugin", "coreUpdates"];
        if (!validPaths.includes(currentPath)) {
            navigate("/dashboard/updates/theme");
        } else {
            setActiveTab(currentPath);
            setSelectedPlugins([]);
        }
    }, [currentPath, navigate]);

    useEffect(() => {
        const fetchTotalUpdates = async () => {
            setLoading(true);
            try {
                await getTotalUpdates(setThemeUpdateCount, setPluginUpdateCount, setCoreUpdateCount, setError );
            } catch (error) {
            } finally {
                setLoading(false);
            }
        };

        fetchTotalUpdates();
    }, []);

    const handleSelectPlugin = (pluginFile) => {
        setSelectedPlugins(prev =>
            prev.includes(pluginFile)
                ? prev.filter(p => p !== pluginFile)
                : [...prev, pluginFile]
        );
    };

    const handleSelectAll = (allPluginFiles) => {
        if (activeTab !== 'plugin') return;

        const allSelected = allPluginFiles.every(plugin => selectedPlugins.includes(plugin));

        if (allSelected) {
            setSelectedPlugins(prev => prev.filter(p => !allPluginFiles.includes(p)));
        } else {
            setSelectedPlugins(prev => [...new Set([...prev, ...allPluginFiles])]);
        }
    };

    const clearSelection = () => setSelectedPlugins([]);

    const handleTabClick = (tab) => {
        if (tab === activeTab) return;
        setActiveTab(tab);
        navigate(`/dashboard/updates/${tab}`);
    };

    const tabs = [
        { key: "theme", label: "Themes", count: themeUpdateCount },
        { key: "plugin", label: "Plugins", count: pluginUpdateCount },
        { key: "coreUpdates", label: "Core Updates", count: coreUpdateCount }
    ];

    const isValidTab = ["theme", "plugin", "coreUpdates"].includes(currentPath);

    const refreshUpdateData = async () => {
        setLoading(true);
        try {
            await refreshUpdateRollback();

            const refetch = refetchCallbacks[activeTab];
            if (refetch) {
                await refetch();
            }
        } finally {
            setLoading(false);
        }
    };

    const registerRefetch = (tab, callback) => {
        setRefetchCallbacks(prev => ({
            ...prev,
            [tab]: callback
        }));
    };

    const handleBulkActivate = async (pluginsToProcess = null) => {
        const plugins = pluginsToProcess || selectedPlugins;

        if (plugins.length === 0) {
            toast.error('Please select at least one plugin');
            return;
        }

        try {
            alertService.showLoading('Activating...', `Activating ${plugins.length} plugin(s)`);
            await bulkPluginAction('activate', plugins);
            alertService.closeLoading();
            clearSelection();
            const refetch = refetchCallbacks.plugin;
            if (refetch) {
                await refetch();
            }
        } catch (error) {
            alertService.closeLoading();
            console.error('Bulk activate failed:', error);
        }
    };

    const handleBulkDeactivate = async (pluginsToProcess = null) => {
        const plugins = pluginsToProcess || selectedPlugins;

        if (plugins.length === 0) {
            toast.error('Please select at least one plugin');
            return;
        }
        try {
            alertService.showLoading('Deactivating...', `Deactivating ${plugins.length} plugin(s)`);
            await bulkPluginAction('deactivate', plugins);
            alertService.closeLoading();
            clearSelection();
            const refetch = refetchCallbacks.plugin;
            if (refetch) {
                await refetch();
            }
        } catch (error) {
            alertService.closeLoading();
            console.error('Bulk deactivate failed:', error);
        }

    };

    const handleBulkDelete = async (pluginsToProcess = null) => {
        const plugins = pluginsToProcess || selectedPlugins;

        if (plugins.length === 0) {
            toast.error('Please select at least one plugin');
            return;
        }
        try {
            alertService.showLoading('Deleting...', `Deleting ${plugins.length} plugin(s)`);
            await bulkPluginAction('delete', plugins);
            alertService.closeLoading();
            clearSelection();
            const refetch = refetchCallbacks.plugin;
            if (refetch) {
                await refetch();
            }
        } catch (error) {
            alertService.closeLoading();
            console.error('Bulk delete failed:', error);
        }

    };

const formatActionType = (actionType, insertWas = false) => {
  if (!actionType) return '';

  const words = actionType.split('_');

  if (insertWas && words.length > 1) {
    const subject = words[0].toLowerCase();
    const action = words[1].toLowerCase();
    const rest = words.slice(2).join(' ');

    const verbMap = {
      activate: "activated",
      deactivate: "deactivated",
      install: "installed",
      delete: "deleted",
      update: "updated",
      rollback: "rolled back"
    };

    const pastTense =
      verbMap[action] ||
      (action.endsWith('e') ? action + 'd' : action + 'ed');

    return rest
      ? `${subject} was ${pastTense} ${rest}`
      : `${subject} was ${pastTense}`;
  }

  return words
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
};



    const handleViewHistoryClick = async () => {
        setIsHistoryModalOpen(true);
        setHistoryLoading(true);
        setHistoryPage(1);
        setHasMoreHistory(true);
        try {
            const type = activeTab === 'theme' ? 'theme' : activeTab === 'plugin' ? 'plugin' : 'core';
            const response = await getViewDetails(1, 10, type);
            const data = response?.data?.data;
            setHistoryData(data?.history || []);
            const pagination = data?.pagination;
            if (pagination) {
                setHasMoreHistory(pagination.page < pagination.total_pages);
            }
        } catch (error) {
            console.error('Error fetching history:', error);
        } finally {
            setHistoryLoading(false);
        }
    };

    const loadMoreHistory = useCallback(async () => {
        if (isLoadingMore || !hasMoreHistory) return;
        setIsLoadingMore(true);
        try {
            const type = activeTab === 'theme' ? 'theme' : activeTab === 'plugin' ? 'plugin' : 'core';
            const nextPage = historyPage + 1;
            const response = await getViewDetails(nextPage, 10, type);
            const data = response?.data?.data;
            const newHistory = data?.history || [];
            setHistoryData(prev => [...prev, ...newHistory]);
            setHistoryPage(nextPage);
            const pagination = data?.pagination;
            if (pagination) {
                setHasMoreHistory(pagination.page < pagination.total_pages);
            }
        } catch (error) {
            console.error('Error loading more history:', error);
        } finally {
            setIsLoadingMore(false);
        }
    }, [activeTab, historyPage, isLoadingMore, hasMoreHistory]);

    const handleHistoryScroll = useCallback((e) => {
        const { scrollTop, scrollHeight, clientHeight } = e.target;
        if (scrollHeight - scrollTop <= clientHeight + 50) {
            loadMoreHistory();
        }
    }, [loadMoreHistory]);

    const handleCloseHistoryModal = () => {
        setIsHistoryModalOpen(false);
        setHistoryData([]);
        setHistoryPage(1);
        setHasMoreHistory(true);
    };

    return {
        tabs,
        handleTabClick,
        activeTab,
        isValidTab,
        setSearchTerm,
        searchTerm,
        updateRollback,
        refreshUpdateData,
        loading,
        selectedPlugins,
        handleSelectAll,
        handleSelectPlugin,
        clearSelection,
        handleBulkActivate,
        handleBulkDeactivate,
        handleBulkDelete,
        registerRefetch,
        themeUpdateCount,
        pluginUpdateCount,
        coreUpdateCount,
        isHistoryModalOpen,
        historyData,
        historyLoading,
        hasMoreHistory,
        isLoadingMore,
        formatActionType,
        handleViewHistoryClick,
        handleHistoryScroll,
        handleCloseHistoryModal,
    };
};