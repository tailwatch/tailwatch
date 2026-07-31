import { useState, useEffect, useRef, useMemo } from "react";
import { fetchData, handleDelete, checkLocalStorage, fetchFilterOptions } from '../../../Pages/Logs/LogsServices/LogsServices';
import { alertService } from "../../AlertService/AlertService";

export const useLogsActivity = (activeTab, key, option) => {
  const [isErrorModalOpen, setIsErrorModalOpen] = useState(false);
  const [isDetailModalOpen, setIsDetailModalOpen] = useState(false);
  const [currentError, setCurrentError] = useState('');
  const [searchTerm, setSearchTerm] = useState('');
  const [currentDetail, setCurrentDetail] = useState({});
  const [selectedLogs, setSelectedLogs] = useState([]);
  const [isDeleting, setIsDeleting] = useState(false);
  const [loading, setLoading] = useState(true);
  const [rawTabData, setRawTabData] = useState([]);
  const [isInitialLoad, setIsInitialLoad] = useState(true);
  const initialMount = useRef(true);
  const [pagination, setPagination] = useState({ page: 0, limit: 10, total_pages: 1, total_items: 0 });
  const [featureEnable, setFeatureEnable] = useState(null);
  const [parentEnable, setParentEnable] = useState(null);
  // Dynamic filter bar: available options per facet + the current selection.
  const [filterOptions, setFilterOptions] = useState({});
  const [selectedFilters, setSelectedFilters] = useState({});
  const [featuresInfo, setFeaturesInfo] = useState({
    user: { featureId: null, isActive: null },
    error: { featureId: null, isActive: null },
    email: { featureId: null, isActive: null }
  });
  // Filter data based on search term
  const filteredTabData = useMemo(() => {
    if (!searchTerm.trim()) {
      return rawTabData;
    }

    const searchLower = searchTerm.toLowerCase();

    return rawTabData.filter(item => {
      switch (activeTab) {
        case 'user':
          return (
            item?.title?.toLowerCase().includes(searchLower) ||
            item?.body?.toLowerCase().includes(searchLower) ||
            item?.user_data?.toLowerCase().includes(searchLower) ||
            item?.type?.toLowerCase().includes(searchLower)
          );
        case 'email':
          return (
            item?.parsedValue?.subject?.toLowerCase().includes(searchLower) ||
            item?.parsedValue?.sent_to?.toLowerCase().includes(searchLower) ||
            item?.parsedValue?.delivery_time?.toLowerCase().includes(searchLower) ||
            item?.parsedValue?.status?.toLowerCase().includes(searchLower)
          );
        case 'error':
          return (
            item?.parsedValue?.log_message?.toLowerCase().includes(searchLower) ||
            item?.parsedValue?.status_code?.toString().includes(searchLower)
          );
        default:
          // Generic search for any string properties
          return Object.values(item).some(value =>
            value && typeof value === 'string' && value.toLowerCase().includes(searchLower)
          );
      }
    });
  }, [rawTabData, searchTerm, activeTab]);

  // Use filtered data as tabData
  const tabData = filteredTabData;

  useEffect(() => {
    const loadFeaturesInfo = async () => {
      await checkLocalStorage((data) => {
        setFeaturesInfo({
          user: {
            featureId: data.featureId,
            isActive: data.isActive
          },
          error: {
            featureId: data.errorLogsFeatureId,
            isActive: data.errorLogsIsActive
          },
          email: {
            featureId: data.emailLogsFeatureId,
            isActive: data.emailLogsIsActive
          }
        });
      });
    };
    loadFeaturesInfo();
  }, []);

  // Load the dynamic filter options (distinct facet values) for this log view.
  useEffect(() => {
    if (!option) return;
    let active = true;
    fetchFilterOptions(key, option).then((opts) => { if (active) setFilterOptions(opts || {}); });
    return () => { active = false; };
  }, [key, option]);

  const isFeatureEnabled = () => {
    return featureEnable !== false && parentEnable !== false;
  };

  const getFeatureStatusMessage = () => {
    if (featureEnable === false || parentEnable === false) {
      if (parentEnable === false) {
        return `${activeTab.charAt(0).toUpperCase() + activeTab.slice(1)} Logs feature is disabled. Please enable it from the settings.`;
      }
      if (featureEnable === false) {
        return "Configure your settings first";
      }
    }
    return "";
  };

  const handleErrorModalOpen = (error) => {
    setCurrentError(error);
    setIsErrorModalOpen(true);
  };

  const handleDetailModalOpen = (detail) => {
    setCurrentDetail(detail);
    setIsDetailModalOpen(true);
  };

  const handleModalClose = () => {
    setIsErrorModalOpen(false);
    setIsDetailModalOpen(false);
    setCurrentError('');
    setCurrentDetail({});
  };

  const refreshFeaturesStatus = async () => {
    setFeatureEnable(null);
    setParentEnable(null);
    await checkLocalStorage((data) => {
      setFeaturesInfo({
        user: {
          featureId: data.featureId,
          isActive: data.isActive
        },
        error: {
          featureId: data.errorLogsFeatureId,
          isActive: data.errorLogsIsActive
        },
        email: {
          featureId: data.emailLogsFeatureId,
          isActive: data.emailLogsIsActive
        }
      });
    });
    fetchTabData();
  };

  const handleSelectLog = (logId) => {
    if (selectedLogs.includes(logId)) {
      setSelectedLogs(selectedLogs.filter(id => id !== logId));
    } else {
      setSelectedLogs([...selectedLogs, logId]);
    }
  };

  const eligibleLogs = tabData;
  const allSelected = eligibleLogs.length > 0 && eligibleLogs.every(log => selectedLogs.includes(log.id));

  const handleSelectAll = () => {
    const eligibleIds = eligibleLogs.map(log => log.id);
    if (allSelected) {
      setSelectedLogs(selectedLogs.filter(id => !eligibleIds.includes(id)));
    } else {
      setSelectedLogs([...selectedLogs, ...eligibleIds.filter(id => !selectedLogs.includes(id))]);
    }
  };

  const handleDeleteAll = async () => {
    const confirmed = await alertService.confirm("This can't be undone. it removes all data from database", "Are you sure to delete all data ?");
    if (!confirmed) {
      return;
    }
    setIsDeleting(true);
    const deleteData = { ids: [], key, option, is_delete: true };
    await handleDelete({ deleteData, setIsDeleting, fetchTabData });
    setSelectedLogs([]);
    setIsDeleting(false);
  };

  const handleBulkDelete = async () => {
    const confirmed = await alertService.confirm("This can't be undone.", "Are you sure to delete this?");
    if (!confirmed) {
      return;
    }
    if (selectedLogs.length > 0) {
      setIsDeleting(true);
      const ids = selectedLogs;
      const deleteData = { ids: ids, key, option, is_delete: false };
      await handleDelete({ deleteData, setIsDeleting, fetchTabData });
      setSelectedLogs([]);
      setIsDeleting(false);
    }
  };


  const getFeatureInfo = () => {
    return featuresInfo[activeTab] || { featureId: null, isActive: null };
  };

  const fetchTabData = async (page = pagination.page, limit = pagination.limit, filtersOverride = null) => {
    setLoading(true);
    let fetchedData = [];

    try {
      const activeFilters = filtersOverride !== null ? filtersOverride : selectedFilters;
      const paginationParams = {
        page: page + 1, limit,
        setPagination: (paginationData) => {
          setPagination({ page: paginationData.page - 1, limit: paginationData.limit, total_pages: paginationData.total_pages, total_items: paginationData.total_items || 0 });
        }
      };

      await fetchData(key, option, () => { }, (data) => {
        fetchedData = data;
      }, paginationParams, setFeatureEnable, setParentEnable, activeFilters);

      setRawTabData(fetchedData);
      setLoading(false);

      if (isInitialLoad) {
        setIsInitialLoad(false);
      }
    } catch (error) {
      setLoading(false);
    }
  };

  // Handle page change
  const handlePageChange = (newPage) => {
    setPagination(prev => ({ ...prev, page: newPage }));
    fetchTabData(newPage, pagination.limit);
  };

  const handlePageSizeChange = (newPageSize) => {
    setPagination(prev => ({ ...prev, limit: newPageSize, page: 0 }));
    fetchTabData(0, newPageSize);
    setSelectedLogs([]);
  };

  // Apply a full set of facet selections at once (from the filter modal), reset to page 1, refetch.
  const applyFilters = (nextFilters) => {
    const next = nextFilters || {};
    setSelectedFilters(next);
    setPagination(prev => ({ ...prev, page: 0 }));
    setSelectedLogs([]);
    fetchTabData(0, pagination.limit, next);
  };

  const clearFilters = () => {
    setSelectedFilters({});
    setPagination(prev => ({ ...prev, page: 0 }));
    setSelectedLogs([]);
    fetchTabData(0, pagination.limit, {});
  };

  return {
    isErrorModalOpen, isDetailModalOpen, featureEnable, setFeatureEnable, setParentEnable, parentEnable, currentError, currentDetail, handleErrorModalOpen, handleDetailModalOpen, handleModalClose, isFeatureEnabled, getFeatureStatusMessage, loading, setLoading, handleDeleteAll,
    tabData, setTabData: setRawTabData, isInitialLoad, setIsInitialLoad, handlePageSizeChange, initialMount, handlePageChange, fetchTabData, getFeatureInfo, refreshFeaturesStatus, handleSelectAll, handleBulkDelete, allSelected, isDeleting, searchTerm, setSearchTerm, selectedLogs, handleSelectLog, pagination,
    filterOptions, selectedFilters, applyFilters, clearFilters,
  };
};