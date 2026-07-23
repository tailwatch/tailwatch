import React, { useState, useEffect, useRef, useCallback } from 'react';
import { Bell, AlertTriangle, Database, Search, Clock, X } from 'lucide-react';
import { getAlerts } from './AlertService/AlertService'
import { useNavigate } from 'react-router-dom';
const AlertBadge = () => {
  const [alerts, setAlerts] = useState([]);
  const [isOpen, setIsOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [hasMore, setHasMore] = useState(true);
  const [page, setPage] = useState(1);
  const [totalCount, setTotalCount] = useState(0);
  const dropdownRef = useRef(null);
  const observer = useRef();
  const navigate = useNavigate();

  // Load initial alerts
  useEffect(() => {
    loadAlerts(1, true);
  }, []);

  // Close dropdown when clicking outside
  useEffect(() => {
    const handleClickOutside = (event) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target)) {
        setIsOpen(false);
      }
    };

    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const loadAlerts = async (pageNum, reset = false) => {
    if (loading) return;

    setLoading(true);
    try {
      const response = await getAlerts(pageNum, 10, 'active');

      if (response.data.data.code === 200) {
        const newAlerts = response.data.data.data;
        const pagination = response.data.data.pagination;

        setAlerts(prev => reset ? newAlerts : [...prev, ...newAlerts]);
        setTotalCount(pagination.total);
        setHasMore(pageNum < pagination.total_pages);
        setPage(pageNum);
      }
    } catch (error) {
    } finally {
      setLoading(false);
    }
  };

  // Infinite scroll callback
  const lastAlertElementRef = useCallback(node => {
    if (loading) return;
    if (observer.current) observer.current.disconnect();

    observer.current = new IntersectionObserver(entries => {
      if (entries[0].isIntersecting && hasMore) {
        loadAlerts(page + 1);
      }
    });

    if (node) observer.current.observe(node);
  }, [loading, hasMore, page]);

  const parseAlertValue = (valueString) => {
    try {
      return JSON.parse(valueString);
    } catch (error) {
      return { message: 'Unable to parse alert data', error: valueString };
    }
  };

  const getAlertIcon = (key, option) => {
    const iconClass = option === 'high' ? 'text-red-500' : 'text-yellow-500';
    return <AlertTriangle className={`w-4 h-4 ${iconClass}`} />;
  };

  const formatDate = (dateString) => {
    const date = new Date(dateString);
    const now = new Date();
    const diffInMinutes = Math.floor((now - date) / (1000 * 60));

    if (diffInMinutes < 1) return 'Just now';
    if (diffInMinutes < 60) return `${diffInMinutes}m ago`;
    if (diffInMinutes < 1440) return `${Math.floor(diffInMinutes / 60)}h ago`;
    return `${Math.floor(diffInMinutes / 1440)}d ago`;
  };

  const truncateError = (error, maxLength = 100) => {
    if (error.length <= maxLength) return error;
    return error.substring(0, maxLength) + '...';
  };

  return (
    <div className="relative inline-block" ref={dropdownRef}>
      <button onClick={() => setIsOpen(!isOpen)} className="relative p-2 text-orange-600 hover:text-orange-800 focus:outline-none focus:ring-2 focus:ring-orange-500 focus:ring-offset-2 rounded-full transition-colors" >
        <Bell className="w-6 h-6" />
        {totalCount > 0 && (
          <span className="absolute -top-1 -right-1 bg-red-500 text-white text-xs font-bold rounded-full h-5 w-5 flex items-center justify-center animate-pulse">
            {totalCount > 99 ? '99+' : totalCount}
          </span>
        )}
        {totalCount > 0 && (
          <span className="absolute top-0 right-0 h-3 w-3 bg-red-400 rounded-full animate-ping"></span>
        )}
      </button>

      {isOpen && (
        <div className="absolute right-0 mt-2 w-96 bg-white rounded-lg shadow-xl border border-gray-200 z-50 max-h-96 overflow-hidden">
          <div className="px-4 py-3 border-b border-gray-200 flex items-center justify-between bg-gray-50">
            <div className="flex items-center gap-3">
              <h3 className="text-lg font-semibold text-gray-800">
                Activity Logs ({totalCount})
              </h3>
              <button onClick={() => navigate("/dashboard/system-logs/error-logs")} className="text-sm text-blue-600 hover:underline" >
                View All
              </button>
            </div>
            <button onClick={() => setIsOpen(false)} className="text-gray-400 hover:text-gray-600 transition-colors" >
              <X className="w-5 h-5" />
            </button>
          </div>

          {/* Alerts List */}
          <div className="max-h-80 overflow-y-auto">
            {alerts.length > 0 ? (
              alerts.map((alert, index) => {
                const parsedValue = parseAlertValue(alert.value);
                const isLast = index === alerts.length - 1;

                return (
                  <div key={alert.id} ref={isLast ? lastAlertElementRef : null} className="px-4 py-3 border-b border-gray-100 hover:bg-gray-50 transition-colors" >
                    <div className="flex items-start space-x-3">
                      {/* Alert Icon */}
                      <div className="flex-shrink-0 mt-1">
                        {getAlertIcon(alert.key, alert.option)}
                      </div>

                      {/* Alert Content */}
                      <div className="flex-1 min-w-0">
                        <div className="flex items-center justify-between mb-1">
                          <h4 className="text-sm font-medium text-gray-900 capitalize">
                            {alert.key.replace('_', ' ')}
                          </h4>
                          <span className={`px-2 py-1 text-xs font-medium rounded-full ${alert.option === 'high'
                              ? 'bg-red-100 text-red-800'
                              : 'bg-yellow-100 text-yellow-800'
                            }`}>
                            {alert.option}
                          </span>
                        </div>

                        <p className="text-sm text-gray-600 mb-2">
                          {parsedValue.message}
                        </p>

                        {parsedValue.error && (
                          <p className="text-xs text-gray-500 mb-2 font-mono bg-gray-100 p-2 rounded">
                            {truncateError(parsedValue.error)}
                          </p>
                        )}

                        {parsedValue.affected_features && (
                          <div className="flex flex-wrap gap-1 mb-2">
                            {parsedValue.affected_features.map((feature, idx) => (
                              <span
                                key={idx}
                                className="px-2 py-1 text-xs bg-blue-100 text-blue-800 rounded"
                              >
                                {feature}
                              </span>
                            ))}
                          </div>
                        )}

                        <div className="flex items-center justify-between text-xs text-gray-500">
                          <span className="flex items-center">
                            <Clock className="w-3 h-3 mr-1" />
                            {formatDate(alert.date_created)}
                          </span>
                          <span>ID: {alert.id}</span>
                        </div>
                      </div>
                    </div>
                  </div>
                );
              })
            ) : (
              <div className="px-4 py-8 text-center text-gray-500">
                <Bell className="w-8 h-8 mx-auto mb-2 text-gray-300" />
                <p>No alerts found</p>
              </div>
            )}

            {/* Loading indicator */}
            {loading && (
              <div className="px-4 py-3 text-center text-gray-500">
                <div className="inline-flex items-center">
                  <div className="animate-spin rounded-full h-4 w-4 border-b-2 border-blue-500 mr-2"></div>
                  Loading more alerts...
                </div>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
};

export default AlertBadge;