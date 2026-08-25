import React, { useState, useEffect } from 'react';
import PopUpModal from '../../../Components/Modal/PopUpModal';
import { Spinner } from '../../../Components/Spinner/Spinner';
import { CircleCheckBig, Loader2, AlertTriangle, XCircle, CheckCircle, Info, ShieldAlert } from 'lucide-react';
import { checkPluginCompatibility, checkThemeCompatibility, checkCoreCompatibility } from '../UpdateServices/UpdateServices';
import { toast } from 'react-toastify';

const RollbackModal = ({ allVersions, currentVersion, onRollback, onCancel, loading, type, slug }) => {
  const [selectedVersion, setSelectedVersion] = useState('');
  const [step, setStep] = useState(1); // Step 1: Select version, Step 2: Compatibility check
  const [compatibilityLoading, setCompatibilityLoading] = useState(false);
  const [compatibilityData, setCompatibilityData] = useState(null);
  const [compatibilityError, setCompatibilityError] = useState(null);
  
  
  useEffect(() => {
    if (currentVersion) {
      setSelectedVersion(currentVersion);
    }
  }, [currentVersion]);

  // Reset state when modal closes
  useEffect(() => {
    return () => {
      setStep(1);
      setCompatibilityData(null);
      setCompatibilityError(null);
    };
  }, []);

const handleNext = async () => {
  if (!selectedVersion || selectedVersion === currentVersion) return;
  setCompatibilityLoading(true);
  setCompatibilityError(null);
  setCompatibilityData(null);

  try {
    let response;
    if (type === 'plugin') {
      response = await checkPluginCompatibility(selectedVersion, slug);
    } else if (type === 'theme') {
      response = await checkThemeCompatibility(selectedVersion, slug);
    } else {
      response = await checkCoreCompatibility(selectedVersion);
    }

    if (response?.data?.success === false && response?.data?.data?.code === 404) {

      setCompatibilityError(response?.data?.data?.message || 'Plugin not found in WordPress repository');
      return;
    }

    setCompatibilityData(response?.data?.data?.compatibility || null);
    setStep(2);
  } catch (error) {
    const backendMessage =
      error?.response?.data?.data?.message ||
      error?.message ||
      'Failed to check compatibility';
    setCompatibilityError(backendMessage);
  } finally {
    setCompatibilityLoading(false);
  }
};

  const handleBack = () => {
    setStep(1);
    setCompatibilityData(null);
    setCompatibilityError(null);
  };

  const handleRollback = () => {
    if (selectedVersion && selectedVersion !== currentVersion) {
      onRollback(selectedVersion);
    }
  };

  const sortedVersions = Object.keys(allVersions).sort((a, b) => {
    const versionA = a.split('.').map(Number);
    const versionB = b.split('.').map(Number);
    for (let i = 0; i < Math.max(versionA.length, versionB.length); i++) {
      if ((versionA[i] || 0) > (versionB[i] || 0)) return -1;
      if ((versionA[i] || 0) < (versionB[i] || 0)) return 1;
    }
    return 0;
  });

  const VersionList = () => (
    <div className="space-y-3">
      {sortedVersions.length > 0 ? (
        sortedVersions.map((version) => (           
          <div
            key={version}
            className={`flex items-center p-4 mr-2 border rounded-lg shadow-sm cursor-pointer transition-all duration-200 ${selectedVersion === version
              ? "border-[#85cbcf] bg-gradient-to-br from-blue-50 to-[#85cbcf] border-l-4"
              : "border-gray-300 hover:border-[#85cbcf] hover:bg-[#07C07E1A]"
              }`}
            onClick={() => setSelectedVersion(version)}
          >
            <div className="flex-1 flex items-center">
              <div className={`text-lg font-mono ${selectedVersion === version ? "text-[#007980] font-medium" : "text-gray-700"}`}>
                v{version}
              </div>

              {version === currentVersion && (
                <span className="ml-3 px-2 py-0.5 text-xs bg-[#07C07E1A] text-[#007980] border border-[#85cbcf] rounded-full">
                  Current
                </span>
              )}
            </div>

            {selectedVersion === version && (
              <CircleCheckBig className='text-[#007980]' />
            )}
          </div>
        ))
      ) : (
        <div className="text-center py-8 text-gray-600 border rounded-lg shadow-sm">
    {compatibilityError || 'No versions available'}
        </div>
      )}
    </div>
  );

  const CompatibilityCheck = () => (
    <div className="space-y-4">
      {/* Version Info */}
      {/* <div className="bg-gray-50 rounded-lg p-4 border border-gray-200">
        <div className="flex items-center justify-between mb-2">
          <span className="text-sm text-gray-600">Current Version</span>
          <span className="font-medium text-gray-700">v{currentVersion}</span>
        </div>
        <div className="flex items-center justify-between">
          <span className="text-sm text-gray-600">Target Version</span>
          <span className="font-semibold text-[#007980]">v{selectedVersion}</span>
        </div>
      </div> */}

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
                  {compatibilityData.action === 'downgrade' ? 'Downgrade' : 'Upgrade'} from v{compatibilityData.current_version} to v{compatibilityData.target_version}
                </p>
              </div>
            </div>
          </div>

          {/* Action Notice */}
          {(() => {
            const action = compatibilityData.action === 'downgrade' ? 'downgrade' : 'upgrade';
            const target = type === 'core' ? 'WordPress core' : type;
            const fromV = compatibilityData.current_version || currentVersion;
            const toV = compatibilityData.target_version || selectedVersion;
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
                  <li key={index} className="bg-white   text-sm text-red-700 font-normal">
                    {issue.message}
                  
                  </li>
                ))}
              </ul>
            </div>
          )}

          {/* Warnings */}
          {/* {compatibilityData.warnings?.length > 0 && (
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
          )} */}


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

          {/* Recommended Actions */}
          {/* {compatibilityData.recommended_actions?.length > 0 && (
            <div className="bg-blue-50 border border-blue-200 rounded-lg p-4">
              <div className="flex items-center gap-2 mb-3">
                <Info className="w-5 h-5 text-blue-500" />
                <h4 className="font-medium text-blue-700">Recommended Actions</h4>
              </div>
                 <ul className="space-y-2 bg-white rounded p-3 border border-blue-100 list-disc pl-9">
                   {compatibilityData.recommended_actions.map((action, index) => (
                     <li key={index} className="bg-white  text-sm font-normal">
                       {action.description}
                     </li>
                  ))}
                </ul>

            </div>
          )} */}

        
        </div>
      ) : null}
    </div>
  );

  const renderContent = () => {
    if (loading) {
      return (
        <div className="flex justify-center items-center py-8">
          <Spinner />
        </div>
      );
    }

    if (step === 1) {
      return <VersionList />;
    }

    return <CompatibilityCheck />;
  };

  const getModalProps = () => {
    if (step === 1) {
      return {
        title: "Select Version to Rollback",
        saveButtonText: "Next",
        onSave: handleNext,
        disabled: !selectedVersion || selectedVersion === currentVersion || compatibilityLoading,
        showBackIcon: false
      };
    }

    return {
      title: "Compatibility Check",
      saveButtonText: "Rollback",
      onSave: handleRollback,
      disabled: compatibilityLoading || compatibilityError || !compatibilityData?.is_compatible,
      showBackIcon: true,
      onBack: handleBack,
      disabledIcon: compatibilityLoading
    };
  };

  const modalProps = getModalProps();

  return (
    <PopUpModal
      title={modalProps.title}
      onClose={onCancel}
      onSave={modalProps.onSave}
      disabled={modalProps.disabled}
      saveButtonText={modalProps.saveButtonText}
      cancelButtonText="Cancel"
      width="w-full md:max-w-2xl"
      showBackIcon={modalProps.showBackIcon}
      onBack={modalProps.onBack}
      disabledIcon={modalProps.disabledIcon}
      showExpandIcon={true}
      isLoading={compatibilityLoading}
    >
      {renderContent()}
    </PopUpModal>
  );
};

export default RollbackModal;
