
import { CheckboxField } from "../../../Components/Fields/CheckboxField";
export const renderCronLogs = (log, selectedLogs, handleSelectRow, showRedirectModal, handleGetSourceUrl) => {
    if (!log || !log.parsedValue) {
        console.error("Invalid log data:", log);
        return null;
    }
    const truncateUrl = (url) => {
        return url.length > 30 ? url.substring(0, 30) + '...' : url;
    };

    const { parsedValue, date_created, id } = log;
    let userData = {};

    try {
        userData = typeof parsedValue.user_data === 'string' ? JSON.parse(parsedValue.user_data) : parsedValue.user_data;
    } catch (e) {
        console.error("Error parsing user data:", e);
    }

    return (
        <>
            <td className="p-3">
                <CheckboxField checked={!!selectedLogs[id]} onChange={() => handleSelectRow(id)} />
            </td>
            <td className="p-3 text-sm">
               {parsedValue?.action}
            </td>
            <td className="p-3 text-sm">
                {parsedValue?.hook}
            </td>            
            <td className="p-3 text-sm" title={parsedValue?.message}>
                {parsedValue?.message}
            </td>                   
            <td className="p-3 text-sm" >
                {parsedValue?.created_at}
            </td>                   
        </>
    );
};