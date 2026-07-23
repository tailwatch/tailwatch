import { Loader2, CheckCircle, X } from 'lucide-react';
import ResetData from './ResetData/ResetData.jsx';
import { useGeneralSettings } from '../../../Components/Hooks/useGeneralSettings/useGeneralSettings.jsx';
import { GeneraSkeleton } from '../../../Components/Skeleton/LoaderSkeleton.jsx';
import { useEffect } from 'react';

const GeneralTab = ({ setIsRunning, isRunning, activeTab, setIsInitializing }) => {
  const { handleResetAllSettings, loading, isReset, resetProgress, isInitializing, closeCompletionModal, isResetComplete } = useGeneralSettings({ setIsRunning, activeTab });

  useEffect(() => {
    if (!isInitializing && setIsInitializing) {
      setIsInitializing(false);
    }
  }, [isInitializing, setIsInitializing]);

  const isProcessing = loading;
  const isCompleted = isResetComplete === 'completed';
  const showSkeleton = isInitializing || isRunning;

  return (
    <>
      <div className="flex flex-col gap-8 p-2">        

        {showSkeleton ? (
          <GeneraSkeleton />
        ) : (
          <div className="grid gap-6 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
            <ResetData handleResetAllSettings={handleResetAllSettings} loading={loading} isReset={isReset} />
          </div>
        )}
      </div>

      {/* Processing Modal */}
      {isProcessing && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg p-8 shadow-xl max-w-sm w-full mx-4">
            <div className="flex flex-col items-center gap-4">
              <Loader2 className="w-8 h-8 animate-spin text-[#007980]" />
              <p className="text-gray-700 text-center font-medium">
                Resetting your data... This may take a few moments.
              </p>
              <div className="w-full">
                <div className="flex justify-between items-center mb-2">
                  <span className="text-sm text-gray-500">Progress</span>
                  <span className="text-sm font-medium text-[#007980]">{resetProgress}%</span>
                </div>
                <div className="w-full bg-gray-200 rounded-full h-2">
                  <div
                    className="bg-[#007980] h-2 rounded-full transition-all duration-300 ease-out"
                    style={{ width: `${resetProgress}%` }}
                  />
                </div>
                {resetProgress > 0 && (
                  <p className="text-xs text-gray-500 mt-2 text-center">
                    Please wait while we process your request...
                  </p>
                )}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Completion Modal */}
      {isCompleted && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-xl shadow-2xl max-w-md w-full overflow-hidden">
            <div className="p-6">
              <div className="flex justify-between items-start">
                <div className="flex items-center gap-3">
                  <CheckCircle className="w-8 h-8 text-green-500" />
                  <div>
                    <h3 className="text-xl font-bold text-gray-800">Reset Completed!</h3>
                    <p className="text-gray-600 text-sm">Your data has been reset successfully.</p>
                  </div>
                </div>
                <button
                  onClick={closeCompletionModal}
                  className="text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg p-2 transition-all duration-200"
                >
                  <X className="w-5 h-5" />
                </button>
              </div>
            </div>
          </div>
        </div>
      )}
    </>
  );
};

export default GeneralTab;
