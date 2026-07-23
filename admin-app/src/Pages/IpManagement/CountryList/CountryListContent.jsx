import ActionButton from '../../../Components/Buttons/ActionButton'
import CountryListModal from './CountryListModal/CountryListModal';
import TableControlStrip from '../../../Components/TableControlStrip/TableControlStrip'
import Pagination from '../../../Components/Pagination/Pagination';
import Table from '../../../Components/Table/Table';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton';
import { useCountryList } from '../../../Components/Hooks/useIpManagement/useCountryList';
import { useNavigate } from 'react-router-dom';
import InfoBar from '../../../Components/InfoBar/InfoBar';
const CountryListContent = ({ widget = false, limit = 10, isGeoLicense }) => {
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
                    showAddButton={true} addButtonLabel="Block Countries" onAddButtonClick={handleOpenModal} onButtonDisable={loading || featureEnable === false || parentEnable === false || !isGeoLicense?.is_connected || isLicenseConnect?.code === 402} />
            )}
            {loading ? (
                <LoaderSkeleton count="0" />
            ) : (
                <div className='space-y-4'>
                    {(featureEnable === false || parentEnable === false || isLicenseConnect === true) && (<InfoBar type="info" message={isLicenseConnect ? "License not connected. Please connect your license to use this feature." : "Please Configure your Settings First"} />)}
                    {(isLicenseConnect?.code === 402) && <InfoBar type="info" message={isLicenseConnect?.message} />}
                    {isLicenseConnect?.code !== 402 && (
                        (featureEnable === false ||
                            parentEnable === false ||
                            isGeoLicense?.file_exists === false) && (
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
                        )
                    )}

                    {isLicenseConnect?.code !== 402 && featureEnable === true || parentEnable === true || isGeoLicense?.file_exists === true && isGeoLicense?.is_connected === false && (<InfoBar type="info" message="Please update your Maxmind license key to get new updates." />)}
                    {widget && (
                        <ActionButton defaultText="Block Countries" isDisabled={featureEnable === false || parentEnable === false || isGeoLicense?.is_connected === false || isLicenseConnect?.code === 402} onClick={handleOpenModal} className="shadow-lg hover:shadow-xl" />
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