import React from 'react'
import TableControlStrip from '../../../../Components/TableControlStrip/TableControlStrip';
import ActionButton from '../../../../Components/Buttons/ActionButton';
import Pagination from '../../../../Components/Pagination/Pagination';
import Table from '../../../../Components/Table/Table';
import { useCountryWhitelist } from '../../../../Components/Hooks/useIpManagement/useCountryWhitelist'
import CountryListModal from '../../CountryList/CountryListModal/CountryListModal';
import LoaderSkeleton from '../../../../Components/Skeleton/LoaderSkeleton';
import InfoBar from '../../../../Components/InfoBar/InfoBar';
const WhiteListCountry = ({ widget, limit }) => {
    const { handleDeletAll, selectedItems, handleBulkDelete, isDeleting, searchTerm, setSearchTerm, filteredData, loading, columns, renderRow, pagination, handlePageChange, whitelistCountries, getWhitelistCountryData,
        handleOpenModal, handleCloseModal,featureEnable,parentEnable, isModalOpen, editingCountryData,handlePageSizeChange,ipManagement,checkWhiteCountryListStatus,isLicenseConnect
    } = useCountryWhitelist(limit,widget);
    return (
        <div>
            {!widget && (
                <TableControlStrip showCanvas={true} isDisabled={loading} handleDeleteAll={handleDeletAll} featureId={ipManagement?.featureId} CheckStatus={checkWhiteCountryListStatus} disbaled={filteredData.length===0} selectedFilesCount={selectedItems.length} handleBulkDelete={handleBulkDelete} isDeleting={isDeleting} searchTerm={searchTerm} setSearchTerm={setSearchTerm} hasEligibleFiles={filteredData.length > 0} showDeleteButton={true} showControls={true}
                    showAddButton={true} addButtonLabel="Add to Whitelist" onAddButtonClick={handleOpenModal} onButtonDisable={loading || featureEnable === false || parentEnable === false || isLicenseConnect === true || ipManagement?.isActiveState === '0'} />
            )}
            {loading ? (
                <LoaderSkeleton count="0" />
            ) : (
                <div className='space-y-4'>
                    {(featureEnable === false || parentEnable === false || isLicenseConnect === true || ipManagement?.isActiveState === '0' ) && (<InfoBar type="info" message="Please Configure your Settings First" />)}
                    {!widget && (
                        <InfoBar
                            type="info"
                            message={
                                <span>
                                    Country rules require a free GeoIP database. Download <strong>GeoLite2-Country.mmdb</strong> from{' '}
                                    <a href="https://www.maxmind.com/en/geolite2/signup" target="_blank" rel="noopener noreferrer" className="underline font-semibold hover:text-blue-700">MaxMind</a>{' '}(a free account is required), then upload it via your hosting file manager or FTP to your WordPress uploads directory, in the folder <code>tailwatch/wptw-logs/geoip/</code>. Country rules take effect automatically once the file is in place.
                                </span>
                            }
                        />
                    )}
                    {widget && (
                        <ActionButton defaultText="Add to Whitelist" isDisabled={featureEnable === false || parentEnable === false || isLicenseConnect === true || ipManagement?.isActiveState === '0' } onClick={handleOpenModal} className="shadow-lg hover:shadow-xl" />
                    )}
                    <Table columns={columns} data={filteredData} renderRow={renderRow} noDataMessage="No whitelist countries found" />
                    {!widget && (
                        <Pagination currentPage={pagination.page} totalPages={pagination.total_pages} onPageChange={handlePageChange} hasData={whitelistCountries.length > 0} pageSizeOptions={[5, 10, 20, 30, 50, 100]} totalItems={pagination.total_items} showPageSizeFilter={true} pageSize={pagination.limit} onPageSizeChange={handlePageSizeChange} />
                    )}
                </div>
            )}
            {isModalOpen && (
                <CountryListModal onClose={handleCloseModal} getBlockCountriesData={getWhitelistCountryData} isEditing={!!editingCountryData} initialData={editingCountryData} isWhiteList={true} />
            )}
        </div>
    );
};

export default WhiteListCountry