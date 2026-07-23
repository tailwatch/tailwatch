import { useEffect } from 'react';
import Table from '../../../Components/Table/Table';
import TableControlStrip from '../../../Components/TableControlStrip/TableControlStrip';
import ActionButton from '../../../Components/Buttons/ActionButton';
import IpManagementModal from '../IpManagementModal/IpManagementModal';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton';
import Pagination from '../../../Components/Pagination/Pagination';
import { useBlackList } from '../../../Components/Hooks/useIpManagement/useBlackList';
import { useNavigate } from 'react-router-dom';
import InfoBar from '../../../Components/InfoBar/InfoBar';

const BlackListContent = ({inActive, refreshTrigger, widget = false, limit = 10, isGeoLicense }) => {    
    const navigate = useNavigate();
    const { selectedItems, handleDeletAll, handleBulkDelete, featureEnable, parentEnable, isDeleting, searchTerm, setSearchTerm, filteredData, handleAddToBlacklist, loading, columns, renderRow, pagination, handlePageChange, blackListData, showModal, handleCloseModal, getBlackListIpRanges, editingIpData, handlePageSizeChange, ipManagement, checkBlackListStatus, isLicenseConnect } = useBlackList(limit, widget);
    
    const handleViewMore = () => {
        navigate('/dashboard/geo-blocking/black-list');
    };    
    
    useEffect(() => {
        if (refreshTrigger > 0) {
            checkBlackListStatus();
        }
    }, [refreshTrigger]);

    return (
        <div>
            {!widget && (
                <TableControlStrip showCanvas={true} disbaled={filteredData.length === 0} featureId={ipManagement?.featureId} isDisabled={loading} CheckStatus={checkBlackListStatus} handleDeleteAll={handleDeletAll} selectedFilesCount={selectedItems.length} handleBulkDelete={handleBulkDelete} isDeleting={isDeleting} searchTerm={searchTerm} setSearchTerm={setSearchTerm} hasEligibleFiles={filteredData.length > 0} showDeleteButton={true} showControls={true}
                    showAddButton={true} addButtonLabel="Add to BlackList" onAddButtonClick={handleAddToBlacklist} onButtonDisable={loading || featureEnable === false || inActive || !isGeoLicense?.is_connected || parentEnable === false} />
            )}
            {loading ? (
                <LoaderSkeleton count="0" />
            ) : (
                <div className='space-y-4'>
                    {/* Existing Info Bar */}
                    {(featureEnable === false || parentEnable === false || inActive) && (
                        <InfoBar type="info" message={isLicenseConnect ? "License not connected. Please connect your license to use this feature." : "Please Configure your Settings First"} />
                    )}
                    
                    {/* Geo License Info Bars */}
                    {featureEnable === false || parentEnable === false || isGeoLicense?.file_exists === false && (
                        <InfoBar 
                            type="warning" 
                            message={
                                <span>
                                    You have not connected the MaxMind license key. Please integrate it first.{' '}
                                    <a href="/dashboard/settings/integration" className="underline font-semibold hover:text-blue-700" onClick={(e) => { e.preventDefault(); navigate('/dashboard/settings/integration'); }} >
                                        Go to Integration
                                    </a>
                                </span>
                            } 
                        />
                    )}
                    
                    {featureEnable === true || parentEnable === true || isGeoLicense?.file_exists === true && isGeoLicense?.is_connected === false && (
                        <InfoBar type="info" message="Please update your Maxmind license key to get new updates." />
                    )}
                    
                    {widget && (
                        <ActionButton defaultText="Add to BlackList" isDisabled={featureEnable === false || inActive || isGeoLicense?.is_connected === false || parentEnable === false} onClick={handleAddToBlacklist} />
                    )}
                    {widget && ( <ActionButton defaultText="View More" onClick={handleViewMore} className="!ml-4" /> )}
                    <Table columns={columns} data={filteredData} renderRow={renderRow} noDataMessage="No blacklisted IP ranges found" />
                    {!widget && (
                        <Pagination currentPage={pagination.page} totalPages={pagination.total_pages} onPageChange={handlePageChange} hasData={blackListData.length > 0} pageSizeOptions={[5, 10, 20, 30, 50, 100]} totalItems={pagination.total_items} showPageSizeFilter={true} pageSize={pagination.limit} onPageSizeChange={handlePageSizeChange}  />
                    )}
                </div>
            )}
            {showModal && (
                <IpManagementModal onClose={handleCloseModal} getData={() => getBlackListIpRanges(pagination.page, pagination.limit)} isEditing={!!editingIpData} initialData={editingIpData} />
            )}
        </div>
    );
};

export default BlackListContent;