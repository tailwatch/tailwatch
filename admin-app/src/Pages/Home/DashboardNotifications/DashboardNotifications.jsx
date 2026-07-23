import { useState } from 'react';
import { dashboardGradient, alertOrange2 } from '../../../Components/Utils/Colors/Colors';
import { useRef } from 'react';
import { Settings, CheckCircle, XCircle, AlertCircle, Crown, Shield, Bell, BadgeCheck } from 'lucide-react';
import { activateFeatures } from '../../../Pages/Home/HomeServices/HomeServices';
import { alertService } from '../../../Components/AlertService/AlertService';
import PopUpModal from '../../../Components/Modal/PopUpModal';
import { Spinner } from '../../../Components/Spinner/Spinner';

const FEATURES_PREVIEW_COUNT = 3;

const DashboardNotifications = ({ notificationsData, handleCalculateScore }) => {
  const [isLoading, setIsLoading] = useState(false);
  const [showModal, setShowModal] = useState(false);
  const [activationResults, setActivationResults] = useState(null);
  const [showLoadingOverlay, setShowLoadingOverlay] = useState(false);
  const [expandedCards, setExpandedCards] = useState({});
  const lordIconRef = useRef();

  const toggleExpand = (index) => {
    setExpandedCards(prev => ({ ...prev, [index]: !prev[index] }));
  };

  const handleCardMouseEnter = () => {
    if (lordIconRef.current) {
      lordIconRef.current.play();
    }
  };

  const handleCardMouseLeave = () => {
    if (lordIconRef.current) {
      lordIconRef.current.stop();
    }
  };

  const handleCloseActiveFeatureModal = () => {
    setShowModal(false);
    setActivationResults(null);
    setShowLoadingOverlay(false);
    handleCalculateScore();
  }

  const handleFixAllClick = async () => {
    const confirmed = await alertService.confirm(
      "This will activate all the recommended features listed below. Do you want to continue?",
      "Fix All Issues?",
      "Yes",
      "Cancel"
    );
    if (!confirmed) return;

    setShowLoadingOverlay(true);

    try {
      const payload = notificationsData.map(notification => notification.key);
      const result = await activateFeatures(setIsLoading, payload);
      setShowLoadingOverlay(false);

      if (result) {
        setActivationResults(result);
        setShowModal(true);
      }
    } catch (error) {
      console.error("Error activating features:", error);
      setShowLoadingOverlay(false);
    }
  };

  return (
    <div className={`rounded-xl shadow-lg ${dashboardGradient} border border-orange-200 overflow-hidden transition-all duration-300 hover:shadow-xl p-6 flex flex-col h-full`}
      onMouseEnter={handleCardMouseEnter}
      onMouseLeave={handleCardMouseLeave}
    >
      <div className="flex flex-wrap items-center gap-2 mb-4">
        <div className="w-10 h-10 rounded-full bg-orange-200 flex items-center justify-center flex-shrink-0">
          <Shield size={24} className="text-orange-600" />
        </div>
        <div className="flex-1 min-w-0">
          <h2 className="text-lg font-semibold text-black">What to fix?</h2>
          <p className="text-sm text-black">
            Suggestions and alerts to improve your site's protection.
          </p>
        </div>

        <button
          onClick={handleFixAllClick}
          disabled={notificationsData.length === 0}
          className={`flex-shrink-0 inline-flex items-center px-3 py-2 text-sm font-medium rounded-lg transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2
    ${notificationsData.length === 0
              ? 'bg-gray-400 cursor-not-allowed opacity-70'
              : `${alertOrange2} text-white hover:shadow-md hover:scale-105 hover:opacity-90 focus:ring-orange-500`
            }`}
        >
          <Settings size={16} className="mr-1" />
          Fix All
        </button>
      </div>

      <div className="space-y-4 max-h-[350px] overflow-y-auto custom-scroll-bar flex-grow">
        {notificationsData.length > 0 ? (
          notificationsData.map((notification, index) => (
            <div key={index} className="bg-white p-2 rounded-xl mr-[10px] shadow-md border border-gray-200 transition hover:shadow-lg hover:border-purple-200 relative">
              {notification?.isUpgradeRequired ? (
                <span className="absolute top-2 right-2 inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-orange-100 text-orange-800">
                  <Crown size={12} className="mr-1" />
                  Upgrade
                </span>
              ) : notification?.planRequired && (
                <span className={`absolute top-2 right-2 inline-flex items-center px-2 py-1 rounded-full text-xs font-medium ${notification.planRequired.toLowerCase() === 'free' ? 'bg-green-100 text-green-800' : notification.planRequired.toLowerCase() === 'premium' ? 'bg-blue-100 text-blue-800' : notification.planRequired.toLowerCase() === 'exclusive' ? 'bg-purple-100 text-purple-800' : 'bg-orange-100 text-orange-800'}`}>
                  {notification.planRequired.toLowerCase().includes('business') && <Crown size={12} className="mr-1" />}
                  {notification.planRequired}
                </span>
              )}
              <div className="flex items-start">
                <div className="bg-orange-200 rounded-full px-1 pt-1 mr-3 flex-shrink-0">
                  <Bell size={24} className="text-orange-600" />
                </div>
                <div className="pr-10 sm:pr-16">
                  <span className={`inline-block ${alertOrange2} text-white text-xs font-medium px-3 py-1 rounded-full mb-2`}>
                    {notification?.label}
                  </span>
                  {notification?.featureMessage && (
                    <p className="text-gray-600 text-xs mb-1.5">{notification.featureMessage}</p>
                  )}
                  {notification?.disabledFeatures?.length > 0 && (() => {
                    const isExpanded = expandedCards[index];
                    const features = notification.disabledFeatures;
                    const visible = isExpanded ? features : features.slice(0, FEATURES_PREVIEW_COUNT);
                    const hasMore = features.length > FEATURES_PREVIEW_COUNT;
                    return (
                      <>
                        <ul className="space-y-1">
                          {visible.map((feature, i) => (
                            <li key={i} className="flex items-center gap-1.5 text-sm text-black">
                              <span className="w-1.5 h-1.5 rounded-full bg-orange-400 shrink-0" />
                              {feature}
                            </li>
                          ))}
                        </ul>
                        {hasMore && (
                          <button
                            onClick={() => toggleExpand(index)}
                            className="mt-1.5 text-xs font-medium text-orange-500 hover:text-orange-700 transition-colors"
                          >
                            {isExpanded ? `Show less` : `+${features.length - FEATURES_PREVIEW_COUNT} more — View all`}
                          </button>
                        )}
                      </>
                    );
                  })()}
                </div>
              </div>
            </div>
          ))
        ) : (
          <div className="flex flex-col items-center justify-center text-center py-20 px-6 bg-white rounded-xl shadow-md border border-gray-200 transition hover:border-green-200 hover:shadow-lg">
            <div className="w-20 h-20 rounded-full bg-green-100 flex items-center justify-center mb-4">
              <BadgeCheck size={40} className="text-green-600" />
            </div>
            <p className="text-lg font-semibold text-black">
              Your website is secure
            </p>
            <p className="text-sm text-gray-600 mt-1">
              Everything is running smoothly!
            </p>
          </div>
        )}
      </div>

      {/* Loading Overlay */}
      {showLoadingOverlay && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-8 flex flex-col items-center">
            <Spinner />
            <p className="mt-4 text-gray-600">Activating features, please wait...</p>
          </div>
        </div>
      )}

      {/* Activation Results Modal */}
      {showModal && (
        <PopUpModal title="Feature Activation Results" disabled={false} onClose={() => { handleCloseActiveFeatureModal() }}
          isLoading={false} width="w-full max-w-5xl" height="max-h-[90vh]" cancelButtonText="Close" >
          {activationResults && (
            <div className="space-y-6">
              {/* Summary */}
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
                <div className="bg-blue-50 p-4 rounded-lg text-center">
                  <div className="text-2xl font-bold text-blue-600">{activationResults.summary.total}</div>
                  <div className="text-sm text-blue-600">Total</div>
                </div>
                <div className="bg-green-50 p-4 rounded-lg text-center">
                  <div className="text-2xl font-bold text-green-600">{activationResults.summary.successful}</div>
                  <div className="text-sm text-green-600">Activated</div>
                </div>
                <div className="bg-orange-50 p-4 rounded-lg text-center">
                  <div className="text-2xl font-bold text-orange-600">{activationResults.summary.skipped}</div>
                  <div className="text-sm text-orange-600">Skipped</div>
                </div>
                <div className="bg-red-50 p-4 rounded-lg text-center">
                  <div className="text-2xl font-bold text-red-600">{activationResults.summary.failed}</div>
                  <div className="text-sm text-red-600">Failed</div>
                </div>
              </div>

              {/* Plan Info */}
              <div className="bg-gray-50 p-4 rounded-lg">
                <div className="text-sm text-gray-600">
                  Current Plan: <span className="font-semibold text-gray-900">{activationResults.summary.current_plan}</span>
                </div>
                <div className="text-sm text-gray-600 mt-1">{activationResults.message}</div>
              </div>

              {/* Results List */}
              <div className="space-y-3">
                <h3 className="text-lg font-semibold text-gray-900 mb-4">Feature Details</h3>
                {Object.entries(activationResults.results).map(([featureKey, result]) => {
                  const getStatusIcon = (status) => {
                    switch (status) {
                      case 'activated':
                        return <CheckCircle size={20} className="text-green-500" />;
                      case 'skipped':
                        return <AlertCircle size={20} className="text-orange-500" />;
                      case 'failed':
                        return <XCircle size={20} className="text-red-500" />;
                      default:
                        return <AlertCircle size={20} className="text-gray-500" />;
                    }
                  };

                  const getStatusColor = (status) => {
                    switch (status) {
                      case 'activated':
                        return 'border-green-200 bg-green-50';
                      case 'skipped':
                        return 'border-orange-200 bg-orange-50';
                      case 'failed':
                        return 'border-red-200 bg-red-50';
                      default:
                        return 'border-gray-200 bg-gray-50';
                    }
                  };

                  return (
                    <div key={featureKey} className={`p-3 sm:p-4 rounded-lg border ${getStatusColor(result.status)}`}>
                      <div className="flex items-start gap-2 sm:gap-3">
                        <div className="flex-shrink-0 mt-0.5">{getStatusIcon(result.status)}</div>
                        <div className="flex-1 min-w-0">
                          <div className="flex flex-wrap items-center gap-2 mb-1">
                            <div className="font-medium text-gray-900 text-sm sm:text-base">{result?.feature_name}</div>
                            <span className={`px-2 py-0.5 text-xs font-medium rounded-full flex-shrink-0 ${result.status === 'activated' ? 'bg-green-100 text-green-800' :
                                result.status === 'skipped' ? 'bg-orange-100 text-orange-800' :
                                  'bg-red-100 text-red-800'
                              }`}>
                              {result.status.charAt(0).toUpperCase() + result.status.slice(1)}
                            </span>
                          </div>
                          <span className="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-orange-100 text-orange-800">
                            {result?.feature_plan?.toLowerCase().includes('business') && <Crown size={12} className="mr-1" />}
                            {result?.feature_plan}
                          </span>
                          <div className="text-sm text-gray-600 mt-1">{result.message}</div>
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          )}
        </PopUpModal>
      )}
    </div>
  );
};

export default DashboardNotifications;