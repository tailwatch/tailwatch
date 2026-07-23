import TableControlStrip from '../../../../Components/TableControlStrip/TableControlStrip';
import Table from '../../../../Components/Table/Table';
import Pagination from '../../../../Components/Pagination/Pagination';
import { useWhitelistIp } from '../../../../Components/Hooks/useIpManagement/useWhitelistIp';
import LoaderSkeleton from '../../../../Components/Skeleton/LoaderSkeleton';
import IpManagementModal from '../../IpManagementModal/IpManagementModal';
import ActionButton from '../../../../Components/Buttons/ActionButton';
import { useNavigate } from 'react-router-dom';
import InfoBar from '../../../../Components/InfoBar/InfoBar';

const WhiteListip = ({widget, limit,isGeoLicense}) => {
    const navigate = useNavigate();
    const { handleDeletAll, selectedItems,featureEnable,parentEnable, handleBulkDelete, isDeleting, searchTerm, setSearchTerm, filteredData, handleAddToBlacklist, loading, columns, renderRow, pagination, handlePageChange, ipWhiteListData, showModal, handleCloseModal, getWhiteListIpRanges, editingIpData, handlePageSizeChange,checkWhiteListIpStatus,ipManagement,isLicenseConnect } = useWhitelistIp(limit,widget);
     const handleViewMore = () => {
        navigate('/dashboard/geo-blocking/white-list');
    };
    return (
        <div>
            {!widget && (
            <TableControlStrip showCanvas={true} handleDeleteAll={handleDeletAll} isDisabled={loading} featureId={ipManagement?.featureId} CheckStatus={checkWhiteListIpStatus} disbaled={filteredData.length===0} selectedFilesCount={selectedItems.length} handleBulkDelete={handleBulkDelete} isDeleting={isDeleting} searchTerm={searchTerm} setSearchTerm={setSearchTerm} hasEligibleFiles={filteredData.length > 0} showDeleteButton={true} showControls={true}
                showAddButton={true} addButtonLabel="Add to Whitelist" onAddButtonClick={handleAddToBlacklist} onButtonDisable={loading || featureEnable === false || parentEnable === false || isLicenseConnect === true || !isGeoLicense?.is_connected || ipManagement?.isActiveState === '0'} />
            )}
            {loading ? (
                <LoaderSkeleton count="0" />
            ) : (
                <div className='space-y-4'>
                    {(featureEnable === false || parentEnable === false || isLicenseConnect === true) && (<InfoBar type="info" message={isLicenseConnect ? "License not connected. Please connect your license to use this feature." : "Please Configure your Settings First"} />)}
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
                        <ActionButton defaultText="Add to Whitelist" isDisabled={featureEnable === false || parentEnable === false || isLicenseConnect === true || isGeoLicense?.is_connected === false || ipManagement?.isActiveState === '0' } onClick={handleAddToBlacklist} />
                    )}
                    {widget && ( <ActionButton defaultText="View More" onClick={handleViewMore} className="!ml-4" /> )}
                    <Table columns={columns} data={filteredData} renderRow={renderRow} noDataMessage="No Whitelisted IP ranges found" />
                    {!widget && (
                    <Pagination currentPage={pagination.page} totalPages={pagination.total_pages} onPageChange={handlePageChange} hasData={ipWhiteListData.length > 0} pageSizeOptions={[5, 10, 20, 30, 50, 100]} totalItems={pagination.total_items} showPageSizeFilter={true} pageSize={pagination.limit} onPageSizeChange={handlePageSizeChange} />
                    )}
                </div>
            )}
            {showModal && (
                <IpManagementModal onClose={handleCloseModal} getData={() => getWhiteListIpRanges(pagination.page, pagination.limit)} isEditing={!!editingIpData} initialData={editingIpData} isWhitelist={true} />
            )}
        </div>
    );
};

export default WhiteListip