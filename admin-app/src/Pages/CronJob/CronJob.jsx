import { useState, useEffect, useRef } from 'react'
import Header from '../../Components/Header/Header'
import TableTabs from '../../Components/TableTabs/TableTabs'
import Table from '../../Components/Table/Table'
import Pagination from '../../Components/Pagination/Pagination'
import { renderCronJobRowData, renderScheduleJobRowData } from './CronJobTableData/CronJobTableData'
import LoaderSkeleton from '../../Components/Skeleton/LoaderSkeleton'
import { useCronJob } from '../../Components/Hooks/useCronJobs/useCronJobs'
import TableControlStrip from '../../Components/TableControlStrip/TableControlStrip'
import EditCronJobModal from './CronJobModals/EditCronJobModal'
import ActionButton from '../../Components/Buttons/ActionButton'
import CreateCronjobModal from './CronJobModals/CreateCronjobModal'
import CreateCronSchedule from './CronJobModals/CreateCronSchedule'
import LockCard from '../../Components/LockCard/LockCard'
import InfoBar from '../../Components/InfoBar/InfoBar'
import LoadingBar from 'react-top-loading-bar';
import { CheckboxField } from '../../Components/Fields/CheckboxField'
import EditCronSchedule from './CronJobModals/EditCronScheduleModal'

const CronJob = () => {
    const { cronJobData, pagination, setPagination, logsData, fetchLogsData, deleting, scheduleJobData, loading, activeTab, tabs, handleTabChange, handleCronJobPageSizeChange, handleScheduleJobPageSizeChange, handleCronJobPageChange, handleScheduleJobPageChange, cronJobPagination, scheduleJobPagination, hasEligibleJobs, handleBulkPause, handleBulkResume, handleBulkRun, handleBulkDelete, handleSelectAll, handleRowSelect,
        selectedJobs, allSelected, handleRunNow, handlePause, fetchScheduleJobs, handleDelete, handleResume, fetchCronJobs, handleDeleteSchedule, featureEnable, parentEnable, cronJobRule, checkCronJobStatus, searchTerm, setSearchTerm, setParentEnable, setFeatureEnable, refreshCronJobStatus
    } = useCronJob();

    const [hoveredRowHash, setHoveredRowHash] = useState(null);
    const [isBarLoading, setIsBarLoading] = useState(false);
    const [isEditModalOpen, setIsEditModalOpen] = useState(false);
    const [isCreateModalOpen, setIsCreateModalOpen] = useState(false);
    const [isScheduleModalOpen, setIsScheduleModalOpen] = useState(false);
    const [selectedCronJob, setSelectedCronJob] = useState(null);
    const [isEditScheduleModalOpen, setIsEditScheduleModalOpen] = useState(false);
    const [selectedSchedule, setSelectedSchedule] = useState(null);
    const loadingBarRef = useRef(null);
    const cronJobColumns = [<CheckboxField checked={allSelected} onChange={handleSelectAll} />, 'Hook', 'Next Run', 'Schedule', 'Action'];
    const scheduleJobColumns = ['Internal name', 'Display name', 'Interval',];
    const [loadingAction, setLoadingAction] = useState(null);

    const withLoader = async (actionType, callback) => {
        setLoadingAction(actionType);
        try {
            await callback();
        } finally {
            setLoadingAction(null);
        }
    };

    useEffect(() => {
        const isAnyLoading = loading;

        if (isAnyLoading && !isBarLoading) {
            loadingBarRef.current.continuousStart();
            setIsBarLoading(true);
        } else if (!isAnyLoading && isBarLoading) {
            loadingBarRef.current.complete();
            setIsBarLoading(false);
        }
    }, [loading, isBarLoading]);

    const handleEdit = (rowData) => {
        setSelectedCronJob(rowData);
        setIsEditModalOpen(true);
    };

    const handleEditSchedule = (rowData) => {
        setSelectedSchedule(rowData);
        setIsEditScheduleModalOpen(true);
    };

    const renderCronJobRow = (rowData) => {
        return renderCronJobRowData({ rowData, selectedJobs, hoveredRowHash, setHoveredRowHash, handleRowSelect, handleEdit, handleRunNow, handleResume, handlePause, handleDelete, })
    };

    const renderScheduleJobRow = (rowData) => {
        return renderScheduleJobRowData({ loadingAction, withLoader, rowData, hoveredRowHash, setHoveredRowHash, handleDeleteSchedule, handleEditSchedule, deleting })
    }

    const getCurrentData = () => {
        const filterData = (data) => {
            if (!searchTerm) return data;
            const searchLower = searchTerm.toLowerCase();
            return data.filter(item => {
                if (activeTab === 'cron-jobs') {
                    return (
                        item.hook?.toLowerCase().includes(searchLower) ||
                        item.schedule?.toLowerCase().includes(searchLower) ||
                        item.next_run_formatted?.toLowerCase().includes(searchLower)
                    );
                } else {
                    return (
                        item.slug?.toLowerCase().includes(searchLower) ||
                        item.display_name?.toLowerCase().includes(searchLower) ||
                        item.interval?.toString().includes(searchLower)
                    );
                }
            });
        };

        if (activeTab === 'cron-jobs') {
            const filteredData = filterData(cronJobData);
            return {
                data: filteredData,
                pagination: cronJobPagination,
                columns: cronJobColumns,
                renderRow: renderCronJobRow,
                onPageChange: handleCronJobPageChange,
                onPageSizeChange: handleCronJobPageSizeChange,
                totalItems: filteredData.length,
                noDataMessage: 'No cron jobs found'
            };
        } else if (activeTab === 'schedule-jobs') {
            const filteredData = filterData(scheduleJobData);
            return {
                data: filteredData,
                pagination: scheduleJobPagination,
                columns: scheduleJobColumns,
                renderRow: renderScheduleJobRow,
                onPageChange: handleScheduleJobPageChange,
                onPageSizeChange: handleScheduleJobPageSizeChange,
                totalItems: filteredData.length,
                noDataMessage: 'No schedule jobs found'
            };
        }
        return null;
    };

    const renderTabContent = () => {


        const currentTabData = getCurrentData();

        return (
            <>
                {(featureEnable === false || parentEnable === false) && (
                    <InfoBar type="info" message="Please Configure your Settings First" />
                )}
                <TableControlStrip deleteButton={false} searchTerm={searchTerm} setSearchTerm={setSearchTerm} featureId={cronJobRule?.featureId} isDisabled={parentEnable === false} CheckStatus={checkCronJobStatus} allSelected={activeTab === 'cron-jobs' ? allSelected : false} handleSelectAll={activeTab === 'cron-jobs' ? handleSelectAll : undefined} selectedFilesCount={activeTab === 'cron-jobs' ? selectedJobs.size : 0} hasEligibleFiles={activeTab === 'cron-jobs' ? hasEligibleJobs : false} handleBulkDelete={activeTab === 'cron-jobs' ? handleBulkDelete : undefined} handleBulkPause={activeTab === 'cron-jobs' ? handleBulkPause : undefined} handleBulkResume={activeTab === 'cron-jobs' ? handleBulkResume : undefined} handleBulkRun={activeTab === 'cron-jobs' ? handleBulkRun : undefined} showControls={activeTab === 'cron-jobs'} showDeleteButton={false} showCanvas={true} showBulkActions={activeTab === 'cron-jobs'} showAddButton={true} addButtonLabel={activeTab === 'cron-jobs' ? 'Add Cron Event' : 'Add Schedule'} onAddButtonClick={() => activeTab === 'cron-jobs' ? setIsCreateModalOpen(true) : setIsScheduleModalOpen(true)} onButtonDisable={activeTab === 'cron-jobs' ? (featureEnable === false || parentEnable === false) : (featureEnable === true || parentEnable === true)} />
                <div className="mt-4">
                    <Table columns={currentTabData.columns} data={currentTabData.data} renderRow={currentTabData.renderRow} noDataMessage={currentTabData.noDataMessage} />
                </div>
                <Pagination currentPage={currentTabData.pagination.page - 1} totalPages={currentTabData.pagination.total_pages} onPageChange={currentTabData.onPageChange} hasData={currentTabData.data.length > 0} pageSizeOptions={[5, 10, 20, 30, 50, 100]} totalItems={currentTabData.pagination.totalItems} showPageSizeFilter={true} pageSize={currentTabData.pagination.limit} onPageSizeChange={currentTabData.onPageSizeChange} />
            </>
        );
    };

    return (
        <div>
            <LoadingBar ref={loadingBarRef} height={3} color="#ec5023" />
            <Header title="Cron Jobs" />
            <div className='p-4'>
                <TableTabs tabs={tabs} activeTab={activeTab} setActiveTab={handleTabChange} />
                {loading ? (
                    <LoaderSkeleton count='0' />
                ) : (
                    renderTabContent()
                )}
            </div>
            {(parentEnable === false) && (<LockCard featureName="Cron Jobs" featureDescription="Manage all your WordPress cron jobs from one place. View, edit, pause, delete" featureId={cronJobRule?.featureId} isActive={cronJobRule?.isActiveState} isLocked={cronJobRule?.isLocked} afterToggleCallback={checkCronJobStatus} />)}
            {isEditModalOpen && (
                <EditCronJobModal isOpen={isEditModalOpen} onClose={() => setIsEditModalOpen(false)} cronJobData={selectedCronJob} fetchCronJobs={fetchCronJobs} />
            )}
            {isCreateModalOpen && (
                <CreateCronjobModal isOpen={isCreateModalOpen} onClose={() => setIsCreateModalOpen(false)} fetchCronJobs={fetchCronJobs} />
            )}
            {isScheduleModalOpen && (
                <CreateCronSchedule isOpen={isScheduleModalOpen} onClose={() => setIsScheduleModalOpen(false)} fetchCronJobs={fetchScheduleJobs} />
            )}
            {isEditScheduleModalOpen && (
                <EditCronSchedule isOpen={isEditScheduleModalOpen} onClose={() => setIsEditScheduleModalOpen(false)} scheduleData={selectedSchedule} fetchCronJobs={fetchScheduleJobs} />
            )}
        </div>
    )
}

export default CronJob