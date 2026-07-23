import { CheckboxField } from '../../../Components/Fields/CheckboxField';
import ActionButton from '../../../Components/Buttons/ActionButton';

const formatScanType = (type) => {
    if (!type) return '—';
    return type
        .split(/[-_\s]+/)
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(' ');
};

const SummaryPills = ({ summary }) => {
    if (!summary || typeof summary !== 'object') return <span className="text-gray-400">—</span>;

    const critical = summary.critical ?? 0;
    const error = summary.error ?? 0;
    const warning = summary.warning ?? 0;
    const notice = summary.notice ?? 0;
    const issues = critical + error + warning + notice;

    if (issues === 0) {
        return (
            <span className="inline-flex items-center border bg-green-100 text-green-800 border-green-200 px-2.5 py-1 rounded-full text-xs font-semibold whitespace-nowrap">
                All Passed
            </span>
        );
    }

    const classes = (critical > 0 || error > 0)
        ? 'bg-red-100 text-red-800 border-red-200'
        : warning > 0
            ? 'bg-yellow-100 text-yellow-800 border-yellow-200'
            : 'bg-blue-100 text-blue-800 border-blue-200';

    return (
        <span className={`inline-flex items-center border px-2.5 py-1 rounded-full text-xs font-semibold whitespace-nowrap ${classes}`}>
            {issues} {issues === 1 ? 'Issue' : 'Issues'} Found
        </span>
    );
};

export const ReportsData = ({ report, selectedReports, handleSelectReport, onView }) => {
    const id = report?.id ?? '—';
    const scanTime = report?.scan_time ?? '—';
    const scanType = report?.scan_type;
    const isCompleted = report?.is_completed === true;
    const canView = !!report?.id;

    return (
        <>
            <td className="p-3">
                <CheckboxField
                    checked={!!selectedReports[id]}
                    onChange={() => handleSelectReport(id)}
                />
            </td>
            <td className="p-3 text-sm text-black font-mono">{id}</td>
            <td className="p-3 text-sm text-black whitespace-nowrap">{scanTime}</td>
            <td className="p-3 text-sm text-black">
                <span className="inline-flex items-center border bg-blue-100 text-blue-800 border-blue-200 px-2.5 py-1 rounded-full text-xs font-semibold whitespace-nowrap">
                    {formatScanType(scanType)}
                </span>
            </td>
            <td className="p-3 text-sm text-black">
                <span
                    className={`inline-flex items-center border px-2.5 py-1 rounded-full text-xs font-semibold whitespace-nowrap ${
                        isCompleted
                            ? 'bg-green-100 text-green-800 border-green-200'
                            : 'bg-yellow-100 text-yellow-800 border-yellow-200'
                    }`}
                >
                    {isCompleted ? 'Completed' : 'In Progress'}
                </span>
            </td>
            <td className="p-3 text-sm text-black">
                <SummaryPills summary={report?.summary} />
            </td>
            <td className="p-3 text-sm text-black">
                <ActionButton
                    defaultText="View"
                    isDisabled={!canView}
                    onClick={() => canView && onView && onView(report.id)}
                />
            </td>
        </>
    );
};
