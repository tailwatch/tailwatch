import { useState, useEffect, useRef } from "react";
import { useNavigate } from "react-router-dom";
import { useLocation } from "react-router-dom";
import { toast } from 'react-toastify'
import { useCronJobFeature } from "../Features/UseFeatures";
import { getCronJobLists, getSheduleCron, fetchLogsCron, bulkActions, pauseCronJob, resumeCronJob, deleteCronJob, deleteCronSchedule, runCronJob } from '../../../Pages/CronJob/CronJobServices/CronJobServices';

export const useCronJob = () => {
    const { cronJobRule, refreshCronJobStatus } = useCronJobFeature();
    const location = useLocation();
    const navigate = useNavigate();
    const [scheduleJobPagination, setScheduleJobPagination] = useState({ page: 1, limit: 10, total_pages: 1, total_schedules: 0 });
    const [cronJobPagination, setCronJobPagination] = useState({ page: 1, limit: 10, total_pages: 1, total_records: 0 });
    const [pagination, setPagination] = useState({ page: 0, limit: 10, total_pages: 1, total_items: 0 });
    const [cronJobData, setCronJobData] = useState([]);
    const [scheduleJobData, setScheduleCronData] = useState([]);
    const [selectedJobs, setSelectedJobs] = useState(new Set());
    const [allSelected, setAllSelected] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const [loading, setLoading] = useState(true);
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);
    const [deleting, setDeleting] = useState(false);
    const [logsData, setLogsData] = useState([]);
    const isChecking = useRef(false);

    const getActiveTabFromURL = () => {
        if (location.pathname.includes('/schedule-jobs')) {
            return 'schedule-jobs';
        } else if (location.pathname.includes('/cron-job-logs')) {
            return 'cron-job-logs';
        }
        return 'cron-jobs';
    };
    const [activeTab, setActiveTab] = useState(getActiveTabFromURL());

    const tabs = [
        { key: 'cron-jobs', label: 'Cron Events' },
        { key: 'schedule-jobs', label: 'Cron Schedules' },
    ];

    const fetchCronJobs = async (page = 1, limit = cronJobPagination.limit) => {
        setLoading(true);
        try {
            await getCronJobLists({ setCronJobData, setLoading, page, limit, setPagination: setCronJobPagination, setFeatureEnable, setParentEnable });
        } catch (error) {
            console.error('Error fetching cron jobs:', error);
        }
    };

    const fetchScheduleJobs = async (page = 1, limit = scheduleJobPagination.limit) => {
        setLoading(true);
        try {
            await getSheduleCron({ setScheduleCronData, setLoading, page, limit, setPagination: setScheduleJobPagination, setParentEnable, setFeatureEnable });
        } catch (error) {
            console.error('Error fetching schedule jobs:', error);
        }
    };

    const fetchLogsData = async (page = pagination.page, limit = pagination.limit) => {
        await fetchLogsCron({
            setLoading, setLogsData, page: page + 1, limit, setParentEnable, setFeatureEnable,
            setPagination: (paginationData) => {
                setPagination({ page: paginationData.page - 1, limit: paginationData.limit, total_pages: paginationData.total_pages, total_items: paginationData.total_items || 0 });
            }
        });
    };

    useEffect(() => {
        const currentTab = getActiveTabFromURL();
        setActiveTab(currentTab);

        if (currentTab === 'cron-jobs') {
            fetchCronJobs(1);
        } else if (currentTab === 'schedule-jobs') {
            fetchScheduleJobs(1);
        } else if (currentTab === 'cron-job-logs') {
            setPagination(prev => ({ ...prev, page: 0 })); // Reset to page 0 (which becomes page 1 in the API)
            fetchLogsData(0);
        }
    }, [location.pathname]);

    const handleTabChange = (tab) => {
        if (tab === 'cron-jobs') {
            navigate('/dashboard/cron-jobs');
        } else if (tab === 'schedule-jobs') {
            navigate('/dashboard/cron-jobs/schedule-jobs');
        } else if (tab === 'cron-job-logs') {
            setPagination(prev => ({ ...prev, page: 0 })); // Reset to page 0 when switching to logs tab
            navigate('/dashboard/cron-jobs/cron-job-logs');
        }
    };

    const handleCronJobPageChange = (page) => {
        setCronJobPagination(prev => ({ ...prev, page: page + 1 }));
        fetchCronJobs(page + 1);
    };
    const handleCronJobPageSizeChange = (newPageSize) => {
        setCronJobPagination(prev => ({ ...prev, limit: newPageSize, page: 1 })); // Reset to page 1, not 0
        fetchCronJobs(1, newPageSize); // Pass newPageSize parameter
        setAllSelected(false);
    };

    const handleScheduleJobPageChange = (page) => {
        setScheduleJobPagination(prev => ({ ...prev, page: page + 1 }));
        fetchScheduleJobs(page + 1);
    };

    const handleScheduleJobPageSizeChange = (newPageSize) => {
        setScheduleJobPagination(prev => ({ ...prev, limit: newPageSize, page: 1 })); // Reset to page 1, not 0
        fetchScheduleJobs(1, newPageSize); // Pass newPageSize parameter
        setAllSelected(false);
    };

    const getEligibleJobs = () => {
        if (activeTab !== 'cron-jobs') return [];
        return cronJobData.filter(job => !job.is_protected);
    };

    const eligibleJobs = getEligibleJobs();
    const hasEligibleJobs = eligibleJobs.length > 0;

    const handleSelectAll = () => {
        if (allSelected) {
            setSelectedJobs(new Set());
            setAllSelected(false);
        } else {
            const eligibleHashes = eligibleJobs.map(job => job.hash);
            setSelectedJobs(new Set(eligibleHashes));
            setAllSelected(true);
        }
    };

    const handleRowSelect = (jobHash, isSelected) => {
        const newSelected = new Set(selectedJobs);
        if (isSelected) {
            newSelected.add(jobHash);
        } else {
            newSelected.delete(jobHash);
        }
        setSelectedJobs(newSelected);
    };

    const performBulkAction = async (action) => {
        if (selectedJobs.size === 0) {
            toast.error('Please select at least one job');
            return;
        }
        let jobsToAction;
        if (action === 'run') {
            jobsToAction = cronJobData.filter(job => selectedJobs.has(job.hash));
        } else {
            jobsToAction = cronJobData.filter(job =>
                selectedJobs.has(job.hash) && !job.is_protected
            );
        }
        setLoading(true);
        try {
            const hashes = jobsToAction.map(job => job.hash);
            await bulkActions({ type: action, hashes, bulk_action: action });
            toast.success(`${action} cron completed successfully`);
            await fetchCronJobs();
            setSelectedJobs(new Set());
            setAllSelected(false);
        } catch (error) {
            console.error(`Error performing bulk ${action}:`, error);
            toast.error(`Failed to perform bulk ${action}`);
        }
        finally {
            setLoading(false);
        }
    };

    const handleBulkPause = () => performBulkAction('pause');
    const handleBulkResume = () => performBulkAction('resume');
    const handleBulkRun = () => performBulkAction('run');
    const handleBulkDelete = () => performBulkAction('delete');

    const handleRunNow = async (hash) => {
        await runCronJob({ hash, fetchCronJobs });
    };

    const handlePause = async (hash) => {
        await pauseCronJob({ hash, fetchCronJobs });
    };

    const handleResume = async (hash) => {
        await resumeCronJob({ hash, fetchCronJobs });
    };

    const handleDelete = async (hash) => {
        await deleteCronJob({ hash, fetchCronJobs });
    };

    const handleDeleteSchedule = async (slug) => {
        await deleteCronSchedule({ slug, fetchScheduleJobs, setDeleting });
    };

    const checkCronJobStatus = async () => {
        if (isChecking.current) return;
        isChecking.current = true;
        try {
            await refreshCronJobStatus();
            setParentEnable(null);
            setFeatureEnable(null);
            setCronJobData([]);
            setScheduleCronData([]);
            await fetchCronJobs();
            await fetchScheduleJobs();
        } finally {
            isChecking.current = false;
        }
    };

    return {
        cronJobData, logsData, pagination, setPagination, fetchLogsData, deleting, scheduleJobData, loading, activeTab, tabs, handleTabChange, handleCronJobPageChange, handleScheduleJobPageChange, cronJobPagination, scheduleJobPagination, fetchCronJobs, setLoading,
        hasEligibleJobs, handleBulkPause, handleBulkResume, handleBulkRun, handleBulkDelete, handleSelectAll, handleRowSelect, selectedJobs, allSelected, handleRunNow, handlePause, handleDelete, handleResume, handleDeleteSchedule,
        featureEnable, parentEnable, cronJobRule, checkCronJobStatus, searchTerm, setSearchTerm, handleCronJobPageSizeChange, handleScheduleJobPageSizeChange, fetchScheduleJobs, setParentEnable, setFeatureEnable, refreshCronJobStatus, fetchLogsData
    }
}