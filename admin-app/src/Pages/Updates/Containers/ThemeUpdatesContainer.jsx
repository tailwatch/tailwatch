import React, { useEffect, useState, useRef } from 'react';
import Table from '../../../Components/Table/Table.jsx';
import Pagination from '../../../Components/Pagination/Pagination.jsx';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton.jsx';
import InfoBar from '../../../Components/InfoBar/InfoBar.jsx';
import { ThemeUpdateData } from '../UpdatesData/ThemeUpdateData.jsx';
import { fetchUpdates } from '../UpdateServices/UpdateServices.jsx';
import { toast } from 'react-toastify';

export const ThemeUpdatesContainer = ({ searchTerm, onDataChange, onFeatureStatusChange, registerRefetch }) => {
    const [loading, setLoading] = useState(true);
    const [themeUpdates, setThemeUpdates] = useState([]);
    const [currentPage, setCurrentPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [paginationData, setPaginationData] = useState({ total: 0, page: 1, limit: 10, total_pages: 0 });
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);
    const isFetching = useRef(false);

    const fetchThemeData = async () => {
        if (isFetching.current) return;
        
        setLoading(true);
        isFetching.current = true;

        try {
            setParentEnable(null);
            setFeatureEnable(null);
            
            const data = await fetchUpdates("wptw_get_all_installed_themes", currentPage, pageSize);
            setThemeUpdates(data?.themes || []);
            
            if (data?.pagination) {
                setPaginationData(data.pagination);
            }

            if (data?.code === 400) {
                setFeatureEnable(data?.feature_enable);
                setParentEnable(data?.parent_enable);
                if (onFeatureStatusChange) {
                    onFeatureStatusChange(data?.feature_enable, data?.parent_enable);
                }
            }

            if (onDataChange) {
                onDataChange(data?.themes || []);
            }
        } catch (error) {
        } finally {
            setLoading(false);
            isFetching.current = false;
        }
    };

    useEffect(() => {
        fetchThemeData();
    }, [currentPage, pageSize]);

    // Client-side search filter
    const filteredData = searchTerm
        ? themeUpdates.filter(item => item.name?.toLowerCase().includes(searchTerm.toLowerCase()))
        : themeUpdates;

    const handlePageChange = (selectedPage) => {
        setCurrentPage(selectedPage + 1);
    };

    const handlePageSizeChange = (newPageSize) => {
        setPageSize(newPageSize);
        setCurrentPage(1);
    };

    const handleRefetch = async () => {
        await fetchThemeData();
    };

    // Register refetch callback
    useEffect(() => {
        if (registerRefetch) {
            registerRefetch('theme', handleRefetch);
        }
    }, []);

    const renderRow = (rowData) => {
        if (!rowData) return null;
        return (
            <ThemeUpdateData
                rowData={rowData}
                onRefetch={handleRefetch}
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
                    <Table columns={getTableColumns()} data={filteredData} renderRow={renderRow} noDataMessage="No Search result found" />
                    <Pagination currentPage={currentPage - 1} totalPages={paginationData.total_pages} onPageChange={handlePageChange} hasData={paginationData.total_pages > 0} showPageSizeFilter={true} 
                        pageSize={pageSize} onPageSizeChange={handlePageSizeChange} totalItems={paginationData.total} pageSizeOptions={[5, 10, 20, 50]} 
                    />
                </>
            )}
        </>
    );
};
