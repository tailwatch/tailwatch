import React from 'react'
import Table from '../../../Components/Table/Table'
import ActionButton from '../../../Components/Buttons/ActionButton'
import TableControlStrip from '../../../Components/TableControlStrip/TableControlStrip'
import { useBruteForce } from '../../../Components/Hooks/useBruteForce/useBruteForce'
import Pagination from '../../../Components/Pagination/Pagination'
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton'
import LockCard from '../../../Components/LockCard/LockCard'
import { useNavigate } from 'react-router-dom'
import InfoBar from '../../../Components/InfoBar/InfoBar'
const IpBasedProtection = ({ widget = false, limit = 10 }) => {
    const navigate = useNavigate();
    const { searchTerm, checkBruteForceStatus, featureEnable, parentEnable, isLicenseConnect, bruteForceRule, setSearchTerm, isLoading, columns, filteredData, renderRow, pagination, handlePageChange, ipDetailsData, handleDeletAll,
        selectedItems, handleBulkDelete, handleUnblockIpAddress, handleAddToBlackList, handleAddToWhitelist, handlePageSizeChange
    } = useBruteForce(limit, widget);
    const handleViewMore = () => {
        navigate('/dashboard/login-defender/ip-based');
    };    

    return (
        <div>
            {(parentEnable === false || isLicenseConnect === true) && (
                <LockCard
                    featureName="Login Defender"
                    featureDescription="Protect your site from brute-force attacks with customizable rules based on usernames, IP addresses, and login behavior. Configure limits, block durations, and lockout scopes while maintaining full control over every login attempt."
                    featureId={bruteForceRule?.featureId}
                    isLocked={bruteForceRule?.isLocked || isLicenseConnect === true}
                    isActive={bruteForceRule?.isActiveState}
                    afterToggleCallback={checkBruteForceStatus}
                />
            )}
            {!widget && (
                <TableControlStrip showCanvas={true} featureId={bruteForceRule?.featureId} isDisabled={isLoading} CheckStatus={checkBruteForceStatus} handleAddToBlacklist={handleAddToBlackList} disbaled={filteredData.length === 0} handleAddToWhitelist={handleAddToWhitelist} handleDelete={handleBulkDelete} handleDeleteAll={handleDeletAll} showBruteForceActions={true}
                    selectedFilesCount={selectedItems.length} searchTerm={searchTerm} setSearchTerm={setSearchTerm} hasEligibleFiles={filteredData.length > 0}
                    showDeleteButton={false} showControls={true} handleUnblock={handleUnblockIpAddress} />
            )}
            {widget && (<ActionButton defaultText="View More" onClick={handleViewMore} className="!mb-4" />)}
            {isLoading ? (
                <LoaderSkeleton count="0" />
            ) : (
                <>
                {(featureEnable === false || parentEnable === false || isLicenseConnect === true) && (<InfoBar type="info" message={isLicenseConnect ? "License not connected. Please connect your license to use this feature." : "Please Configure your Settings First"}/>)}                
                    <Table columns={columns} data={filteredData} renderRow={renderRow} noDataMessage={searchTerm ? `No IP details found matching "${searchTerm}"` : "No IP details available"} />
                    {!widget && (
                        <Pagination currentPage={pagination.page} totalPages={pagination.total_pages} onPageChange={handlePageChange} hasData={ipDetailsData.length > 0} pageSizeOptions={[5, 10, 20, 30, 50, 100]} totalItems={pagination.total_items} showPageSizeFilter={true} pageSize={pagination.limit} onPageSizeChange={handlePageSizeChange} />
                    )}
                </>
            )}
        </div>
    )
}

export default IpBasedProtection