import { useState, useEffect, useMemo, useRef } from 'react'
import { getIpDetails, handleUnblockIp, handleDeleteIp } from '../../../Pages/BruteForce/BruteForceService/BruteForceService';
import { BruteForceData } from '../../../Pages/BruteForce/BruteForceData/BruteForceData';
import { addToBlackList, addIpToWhiteList } from '../../../Pages/IpManagement/IpManagementServices/IpManagementServices';
import { alertService } from '../../AlertService/AlertService';
import { CheckboxField } from '../../Fields/CheckboxField';
import { useBruteForceFeature } from '../Features/UseFeatures';
export const useBruteForce = (initialLimit = 10, widget) => {
    const { bruteForceRule, refreshBruteForceStatus } = useBruteForceFeature()
    const [selectedItems, setSelectedItems] = useState([]);
    const [allSelected, setAllSelected] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [isLoading, setLoading] = useState(true);
    const [ipDetailsData, setIpDetailsData] = useState([]);
    const [pagination, setPagination] = useState({ page: 0, limit: initialLimit, total_pages: 0, total_items: 0 });
    const [searchTerm, setSearchTerm] = useState('');
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);
    const [isLicenseConnect, setIsLicenseConnect] = useState(null);
    const isChecking = useRef(false);
    const getIpDetailsData = async (page = pagination.page, limit = pagination.limit) => {
        await getIpDetails({
            setLoading, setIpDetailsData, page: page + 1, limit, setFeatureEnable, setParentEnable, setIsLicenseConnect,
            setPagination: (paginationData) => {
                setPagination({ page: paginationData.page - 1, limit: paginationData.limit, total_pages: paginationData.total_pages, total_items: paginationData.total_items || 0 });
            }
        });
    };
    useEffect(() => {
        getIpDetailsData();
    }, []);

    const handlePageChange = (newPage) => {
        setPagination(prev => ({ ...prev, page: newPage }));
        getIpDetailsData(newPage, pagination.limit);
    };

    const handlePageSizeChange = (newPageSize) => {
        setPagination(prev => ({ ...prev, limit: newPageSize, page: 0 }));
        getIpDetailsData(0, newPageSize);
        setSelectedItems([]);
        setAllSelected(false);
    };

    const filteredData = useMemo(() => {
        if (!searchTerm.trim()) return ipDetailsData;

        return ipDetailsData.filter(item => item.ip?.toLowerCase().includes(searchTerm.toLowerCase()) || item.country?.toLowerCase().includes(searchTerm.toLowerCase()));
    }, [ipDetailsData, searchTerm]);

    const handleUnblockIpAddress = async () => {
        const ip = { ips: selectedItems };
        await handleUnblockIp({ setLoading, ip, getIpDetailsData })
    };

    const handleAddToBlackList = async () => {
        const payload = { ip_ranges: selectedItems, "block_type": "permanent", "block_duration": "0", "scope": "login_forms", "reason": "Blacklisted from BruteForce" }
        const success = await addToBlackList({ setLoading, payload, getIpDetailsData: () => getIpDetailsData(pagination.page, pagination.limit) });
        if (success) {
            await handleDeleteIp({ setLoading, payload: { ips: selectedItems, "is_delete": false }, getIpDetailsData: () => getIpDetailsData(pagination.page, pagination.limit) });
            setSelectedItems([]);
        }
    }

    const handleAddToWhitelist = async () => {
        // Only one exemption type is supported now — Login Defender. Older builds
        // sent "full_exemption" which the backend no longer recognises.
        const payload = { ip_ranges: selectedItems, "exemption": "login_defender" }
        const success = await addIpToWhiteList({ setLoading, payload, getIpDetailsData: () => getIpDetailsData(pagination.page, pagination.limit) });
        if (success) {
            await handleDeleteIp({ setLoading, payload: { ips: selectedItems, "is_delete": false }, getIpDetailsData: () => getIpDetailsData(pagination.page, pagination.limit) });
            setSelectedItems([]);
        }
    }


    const handleSelectItem = (ipRange) => {
        setSelectedItems(prev =>
            prev.includes(ipRange) ? prev.filter(item => item !== ipRange) : [...prev, ipRange]
        );
    };

    const handleSelectAll = () => {
        if (allSelected) {
            setSelectedItems([]);
        } else {
            const allIpRanges = filteredData.map(item => item.ip);
            setSelectedItems(allIpRanges);
        }
        setAllSelected(!allSelected);
    };

    const columns = [...(!widget ? [<CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={filteredData.length === 0} />] : []), 'IP Address', 'Country', 'Block Status', 'Attempt Count', 'Reason', 'Last Attempt', 'Lock Duration', 'Details'];
    const renderRow = (rowData) => {
        return (<BruteForceData widget={widget} rowData={rowData} handleSelectItem={handleSelectItem} selectedItems={selectedItems} />);
    };
    const handleDeletAll = async () => {
        const confirmed = await alertService.confirm("This can't be undone. it removes all data from database", "Are you sure to delete all this data?");
        if (!confirmed) {
            return;
        }
        setIsDeleting(true);
        try {
            const payload = { ips: '', is_delete: true };
            await handleDeleteIp({ setLoading, payload, getIpDetailsData, getIpDetailsData: () => getIpDetailsData(pagination.page, pagination.limit) });
            setSelectedItems([]);
            setAllSelected(false);
        } catch (error) {
            console.error('Error deleting items:', error);
        } finally {
            setIsDeleting(false);
        }
    }
    const checkBruteForceStatus = async () => {
        if (isChecking.current) return;
        isChecking.current = true;
        try {
            await refreshBruteForceStatus();
            setParentEnable(null);
            setFeatureEnable(null);
            setIsLicenseConnect(null);
            setIpDetailsData([]);
            await getIpDetailsData();
        } finally {
            isChecking.current = false;
        }
    };

    const handleBulkDelete = async () => {
        if (selectedItems.length === 0) return;
        const confirmed = await alertService.confirm("This can't be undone.", "Are you sure to delete ?");
        if (!confirmed) {
            return;
        }
        setIsDeleting(true);
        try {
            const payload = { ips: selectedItems, is_delete: false };
            await handleDeleteIp({ setLoading, payload, getIpDetailsData, getIpDetailsData: () => getIpDetailsData(pagination.page, pagination.limit) });
            setSelectedItems([]);
            setAllSelected(false);
        } catch (error) {
            console.error('Error deleting items:', error);
        } finally {
            setIsDeleting(false);
        }
    };

    return {
        searchTerm, bruteForceRule, refreshBruteForceStatus, checkBruteForceStatus, featureEnable, parentEnable, setParentEnable, setSearchTerm, isLoading, columns, filteredData, handlePageSizeChange, renderRow, pagination, handlePageChange, ipDetailsData, handleDeletAll, selectedItems, handleBulkDelete, isDeleting, handleUnblockIpAddress, handleAddToBlackList, handleAddToWhitelist, isLicenseConnect, setIsLicenseConnect
    }
}