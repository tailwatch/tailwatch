import React, { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import Table from '../../../Components/Table/Table';
import TableControlStrip from '../../../Components/TableControlStrip/TableControlStrip';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton';
import { ReportsData } from './ReportsData';
import { listHardeningAuditReports, deleteHardeningAuditReport } from '../AuditServices/AuditServices';
import { alertService } from '../../../Components/AlertService/AlertService';
import { CheckboxField } from '../../../Components/Fields/CheckboxField';
import Pagination from '../../../Components/Pagination/Pagination';

const AuditReports = ({ setFeatureEnable, setParentEnable }) => {
    const navigate = useNavigate();
    const [reports, setReports] = useState([]);
    const [loading, setLoading] = useState(true);
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedReports, setSelectedReports] = useState({});
    const [allSelected, setAllSelected] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [pagination, setPagination] = useState({ page: 0, limit: 10, total_pages: 1, total_items: 0 });

    const fetchReports = async (page = pagination.page, limit = pagination.limit) => {
        await listHardeningAuditReports({
            setReports,
            setLoading,
            page: page + 1,
            limit,
            setFeatureEnable,
            setParentEnable,
            setPagination: (paginationData) => {
                setPagination({
                    page: (paginationData.page || 1) - 1,
                    limit: paginationData.limit,
                    total_pages: paginationData.total_pages,
                    total_items: paginationData.total_items || 0,
                });
            },
        });
    };

    useEffect(() => {
        fetchReports();
    }, []);

    const filteredReports = (reports || []).filter((report) => {
        if (!searchTerm) return true;
        const searchLower = searchTerm.toLowerCase();
        return Object.values(report || {})
            .filter((v) => v !== null && v !== undefined)
            .some((v) => String(v).toLowerCase().includes(searchLower));
    });

    const handleSelectReport = (reportId) => {
        setSelectedReports((prev) => {
            const next = { ...prev, [reportId]: !prev[reportId] };
            const everySelected = filteredReports.every((r) => {
                const id = r?.id ?? r?.report_id;
                return next[id];
            });
            setAllSelected(filteredReports.length > 0 && everySelected);
            return next;
        });
    };

    const handleSelectAll = () => {
        const newSelectedState = !allSelected;
        setAllSelected(newSelectedState);
        const next = { ...selectedReports };
        filteredReports.forEach((r) => {
            const id = r?.id ?? r?.report_id;
            if (id !== undefined) next[id] = newSelectedState;
        });
        setSelectedReports(next);
    };

    const handlePageChange = (newPage) => {
        setPagination((prev) => ({ ...prev, page: newPage }));
        fetchReports(newPage, pagination.limit);
        setSelectedReports({});
        setAllSelected(false);
    };

    const handlePageSizeChange = (newPageSize) => {
        setPagination((prev) => ({ ...prev, limit: newPageSize, page: 0 }));
        fetchReports(0, newPageSize);
        setSelectedReports({});
        setAllSelected(false);
    };

    const selectedCount = Object.values(selectedReports).filter(Boolean).length;

    const handleDeleteAll = async () => {
        const confirmed = await alertService.confirm("This can't be undone. it removes all hardening audit reports from the database", "Are you sure to delete ?");
        if (!confirmed) return;
        await deleteHardeningAuditReport({ ids: [], is_delete: true }, setIsDeleting, fetchReports);
        setSelectedReports({});
        setAllSelected(false);
    };

    const handleBulkDelete = async () => {
        const confirmed = await alertService.confirm("This can't be undone.", "Are you sure to delete ?");
        if (!confirmed) return;
        const ids = Object.keys(selectedReports)
            .filter((id) => selectedReports[id])
            .map((id) => (Number.isNaN(parseInt(id, 10)) ? id : parseInt(id, 10)));
        await deleteHardeningAuditReport({ ids, is_delete: false }, setIsDeleting, fetchReports);
        setSelectedReports({});
        setAllSelected(false);
    };

    const columns = [
        <CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={filteredReports.length === 0} className='ml-1' />,
        "Report ID",
        "Scan Time",
        "Type",
        "Status",
        "Summary",
        "Actions",
    ];

    const handleViewReport = (reportId) => {
        navigate(`/dashboard/hardening-audit/report/${encodeURIComponent(reportId)}`);
    };

    const renderRow = (report) => (
        <ReportsData
            report={report}
            selectedReports={selectedReports}
            handleSelectReport={handleSelectReport}
            onView={handleViewReport}
        />
    );

    return (
        <div className="px-4">
            <TableControlStrip
                handleDeleteAll={handleDeleteAll}
                disbaled={filteredReports.length === 0}
                selectedFilesCount={selectedCount}
                handleBulkDelete={handleBulkDelete}
                isDeleting={isDeleting}
                searchTerm={searchTerm}
                setSearchTerm={setSearchTerm}
                hasEligibleFiles={filteredReports.length > 0}
                showControls={true}
            />
            {loading ? (
                <LoaderSkeleton count="0" />
            ) : (
                <>
                    <Table
                        columns={columns}
                        data={filteredReports}
                        renderRow={renderRow}
                        noDataMessage="No Hardening Audit reports found."
                    />
                    <Pagination
                        currentPage={pagination.page}
                        totalPages={pagination.total_pages}
                        onPageChange={handlePageChange}
                        hasData={filteredReports.length > 0}
                        pageSizeOptions={[5, 10, 20, 30, 50, 100]}
                        totalItems={pagination.total_items}
                        showPageSizeFilter={true}
                        pageSize={pagination.limit}
                        onPageSizeChange={handlePageSizeChange}
                    />
                </>
            )}
        </div>
    );
};

export default AuditReports;
