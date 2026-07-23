import React, { useEffect, useState, useRef } from 'react';
import Table from '../../../Components/Table/Table.jsx';
import Pagination from '../../../Components/Pagination/Pagination.jsx';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton.jsx';
import InfoBar from '../../../Components/InfoBar/InfoBar.jsx';
import { PluginUpdateData } from '../UpdatesData/PluginUpdateData.jsx';
import { fetchUpdates } from '../UpdateServices/UpdateServices.jsx';
import { toast } from 'react-toastify';

export const PluginUpdatesContainer = ({ searchTerm, selectedPlugins, handleSelectPlugin, handleSelectAll, onDataChange, onFeatureStatusChange, registerRefetch }) => {
    const [loading, setLoading] = useState(true);
    const [pluginUpdates, setPluginUpdates] = useState([]);
    const [currentPage, setCurrentPage] = useState(1);
    const [pageSize, setPageSize] = useState(10);
    const [paginationData, setPaginationData] = useState({ total: 0, page: 1, limit: 10, total_pages: 0 });
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);
    const isFetching = useRef(false);

    const fetchPluginData = async () => {
        if (isFetching.current) return;

        setLoading(true);
        isFetching.current = true;

        try {
            setParentEnable(null);
            setFeatureEnable(null);

            const data = await fetchUpdates("wptw_get_all_installed_plugins", currentPage, pageSize);
            setPluginUpdates(data?.plugins || []);
            if (data?.pagination) {
                setPaginationData(data.pagination);

                // If current page is empty after delete and not on page 1, go to previous page
                if (data?.plugins?.length === 0 && currentPage > 1) {
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
                onDataChange(data?.plugins || []);
            }
        } catch (error) {
        } finally {
            setLoading(false);
            isFetching.current = false;
        }
    };

    useEffect(() => {
        fetchPluginData();
    }, [currentPage, pageSize]);

    // Client-side search filter
    const filteredData = searchTerm
        ? pluginUpdates.filter(item => item.name?.toLowerCase().includes(searchTerm.toLowerCase()))
        : pluginUpdates;

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
        await fetchPluginData();
    };

    // Register refetch callback with dependencies to ensure latest state
    useEffect(() => {
        if (registerRefetch) {
            registerRefetch('plugin', handleRefetch);
        }
    }, [currentPage, pageSize]);

    const renderRow = (rowData) => {
        if (!rowData) return null;
        const isSelected = rowData.plugin && selectedPlugins.includes(rowData.plugin);
        return (
            <PluginUpdateData
                rowData={rowData}
                onRefetch={handleRefetch}
                isSelected={isSelected}
                onSelectChange={handleSelectPlugin}
            />
        );
    };

    const handleSelectAllClick = () => {
        const allPluginFiles = filteredData.map(p => p.plugin).filter(Boolean);
        if (handleSelectAll) {
            handleSelectAll(allPluginFiles);
        }
    };

    const getTableColumns = () => {
        return [
            <input
                key="select-all"
                type="checkbox"
                onChange={handleSelectAllClick}
                checked={filteredData.length > 0 && filteredData.every(p => selectedPlugins.includes(p.plugin))}
                className="w-4 h-4 text-[#007980] bg-gray-100 border-gray-300 rounded focus:ring-[#007980] focus:ring-2 cursor-pointer"
            />,
            "Name",
            "Current Version",
            "New Version",
            "Action"
        ];
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
                    <Pagination currentPage={currentPage - 1} totalPages={paginationData.total_pages} onPageChange={handlePageChange} hasData={paginationData.total_pages > 0} showPageSizeFilter={true} pageSize={pageSize} onPageSizeChange={handlePageSizeChange} totalItems={paginationData.total} pageSizeOptions={[5, 10, 20, 50]} />
                </>
            )}
        </>
    );
};