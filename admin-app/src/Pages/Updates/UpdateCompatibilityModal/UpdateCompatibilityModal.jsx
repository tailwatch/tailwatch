import React, { useState, useEffect } from 'react';
import PopUpModal from '../../../Components/Modal/PopUpModal';
import { Loader2, AlertTriangle, XCircle, CheckCircle, Info, ShieldAlert } from 'lucide-react';
import { checkPluginCompatibility, checkThemeCompatibility, checkCoreCompatibility } from '../UpdateServices/UpdateServices';

const UpdateCompatibilityModal = ({ currentVersion, updateVersion, onUpdate, onCancel, type, slug }) => {
  const [compatibilityLoading, setCompatibilityLoading] = useState(true);
  const [compatibilityData, setCompatibilityData] = useState(null);
  const [compatibilityError, setCompatibilityError] = useState(null);

  useEffect(() => {
    checkCompatibility();
  }, []);

  const checkCompatibility = async () => {
    setCompatibilityLoading(true);
    setCompatibilityError(null);
    setCompatibilityData(null);

    try {
      let response;
      if (type === 'plugin') {
        response = await checkPluginCompatibility(updateVersion, slug);
      } else if (type === 'theme') {
        response = await checkThemeCompatibility(updateVersion, slug);
      } else {
        response = await checkCoreCompatibility(updateVersion);
      }

      setCompatibilityData(response?.data?.data?.compatibility || null);
    } catch (error) {
      console.error('Error checking compatibility:', error);
      setCompatibilityError(error.message || 'Failed to check compatibility');
    } finally {
      setCompatibilityLoading(false);
    }
  };

  const handleUpdate = () => {
    onUpdate();
  };

  const renderContent = () => (
    <div className="space-y-4">
      {compatibilityLoading ? (
        <div className="flex flex-col items-center justify-center py-8">
          <Loader2 className="w-8 h-8 text-[#007980] animate-spin mb-3" />
          <p className="text-sm text-gray-600">Checking compatibility...</p>
        </div>
      ) : compatibilityError ? (
        <div className="bg-red-50 border border-red-200 rounded-lg p-4 text-center">
          <XCircle className="w-8 h-8 text-red-500 mx-auto mb-2" />
          <p className="text-red-600">{compatibilityError}</p>
        </div>
      ) : compatibilityData ? (
        <div className="space-y-4">
          {/* Compatibility Status */}
          <div className={`rounded-lg p-4 border ${compatibilityData.is_compatible
            ? 'bg-green-50 border-green-200'
            : 'bg-red-50 border-red-200'
            }`}>
            <div className="flex items-center gap-3">
              {compatibilityData.is_compatible ? (
                <CheckCircle className="w-6 h-6 text-green-500 flex-shrink-0" />
              ) : (
                <XCircle className="w-6 h-6 text-red-500 flex-shrink-0" />
              )}
              <div>
                <p className={`font-medium ${compatibilityData.is_compatible ? 'text-green-700' : 'text-red-700'}`}>
                  {compatibilityData.is_compatible ? 'Compatible' : 'Not Compatible'}
                </p>
                <p className="text-sm text-gray-600">
                  {compatibilityData.action === 'upgrade' ? 'Upgrade' : 'Downgrade'} from v{compatibilityData.current_version} to v{compatibilityData.target_version}
                </p>
              </div>
            </div>
          </div>

          {/* Action Notice */}
          {(() => {
            const action = compatibilityData.action === 'downgrade' ? 'downgrade' : 'upgrade';
            const target = type === 'core' ? 'WordPress core' : type;
            const fromV = compatibilityData.current_version || currentVersion;
            const toV = compatibilityData.target_version || updateVersion;
            return (
              <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
                <div className="flex items-start gap-3">
                  <AlertTriangle className="w-5 h-5 text-yellow-600 flex-shrink-0 mt-0.5" />
                  <div className="space-y-1">
                    <p className="text-sm text-yellow-800">
                      You are about to {action} your current {target} version from <span className="font-semibold">v{fromV}</span> to <span className="font-semibold">v{toV}</span>.
                    </p>
                    <p className="text-sm text-yellow-700">
                      We recommend creating a full backup first. {action === 'downgrade' ? 'Downgrading' : 'Upgrading'} may cause compatibility issues with current {target} features.
                    </p>
                  </div>
                </div>
              </div>
            );
          })()}

          {/* Blocking Issues */}
          {compatibilityData.blocking_issues?.length > 0 && (
            <div className="bg-red-50 border border-red-200 rounded-lg p-4">
              <div className="flex items-center gap-2 mb-3">
                <ShieldAlert className="w-5 h-5 text-red-500" />
                <h4 className="font-medium text-red-700">Blocking Issues</h4>
              </div>
                 <ul className="space-y-2 bg-white rounded p-3 border border-blue-100 list-disc pl-9">
                {compatibilityData.blocking_issues.map((issue, index) => (
                  <li key={index} className="bg-white  text-sm text-red-700 font-normal">
                    {issue.message}
              
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Warnings */}
          {compatibilityData.warnings?.length > 0 && (
            <div className="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
              <div className="flex items-center gap-2 mb-3">
                <AlertTriangle className="w-5 h-5 text-yellow-600" />
                <h4 className="font-medium text-yellow-700">Warnings</h4>
              </div>
                 <ul className="space-y-2 bg-white rounded p-3 border border-blue-100 list-disc pl-9">
                {compatibilityData.warnings.map((warning, index) => (
                  <li key={index} className="bg-white  text-sm text-yellow-700 font-normal">
                   {warning.message}
                  
                  </li>
                ))}
              </ul>
            </div>
          )}

         

          {/* Affected Dependencies */}
          {compatibilityData.affected_dependencies?.length > 0 && (
            <div className="bg-gray-50 border border-gray-200 rounded-lg p-4">
              <div className="flex items-center gap-2 mb-3">
                <Info className="w-5 h-5 text-gray-500" />
                <h4 className="font-medium text-gray-700">Affected Dependencies</h4>
              </div>
              <ul className="space-y-2 bg-white rounded p-3 border border-blue-100 list-disc pl-9">
                {compatibilityData.affected_dependencies.map((dep, index) => (
                  <li key={index} className="bg-white  text-sm font-normal">
                    
                  {dep.issue}
                  </li>
                ))}
              </ul>
            </div>
          )}


        </div>
      ) : null}
    </div>
  );

  return (
    <PopUpModal
      title="Update Compatibility Check"
      onClose={onCancel}
      onSave={handleUpdate}
      disabled={compatibilityLoading || compatibilityError || !compatibilityData?.is_compatible}
      saveButtonText="Update"
      cancelButtonText="Cancel"
      width="w-full md:max-w-2xl"
      isLoading={compatibilityLoading}
      showExpandIcon={true}
    >
      {renderContent()}
    </PopUpModal>
  );
};

export default UpdateCompatibilityModal;
