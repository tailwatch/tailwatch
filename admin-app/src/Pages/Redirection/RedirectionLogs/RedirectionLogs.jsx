import React, { useEffect, useState } from 'react';
import { getRedirectionLogs, handleDeleteLogs } from '../RedirectionServices/RedirectionServices';
import Table from '../../../Components/Table/Table';
import TableControlStrip from '../../../Components/TableControlStrip/TableControlStrip';
import { renderLogsRow } from '../RedirectionFormContent/RedirectionRules';
import Pagination from '../../../Components/Pagination/Pagination';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton';
import { alertService } from '../../../Components/AlertService/AlertService';
import { CheckboxField } from '../../../Components/Fields/CheckboxField';
import InfoBar from '../../../Components/InfoBar/InfoBar';

const RedirectionLogs = ({setFeatureEnable, featureEnable,enableRedirectionRule,checRedirectionStatus,parentEnable, setParentEnable}) => {
    const [redirectionLogs, setRedirectionLogs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedLogs, setSelectedLogs] = useState({});
    const [allSelected, setAllSelected] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [pagination, setPagination] = useState({ page: 0, limit: 10, total_pages: 1,total_items: 0 });

    const fetchRedirectionLogs = async (page = pagination.page, limit = pagination.limit) => {
        setLoading(true);
        await getRedirectionLogs({
            setRedirectLogs: setRedirectionLogs, setLoading, page: page + 1, limit,setFeatureEnable, setParentEnable,
            setPagination: (paginationData) => {
                setPagination({ page: paginationData.page - 1, limit: paginationData.limit, total_pages: paginationData.total_pages,total_items: paginationData.total_items || 0  });
            }
        });
        setLoading(false);
    };

    useEffect(() => {
        fetchRedirectionLogs();
    }, []);

    const parseLogValue = (valueString) => {
        try {
            return JSON.parse(valueString);
        } catch (error) {
            console.error('Error parsing log value:', error);
            return {};
        }
    };

    const processedLogs = redirectionLogs ? redirectionLogs.map(log => {
        const valueData = parseLogValue(log.value);
        return { id: log.id, sourceUrl: valueData.source_url || 'N/A', targetUrl: valueData.target_url || 'N/A', referrer: valueData.referrer || 'N/A', match_type: valueData.match_type || 'N/A', client_ip: valueData.client_ip || 'N/A', statusCode: valueData.status_code || 'N/A', timestamp: valueData.timestamp || log.date_created || 'N/A', selected: selectedLogs[log.id] || false };
    }) : [];

    const filteredLogs = processedLogs.filter(log => {
        const searchLower = searchTerm.toLowerCase();
        return (
            log.sourceUrl.toLowerCase().includes(searchLower) ||
            log.targetUrl.toLowerCase().includes(searchLower) ||
            log.timestamp.toLowerCase().includes(searchLower) ||
            String(log.statusCode).includes(searchLower)
        );
    });

    const handleSelectLog = (logId) => {
        setSelectedLogs(prev => ({
            ...prev,
            [logId]: !prev[logId]
        }));
        if (!selectedLogs[logId]) {
            const allOthersSelected = filteredLogs.every(log =>
                log.id === logId || selectedLogs[log.id]
            );
            setAllSelected(allOthersSelected);
        } else {
            setAllSelected(false);
        }
    };

    const handleSelectAll = () => {
        const newSelectedState = !allSelected;
        setAllSelected(newSelectedState);

        const newSelectedLogs = { ...selectedLogs };
        filteredLogs.forEach(log => {
            newSelectedLogs[log.id] = newSelectedState;
        });

        setSelectedLogs(newSelectedLogs);
    };

    const selectedLogsCount = Object.values(selectedLogs).filter(Boolean).length;

    const handleDeletAll = async () => {
        const confirmed = await alertService.confirm("This can't be undone. it removes all data from database", "Are you sure to delete all this data?");
        if (!confirmed) {
            return;
        }
        setIsDeleting(true);
        try {
            const deleteData = { ids: [], is_delete: true };
            await handleDeleteLogs(deleteData, setIsDeleting, fetchRedirectionLogs);
            setSelectedLogs({});
            setAllSelected(false);
        } catch (error) {
            console.error('Error deleting items:', error);
        } finally {
            setIsDeleting(false);
        }
    }

    const handleBulkDelete = async () => {
        const confirmed = await alertService.confirm("This can't be undone. ", "Are you sure to delete ?");
        if (!confirmed) {
            return;
        }
        const selectedIds = Object.keys(selectedLogs).filter(id => selectedLogs[id]).map(id => parseInt(id));
        const deleteData = { ids: selectedIds, is_delete: false };
        await handleDeleteLogs(deleteData, setIsDeleting, fetchRedirectionLogs);
        setSelectedLogs({});
        setAllSelected(false);
    };

    const handlePageChange = (newPage) => {
        setPagination(prev => ({ ...prev, page: newPage }));
        fetchRedirectionLogs(newPage, pagination.limit);
        // Clear selections when changing pages
        setSelectedLogs([]);
        setAllSelected(false);
    };

     const handlePageSizeChange = (newPageSize) => {
        setPagination(prev => ({ ...prev, limit: newPageSize, page: 0 }));
        fetchRedirectionLogs(0, newPageSize);        
        setSelectedLogs([]);
        setAllSelected(false);
    };
    
    const columns = [<CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={filteredLogs.length===0} className='ml-1'/>, "Redirect", "Client Ip", "Referer", "Timestamp"];

    const handlerenderlogsRow = (log) => {
        return renderLogsRow({ log, selectedLogs, handleSelectLog });
    }

    return (
        <div className="">
            {loading ? (
                <LoaderSkeleton count="0" />
            ) : (
                <>
                    { (featureEnable=== false || parentEnable === false ) && (<InfoBar type="info" message="Please Configure your settings first"/>)}
                    <TableControlStrip showCanvas={true} featureId={enableRedirectionRule?.featureId} isDisabled={parentEnable===false} CheckStatus={checRedirectionStatus} handleDeleteAll={handleDeletAll} disbaled={filteredLogs.length === 0} selectedFilesCount={selectedLogsCount} handleBulkDelete={handleBulkDelete} isDeleting={isDeleting} searchTerm={searchTerm} setSearchTerm={setSearchTerm} hasEligibleFiles={filteredLogs.length > 0} showControls={true} />
                    <Table columns={columns} data={filteredLogs} renderRow={handlerenderlogsRow} noDataMessage="No redirection logs found." />
                    <Pagination currentPage={pagination.page} totalPages={pagination.total_pages} onPageChange={handlePageChange} hasData={redirectionLogs.length > 0} pageSizeOptions={[5, 10, 20, 30, 50, 100]} totalItems={pagination.total_items} showPageSizeFilter={true} pageSize={pagination.limit} onPageSizeChange={handlePageSizeChange} />
                </>
            )}
        </div>
    );
};

export default RedirectionLogs;