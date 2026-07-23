import React, { useEffect, useState } from 'react';
import Table from '../../../Components/Table/Table';
import TableControlStrip from '../../../Components/TableControlStrip/TableControlStrip';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton';
import { LogsData } from './LogsData';
import PopUpModal from '../../../Components/Modal/PopUpModal';
import { useRedirection } from '../../../Components/Hooks/useRedirection/useRedirection';
import { RedirectionFormContent } from '../../Redirection/RedirectionFormContent/RedirectionFormContent';
import { getBrokenLinkLogs, handleDeleteLogs } from '../LinkServices/LinkServices';
import { redirectCodes, matchTypeOptions } from '../../Redirection/RedirectionFormContent/FormData';
import { alertService } from '../../../Components/AlertService/AlertService';
import { CheckboxField } from '../../../Components/Fields/CheckboxField';
import Pagination from '../../../Components/Pagination/Pagination';
import { useRedirectionFeature } from '../../../Components/Hooks/Features/UseFeatures';
const BrokenLinkLogs = ({setFeatureEnable,setParentEnable,setIsLicenseConnect}) => {    
    const {enableRedirectionRule} = useRedirectionFeature();
    const [linkLogs, setLinkLogs] = useState([]);
    const [loading, setLoading] = useState(true);
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedLogs, setSelectedLogs] = useState({});
    const [allSelected, setAllSelected] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [pagination, setPagination] = useState({ page: 0, limit: 10, total_pages: 1, total_items: 0 });
    const [redirectionsFeature, setRedirectionsFeature] = useState(null);
    const { showAdvancedOptions, showRedirectModal, userRoles, isLoading, control, handlePostChange, handleSubmit, onSubmit, handleRoleSelection,
        errors, setShowModal, showModal, formValues, setValue, setShowAdvancedOptions, showInclusionRules, setShowInclusionRules, handleGetSourceUrl, preselectedValues, handleSelectionChange
    } = useRedirection();    
    useEffect(() => {      
        fetchBrokenLinkLogs();        
    }, []);

    const fetchBrokenLinkLogs = async (page = pagination.page, limit = pagination.limit) => {
        await getBrokenLinkLogs({ setLinkLogs, setLoading, page: page + 1, limit,setFeatureEnable,setParentEnable,setIsLicenseConnect, setRedirectionsFeature, setPagination: (paginationData) => { setPagination({ page: paginationData.page - 1, limit: paginationData.limit, total_pages: paginationData.total_pages, total_items: paginationData.total_items || 0 }) } });
    };

    const parseLogValue = (valueString) => {
        try {
            return JSON.parse(valueString);
        } catch (error) {
            console.error('Error parsing log value:', error);
            return {};
        }
    };

    const processedLogs = linkLogs ? linkLogs.map(log => {
        const valueData = parseLogValue(log.value);
        return { id: log.id, Url: valueData.url || 'N/A', errorMessage: valueData.error_message || 'N/A', parentType: valueData.parent_type || 'N/A', statusCode: valueData.status_code || 'N/A', lastChecked: valueData.last_checked || 'N/A', anchorText: valueData.anchor_text || 'N/A' };
    }) : [];

    const filteredLogs = processedLogs.filter(log => {
        const searchLower = searchTerm.toLowerCase();
        return (
            (log.Url && log.Url.toLowerCase().includes(searchLower)) ||
            (log.errorMessage && log.errorMessage.toLowerCase().includes(searchLower)) ||
            (log.lastChecked && log.lastChecked.toLowerCase().includes(searchLower)) ||
            (log.statusCode && String(log.statusCode).includes(searchLower))
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

    const handlePageChange = (newPage) => {
        setPagination(prev => ({ ...prev, page: newPage }));
        fetchBrokenLinkLogs(newPage, pagination.limit);
        // Clear selections when changing pages
        setSelectedLogs([]);
        setAllSelected(false);
    };

    const handlePageSizeChange = (newPageSize) => {
        setPagination(prev => ({ ...prev, limit: newPageSize, page: 0 }));
        fetchBrokenLinkLogs(0, newPageSize);
        setSelectedLogs([]);
        setAllSelected(false);
    };

    const selectedLogsCount = Object.values(selectedLogs).filter(Boolean).length;

    const handleDeleteAll = async () => {
        const confirmed = await alertService.confirm("This can't be undone. it removes all data from database", "Are you sure to delete ?");
        if (!confirmed) {
            return;
        }
        const deleteData = { ids: [], is_delete: true, };
        await handleDeleteLogs(deleteData, setIsDeleting, fetchBrokenLinkLogs);
        setSelectedLogs({});
        setAllSelected(false);
    };

    const handleBulkDelete = async () => {
        const confirmed = await alertService.confirm("This can't be undone.", "Are you sure to delete ?");
        if (!confirmed) {
            return;
        }
        const selectedIds = Object.keys(selectedLogs).filter(id => selectedLogs[id]).map(id => parseInt(id));
        const deleteData = { ids: selectedIds, is_delete: false, };
        await handleDeleteLogs(deleteData, setIsDeleting, fetchBrokenLinkLogs);
        setSelectedLogs({});
        setAllSelected(false);
    };

    const columns = [<CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={filteredLogs.length === 0} className='ml-1' />, "Url", "Error Message", "Parent Type", "Timestamp", "Anchor Text", "Create Rule"];

    const handlerenderlogsRow = (log) => {
        return LogsData({ log, selectedLogs, handleSelectLog, showRedirectModal, handleGetSourceUrl, redirectionsFeature });
    }
    return (
        <div className="px-4">
            <TableControlStrip handleDeleteAll={handleDeleteAll} disbaled={filteredLogs.length === 0} selectedFilesCount={selectedLogsCount} handleBulkDelete={handleBulkDelete} isDeleting={isDeleting} searchTerm={searchTerm} setSearchTerm={setSearchTerm} hasEligibleFiles={filteredLogs.length > 0} showControls={true} />
            {loading ? (
                <LoaderSkeleton count="0" />
            ) : (
                <>
                    <Table columns={columns} data={filteredLogs} renderRow={handlerenderlogsRow} noDataMessage="No Broken Link Logs found." />
                    <Pagination currentPage={pagination.page} totalPages={pagination.total_pages} onPageChange={handlePageChange} hasData={filteredLogs.length > 0} pageSizeOptions={[5, 10, 20, 30, 50, 100]} totalItems={pagination.total_items} showPageSizeFilter={true} pageSize={pagination.limit} onPageSizeChange={handlePageSizeChange} />
                </>
            )}

            {showModal && (
                <PopUpModal title="Add a redirect" disabled={isLoading} isLoading={isLoading} onClose={() => setShowModal(false)} onSave={handleSubmit(onSubmit)} saveButtonText="Add this redirect!" cancelButtonText="Cancel" width="w-full max-w-4xl" height="max-h-[90vh]" showExpandIcon={true}>
                    <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
                        <RedirectionFormContent control={control} errors={errors} formValues={formValues} isLoading={isLoading} userRoles={userRoles} setValue={setValue} handleRoleSelection={handleRoleSelection} handlePostChange={handlePostChange} showAdvancedOptions={showAdvancedOptions} setShowAdvancedOptions={setShowAdvancedOptions} showInclusionRules={showInclusionRules} setShowInclusionRules={setShowInclusionRules} redirectCodes={redirectCodes} matchTypeOptions={matchTypeOptions} initialPostType={preselectedValues.postType} initialPostId={preselectedValues.postId} initialCustomUrl={preselectedValues.customUrl} handleSelectionChange={handleSelectionChange} disableSourceUrl={true} />
                    </form>
                </PopUpModal>
            )}
        </div>
    );
};

export default BrokenLinkLogs;