import React, { useState } from 'react';
import { useParams } from 'react-router-dom';
import { UpdateDetailLoader } from '../../../../Components/Skeleton/LoaderSkeleton';
import RollbackModal from '../../RollbackModal/RollbackModal';
import UpdateCompatibilityModal from '../../UpdateCompatibilityModal/UpdateCompatibilityModal';
import { getAllVersion } from '../../UpdateServices/UpdateServices';
import { ArrowUpCircle, InfoIcon, CheckCircle2, AlertTriangle, RotateCcw, Clock, Package } from 'lucide-react';

const UpdateStats = ({ pluginDetails, updateStatus, rollbackStatus, setError, handleUpdateClick, handleRollbackClick, setShouldRefetch, loading, detailLoading }) => {
  const { pluginSlug } = useParams();
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [isUpdateModalOpen, setIsUpdateModalOpen] = useState(false);
  const [allVersions, setAllVersions] = useState([]);
  const [versionLoading, setVersionLoading] = useState(false);
  const slugParts = pluginSlug.split('&');
  const slugPlugin = slugParts[1];
  const fetchPluginDetail = slugParts[0];
  const type = slugParts[slugParts.length - 1];
  const handleRollback = (version) => {
    setIsModalOpen(false);
    handleRollbackClick(version);
    setShouldRefetch(true);
  };
  const handleCancel = () => {
    setIsModalOpen(false);
  };  
  const fetchAllVersions = async () => {
    setVersionLoading(true);
    setAllVersions([]);
    let updateInfo;
    if (pluginSlug === 'wordpress&coreUpdates') {
      updateInfo = { type: 'core' };
    } else if (pluginSlug?.includes('theme')) {
      updateInfo = { slug: pluginSlug, type: 'theme' };
    } else {
      updateInfo = { slug: slugPlugin, type: 'plugin' };
    }
      await getAllVersion(updateInfo, setAllVersions, setError, setVersionLoading);
    setVersionLoading(false);
  };  
  const handleRollbackButtonClick = () => {
    setIsModalOpen(true);
    fetchAllVersions();
  };

  const handleUpdateButtonClick = () => {
    setIsUpdateModalOpen(true);
  };

  const handleUpdateFromModal = () => {
    setIsUpdateModalOpen(false);
    handleUpdateClick();
  };

  const handleUpdateCancel = () => {
    setIsUpdateModalOpen(false);
  };
  const renderDetailItem = (label, value, icon) => {
    const displayValue = loading || !value ? "N/A" : value;

    return (
      <div className="flex justify-between items-center py-2 sm:py-3 border-b border-gray-100 gap-2">
        <div className="flex items-center text-gray-700 min-w-0">
          {icon && <div className="mr-1.5 sm:mr-2 text-gray-400 flex-shrink-0">{icon}</div>}
          <span className="text-xs sm:text-sm truncate">{label}</span>
        </div>
        <span className="text-xs sm:text-sm font-medium text-gray-900 text-right flex-shrink-0">{displayValue}</span>
      </div>
    );
  };

  if (updateStatus === 'completed' || rollbackStatus === 'rollback completed') {
    if (detailLoading) {
      return <UpdateDetailLoader />;
    }
  } else if (loading) {
    return <UpdateDetailLoader />;
  }
  else if (pluginSlug === 'wordpress&undefined&coreUpdates') {
    if (detailLoading) {
      return <UpdateDetailLoader />;
    }
  }
  else if (pluginSlug?.includes('theme')) {
    if (detailLoading) {
      return <UpdateDetailLoader />;
    }
  }

  return (
    <div className="bg-white rounded-lg shadow-sm border border-gray-300 p-3 sm:p-4 lg:p-6">
      <div className="border-b border-gray-200 mb-3 sm:mb-4 pb-2">
        <h2 className="text-base sm:text-lg lg:text-xl font-semibold text-gray-800">Details</h2>
      </div>
      
      <div>
        {renderDetailItem('Current Version', pluginDetails?.current_version, 
          <Package className="w-4 h-4 text-[#007980]" />)}
          
        {renderDetailItem(
          'Latest Version',
          <>            
            {pluginDetails?.update_available && (
              <span className="mr-2 text-xs text-white bg-[#007980] rounded-full px-2 py-1 inline-flex items-center">
                <ArrowUpCircle className="w-3 h-3 mr-1" />
                Update
              </span>
            )}
            {pluginDetails?.update_version}
          </>,
          <ArrowUpCircle className="w-4 h-4 text-[#007980]" />
        )}
        
        {pluginSlug !== 'wordpress&coreUpdates' && (
          <>
            {renderDetailItem('Last Updated', pluginDetails?.last_updated, 
              <Clock className="w-4 h-4 text-[#007980]" />)}
              
            {renderDetailItem('Active Installations', pluginDetails?.active_installations, 
              <CheckCircle2 className="w-4 h-4 text-[#007980]" />)}
              
            {renderDetailItem('WordPress Version', pluginDetails?.requires_wp, 
              <InfoIcon className="w-4 h-4 text-[#007980]" />)}
              
            {renderDetailItem('Tested Up To', pluginDetails?.tested_up_to, 
              <AlertTriangle className="w-4 h-4 text-[#007980]" />)}
              
            {renderDetailItem('Requires PHP', pluginDetails?.requires_php, 
              <InfoIcon className="w-4 h-4 text-[#007980]" />)}
          </>
        )}
      </div>
      
      <div className="mt-4 sm:mt-6 flex flex-col sm:flex-row gap-2 sm:gap-3">
        <button
          className={`px-3 sm:px-4 py-2 rounded-lg text-white flex items-center justify-center flex-1 transition-all text-xs sm:text-sm ${
            updateStatus === 'updating...' || rollbackStatus === 'Rolling back...'
              ? 'bg-gray-300 cursor-not-allowed'
              : pluginDetails?.update_available
                ? 'bg-[#007980] hover:bg-teal-700 shadow-sm'
                : 'bg-gray-300'
          }`}
          onClick={handleUpdateButtonClick}
          disabled={updateStatus === 'updating...' || !pluginDetails?.update_available || rollbackStatus === 'Rolling back...'}
        >
          {updateStatus === 'updating...' ? (
            <>
              <div className="w-3.5 h-3.5 sm:w-4 sm:h-4 mr-1.5 sm:mr-2 animate-spin rounded-full border-2 border-solid border-white border-t-transparent"></div>
              Updating...
            </>
          ) : pluginDetails?.update_available ? (
            <>
              <ArrowUpCircle className="w-3.5 h-3.5 sm:w-4 sm:h-4 mr-1.5 sm:mr-2" />
              Update
            </>
          ) : (
            <>
              <CheckCircle2 className="w-3.5 h-3.5 sm:w-4 sm:h-4 mr-1.5 sm:mr-2" />
              Up to Date
            </>
          )}
        </button>

        <button
          className={`px-3 sm:px-4 py-2 rounded-lg text-white flex items-center justify-center flex-1 transition-all text-xs sm:text-sm ${
            rollbackStatus === 'Rolling back...' || updateStatus === 'updating...'
              ? 'bg-gray-300 cursor-not-allowed'
              : 'bg-[#ec5023] hover:bg-orange-700 shadow-sm'
          }`}
          onClick={handleRollbackButtonClick}
          disabled={rollbackStatus === 'Rolling back...' || updateStatus === 'updating...'}
        >
          {rollbackStatus === 'Rolling back...' ? (
            <>
              <div className="w-3.5 h-3.5 sm:w-4 sm:h-4 mr-1.5 sm:mr-2 animate-spin rounded-full border-2 border-solid border-white border-t-transparent"></div>
              Rolling back...
            </>
          ) : (
            <>
              <RotateCcw className="w-3.5 h-3.5 sm:w-4 sm:h-4 mr-1.5 sm:mr-2" />
              Rollback
            </>
          )}
        </button>

        {isModalOpen && (
          <RollbackModal
            allVersions={allVersions}
            onRollback={handleRollback}
            onCancel={handleCancel}
            currentVersion={pluginDetails?.current_version}
            loading={versionLoading}
            type={type === 'coreUpdates' ? 'core' : type}
            slug={fetchPluginDetail}
          />
        )}

        {isUpdateModalOpen && (
          <UpdateCompatibilityModal
            currentVersion={pluginDetails?.current_version}
            updateVersion={pluginDetails?.update_version}
            onUpdate={handleUpdateFromModal}
            onCancel={handleUpdateCancel}
            type={type === 'coreUpdates' ? 'core' : type}
            slug={fetchPluginDetail}
          />
        )}
      </div>
    </div>
  );
};

export default UpdateStats;
