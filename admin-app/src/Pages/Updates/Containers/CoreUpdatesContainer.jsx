import React, { useEffect, useState, useRef } from 'react';
import Table from '../../../Components/Table/Table.jsx';
import Pagination from '../../../Components/Pagination/Pagination.jsx';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton.jsx';
import InfoBar from '../../../Components/InfoBar/InfoBar.jsx';
import { CoreUpdateData } from '../UpdatesData/CoreUpdateData.jsx';
import { fetchUpdates } from '../UpdateServices/UpdateServices.jsx';
import { toast } from 'react-toastify';

export const CoreUpdatesContainer = ({ searchTerm, onDataChange, onFeatureStatusChange, registerRefetch }) => {
    const [loading, setLoading] = useState(true);
    const [coreUpdates, setCoreUpdates] = useState([]);
    const [currentPage, setCurrentPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [paginationData, setPaginationData] = useState({ total: 0, page: 1, limit: 10, total_pages: 0 });
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);
    const isFetching = useRef(false);

    const fetchCoreData = async () => {
        if (isFetching.current) return;
        
        setLoading(true);
        isFetching.current = true;

        try {
            setParentEnable(null);
            setFeatureEnable(null);
            
            const data = await fetchUpdates("tailwatch_get_core_updates", currentPage, pageSize);
            setCoreUpdates(data?.core || []);
            
            if (data?.pagination) {
                setPaginationData(data.pagination);
                
                // If current page is empty after delete and not on page 1, go to previous page
                if (data?.core?.length === 0 && currentPage > 1) {
                    setCurrentPage(currentPage - 1);
                }
            }

            if (data?.code === 400) {
                setFeatureEnable(data?.feature_enable);
                setParentEnable(data?.parent_enable);
                if (onFeatureStatusChange) {
                    onFeatureStatusChange(data?.feature_enable, data?.parent_enable);
                }
            }

            if (onDataChange) {
                onDataChange(data?.core || []);
            }
        } catch (error) {
        } finally {
            setLoading(false);
            isFetching.current = false;
        }
    };

    useEffect(() => {
        fetchCoreData();
    }, [currentPage, pageSize]);

    // Client-side search filter
    const filteredData = searchTerm
        ? coreUpdates.filter(item => item.name?.toLowerCase().includes(searchTerm.toLowerCase()))
        : coreUpdates;

    const handlePageChange = (selectedPage) => {
        setCurrentPage(selectedPage + 1);
    };

    const handlePageSizeChange = (newPageSize) => {
        setPageSize(newPageSize);
        setCurrentPage(1);
    };

    const handleRefetch = async () => {
        // Just refetch data, don't reset page or pageSize
        // This maintains current page even for bulk actions
        await fetchCoreData();
    };

    // Register refetch callback with dependencies to ensure latest state
    useEffect(() => {
        if (registerRefetch) {
            registerRefetch('coreUpdates', handleRefetch);
        }
    }, [currentPage, pageSize]);

    const renderRow = (rowData) => {
        if (!rowData) return null;
        return (
            <CoreUpdateData
                rowData={rowData}
            />
        );
    };

    const getTableColumns = () => {
        return ["Name", "Current Version", "New Version", "Action"];
    };

    return (
        <>
            {loading ? (
                <LoaderSkeleton count="0" />
            ) : (
                <>
                        {(featureEnable === false && parentEnable === true) && (
            <InfoBar
              type="info"
              message={"Configure your settings first"}
            />
          )}
                    <Table 
                        columns={getTableColumns()} 
                        data={filteredData} 
                        renderRow={renderRow} 
                        noDataMessage="No Search result found" 
                    />
                    <Pagination 
                        currentPage={currentPage - 1} 
                        totalPages={paginationData.total_pages} 
                        onPageChange={handlePageChange} 
                        hasData={paginationData.total_pages > 0} 
                        showPageSizeFilter={true} 
                        pageSize={pageSize} 
                        onPageSizeChange={handlePageSizeChange} 
                        totalItems={paginationData.total} 
                        pageSizeOptions={[5, 10, 20, 50]} 
                    />
                </>
            )}
        </>
    );
};