import ActionButton from '../../../Components/Buttons/ActionButton'
import CountryListModal from './CountryListModal/CountryListModal';
import TableControlStrip from '../../../Components/TableControlStrip/TableControlStrip'
import Pagination from '../../../Components/Pagination/Pagination';
import Table from '../../../Components/Table/Table';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton';
import { useCountryList } from '../../../Components/Hooks/useIpManagement/useCountryList';
import { useNavigate } from 'react-router-dom';
import InfoBar from '../../../Components/InfoBar/InfoBar';
const CountryListContent = ({ widget = false, limit = 10 }) => {
    const navigate = useNavigate();
    const { handleDeletAll, selectedItems, handleBulkDelete, isDeleting, searchTerm, setSearchTerm, filteredData, loading, columns, renderRow, pagination, handlePageChange, blockCountries, getBlockCountriesData,
        handleOpenModal, handleCloseModal, featureEnable, parentEnable, isModalOpen, editingCountryData, handlePageSizeChange, ipManagement, checkCountryListStatus, isLicenseConnect
    } = useCountryList(limit, widget);
    const handleViewMore = () => {
        navigate('/dashboard/geo-blocking/county');
    };
    return (
        <div>
            {!widget && (
                <TableControlStrip isDisabled={loading} showCanvas={true} handleDeleteAll={handleDeletAll} featureId={ipManagement?.featureId} CheckStatus={checkCountryListStatus} disbaled={filteredData.length === 0} selectedFilesCount={selectedItems.length} handleBulkDelete={handleBulkDelete} isDeleting={isDeleting} searchTerm={searchTerm} setSearchTerm={setSearchTerm} hasEligibleFiles={filteredData.length > 0} showDeleteButton={true} showControls={true}
                    showAddButton={true} addButtonLabel="Block Countries" onAddButtonClick={handleOpenModal} onButtonDisable={loading || featureEnable === false || parentEnable === false || isLicenseConnect?.code === 402} />
            )}
            {loading ? (
                <LoaderSkeleton count="0" />
            ) : (
                <div className='space-y-4'>
                    {(featureEnable === false || parentEnable === false || isLicenseConnect === true) && (<InfoBar type="info" message={isLicenseConnect ? "License not connected. Please connect your license to use this feature." : "Please Configure your Settings First"} />)}
                    {(isLicenseConnect?.code === 402) && <InfoBar type="info" message={isLicenseConnect?.message} />}
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
                        <ActionButton defaultText="Block Countries" isDisabled={featureEnable === false || parentEnable === false || isLicenseConnect?.code === 402} onClick={handleOpenModal} className="shadow-lg hover:shadow-xl" />
                    )}
                    {widget && (<ActionButton defaultText="View More" onClick={handleViewMore} className="!ml-4" />)}
                    <Table columns={columns} data={filteredData} renderRow={renderRow} noDataMessage="No BlackList Country found" />
                    {!widget && (
                        <Pagination currentPage={pagination.page} totalPages={pagination.total_pages} onPageChange={handlePageChange} totalItems={pagination.total_items} hasData={blockCountries.length > 0} pageSizeOptions={[5, 10, 20, 30, 50, 100]} showPageSizeFilter={true} pageSize={pagination.limit} onPageSizeChange={handlePageSizeChange} />
                    )}
                </div>
            )}
            {isModalOpen && (
                <CountryListModal onClose={handleCloseModal} getBlockCountriesData={getBlockCountriesData} isEditing={!!editingCountryData} initialData={editingCountryData} />
            )}
        </div>
    );
};

export default CountryListContent;