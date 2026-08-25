import React, { useEffect, useState, useRef, useCallback } from 'react';
import { useParams } from 'react-router-dom';
import { UpdateDescriptionLoader, UpdateHeaderLoader } from '../../../Components/Skeleton/LoaderSkeleton';
import UpdateDetails from '../InnerContent/UpdateDetails/UpdateDetails';
import UpdateStats from './UpdateDetails/UpdateStats';
import UpdateHeader from './UpdateDetails/UpdateHeader';
import { updatePlugin, updateTheme, fetchPluginThemesDetails, rollbackPlugin, rollbackTheme, rollbackWordpress, updateCoreWordpress, getWordpressDetails, getPluginHistory, getThemeHistory, getCoreHistory } from '../UpdateServices/UpdateServices'
import Header from '../../../Components/Header/Header';
import LoadingBar from 'react-top-loading-bar';

const Innerscreen = () => {
  const { pluginSlug } = useParams();
  const [pluginDetails, setPluginDetails] = useState(null);
  const [shouldRefetch, setShouldRefetch] = useState(false);
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(true);
  const [error, setError] = useState(null);
  const [updateStatus, setUpdateStatus] = useState('');
  const [rollbackStatus, setrollbackStatus] = useState('');
  const [responseMessage, setResponseMessage] = useState('');
  const [progress, setProgress] = useState(0);
  const [activeTab, setActiveTab] = useState('details');
  const progressRef = useRef(0);
  const slugParts = pluginSlug.split('&');
  const fetchPluginDetail = slugParts[0];
  const type = slugParts[slugParts.length - 1];
  const loadingBarRef = useRef(null);

  // Activity history (now rendered inside the History tab instead of a modal).
  // Refetched every time the History tab is clicked.
  const [historyData, setHistoryData] = useState([]);
  const [historyLoading, setHistoryLoading] = useState(false);
  const [historyPage, setHistoryPage] = useState(1);
  const [hasMoreHistory, setHasMoreHistory] = useState(true);
  const [isLoadingMore, setIsLoadingMore] = useState(false);

  useEffect(() => {
    if (loading || detailLoading) {
      loadingBarRef.current.continuousStart();
    } else {
      loadingBarRef.current.complete();
    }
  }, [loading, detailLoading]);

  useEffect(() => {
    setError(null);
    setPluginDetails(null);

    if (pluginSlug === 'wordpress&coreUpdates') {
      getWordpressDetails({ setLoading, setProgress, setPluginDetails, setError, pluginSlug, setDetailLoading });
      return;
    }

    const action_type = type === 'plugin' ? 'tailwatch_plugin_details' : 'tailwatch_theme_details';
    fetchPluginThemesDetails({ pluginSlug, setProgress, setPluginDetails, setError, setLoading, type, setDetailLoading, action_type });
  }, [pluginSlug]);

  useEffect(() => {
    if (updateStatus === 'completed' || rollbackStatus === 'rollback completed' || rollbackStatus === 'rollback failed') {
      setError(null);
      setPluginDetails(null);

      if (pluginSlug === 'wordpress&coreUpdates') {
        getWordpressDetails({ setLoading, setProgress, setPluginDetails, setError, pluginSlug, setDetailLoading });
      } else {
        const action_type = type === 'plugin' ? 'tailwatch_plugin_details' : 'tailwatch_theme_details';
        fetchPluginThemesDetails({ pluginSlug, setProgress, setPluginDetails, setError, setLoading, type, setDetailLoading, action_type });
      }

      // Refetch activity history too if the user is currently viewing the History tab,
      // so the row for the just-completed update/rollback shows up immediately.
      if (activeTab === 'history') {
        loadInitialHistory();
      }
    }
  }, [updateStatus, rollbackStatus, shouldRefetch]);

  const handleStreamResponse = async (response) => {
    const reader = response.body.getReader();
    const decoder = new TextDecoder('utf-8');
    let text = '';
    let lineCount = 0;

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      text += decoder.decode(value, { stream: true });
      lineCount++;

      if (lineCount === 1) setProgress(20);
      else if (lineCount === 2) setProgress(40);
      else if (lineCount === 3) setProgress(60);
      else if (lineCount === 4) setProgress(80);
      else if (lineCount >= 5) setProgress(100);

      setResponseMessage(text);
    }

    return text;
  };

  const handleUpdateClick = async () => {
    setUpdateStatus('');
    setrollbackStatus('');
    setUpdateStatus('updating...');
    setResponseMessage('');
    setProgress(0);
    progressRef.current = 0;

    if (pluginSlug.includes('plugin')) {
      await updatePlugin(fetchPluginDetail, setUpdateStatus, setResponseMessage, handleStreamResponse);
    } else if (pluginSlug.includes('theme')) {
      await updateTheme(pluginSlug, setUpdateStatus, setResponseMessage, handleStreamResponse);
    } else if (pluginSlug.includes('coreUpdates')) {
      await updateCoreWordpress(pluginSlug, setUpdateStatus, setResponseMessage, handleStreamResponse);
    }
  };

  const handleRollbackClick = async (version) => {
    setUpdateStatus('');
    setrollbackStatus('');
    setrollbackStatus('Rolling back...');
    setResponseMessage('');
    setProgress(0);
    progressRef.current = 0;

    try {
      if (pluginSlug.includes('plugin')) {
        await rollbackPlugin(fetchPluginDetail, version, setResponseMessage, setrollbackStatus, setProgress, handleStreamResponse);
      } else if (pluginSlug.includes('theme')) {
        await rollbackTheme(pluginSlug, version, setResponseMessage, setProgress, setrollbackStatus, handleStreamResponse);
      } else if (pluginSlug.includes('coreUpdates')) {
        await rollbackWordpress(pluginSlug, version, setResponseMessage, setProgress, setrollbackStatus, handleStreamResponse);
      }
    } catch (error) {
      setrollbackStatus('failed');
      setResponseMessage('Rollback failed: ' + error.message);
    }
  };

  const handleTabClick = (tab) => {
    setActiveTab(tab);
    // Refetch activity history every time the History tab is clicked so the user always sees fresh data.
    if (tab === 'history') {
      loadInitialHistory();
    }
  };

  const loadInitialHistory = async () => {
    setHistoryLoading(true);
    setHistoryData([]);
    setHistoryPage(1);
    setHasMoreHistory(true);
    try {
      let response;
      if (type === 'plugin') {
        response = await getPluginHistory(1, 10, fetchPluginDetail);
      } else if (type === 'theme') {
        response = await getThemeHistory(1, 10, fetchPluginDetail);
      } else {
        response = await getCoreHistory(1, 10);
      }
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

  const loadMoreHistory = useCallback(async () => {
    if (isLoadingMore || !hasMoreHistory) return;
    setIsLoadingMore(true);
    try {
      const nextPage = historyPage + 1;
      let response;
      if (type === 'plugin') {
        response = await getPluginHistory(nextPage, 10, fetchPluginDetail);
      } else if (type === 'theme') {
        response = await getThemeHistory(nextPage, 10, fetchPluginDetail);
      } else {
        response = await getCoreHistory(nextPage, 10);
      }
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
  }, [type, fetchPluginDetail, historyPage, isLoadingMore, hasMoreHistory]);

  const handleHistoryScroll = useCallback((e) => {
    const { scrollTop, scrollHeight, clientHeight } = e.target;
    if (scrollHeight - scrollTop <= clientHeight + 50) {
      loadMoreHistory();
    }
  }, [loadMoreHistory]);

  return (
    <div>
      <LoadingBar ref={loadingBarRef} height={3} color="#ec5023" />
      <Header title="Updates" showBackIcon={true} />
      <div className="max-w-full mx-auto p-3 sm:p-5 lg:p-7">

        {loading ? (
          <div>
            <UpdateHeaderLoader />
            <div className="flex flex-col lg:flex-row gap-4">
              <div className="w-full lg:w-2/3">
                <UpdateDescriptionLoader />
              </div>
              <div className="w-full lg:w-1/3">
                <UpdateStats
                  pluginDetails={pluginDetails}
                  updateStatus={updateStatus}
                  rollbackStatus={rollbackStatus}
                  setError={setError}
                  handleUpdateClick={handleUpdateClick}
                  handleRollbackClick={handleRollbackClick}
                  setShouldRefetch={setShouldRefetch}
                  loading={loading}
                  detailLoading={detailLoading}
                />
              </div>
            </div>
          </div>

        ) :  error  || pluginDetails?.is_custom? (
          <div className="min-h-[300px] sm:min-h-[400px] flex items-center justify-center px-4">
            <div className="flex flex-col items-center justify-center space-y-3 sm:space-y-4 text-center">
              <div className="w-12 h-12 sm:w-16 sm:h-16 bg-red-100 rounded-full flex items-center justify-center">
                <svg className="w-6 h-6 sm:w-8 sm:h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2} d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <h3 className="text-base sm:text-lg font-semibold text-gray-800">
                Unable to Load Content 
              </h3>
              <p className="text-sm sm:text-base text-gray-600 max-w-md">
                {error ? error : pluginDetails?.message || 'Something went wrong while fetching the details. Please try again later.'}
              </p>
            </div>
          </div>

        ) : (
          <div>
            <UpdateHeader pluginDetails={pluginDetails} pluginSlug={pluginSlug} />
            <div className="flex flex-col lg:flex-row gap-4">
              <div className="w-full lg:w-2/3 order-2 lg:order-1">
                <UpdateDetails
                  activeTab={activeTab}
                  pluginDetails={pluginDetails}
                  handleTabClick={handleTabClick}
                  updateStatus={updateStatus}
                  rollbackStatus={rollbackStatus}
                  historyData={historyData}
                  historyLoading={historyLoading}
                  hasMoreHistory={hasMoreHistory}
                  isLoadingMore={isLoadingMore}
                  formatActionType={formatActionType}
                  onHistoryScroll={handleHistoryScroll}
                />
              </div>
              <div className="w-full lg:w-1/3 order-1 lg:order-2">
                <UpdateStats
                  pluginDetails={pluginDetails}
                  updateStatus={updateStatus}
                  rollbackStatus={rollbackStatus}
                  setError={setError}
                  handleUpdateClick={handleUpdateClick}
                  handleRollbackClick={handleRollbackClick}
                  setShouldRefetch={setShouldRefetch}
                  loading={loading}
                  detailLoading={detailLoading}
                />
              </div>
            </div>
          </div>
        )}
      </div>

    </div>
  );
}

export default Innerscreen;