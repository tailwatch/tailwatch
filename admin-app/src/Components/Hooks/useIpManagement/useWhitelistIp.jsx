import { useState, useEffect, useMemo, useRef } from "react";
import { getIpWhiteList, handleDeleteWhitelistIp } from '../../../Pages/IpManagement/IpManagementServices/IpManagementServices';
import { WhiteListIpData } from "../../../Pages/IpManagement/WhiteListContent/WhiteListIp/WhiteListIpData";
import { alertService } from "../../AlertService/AlertService";
import { CheckboxField } from "../../Fields/CheckboxField";
import { useIpFeature } from "../Features/UseFeatures";

export const useWhitelistIp = (initialLimit = 10,widget) => {

    const [showModal, setShowModal] = useState(false);
    const [ipWhiteListData, setIpWhiteListData] = useState([]);
    const [loading, setLoading] = useState(true);
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedItems, setSelectedItems] = useState([]);
    const [allSelected, setAllSelected] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [editingIpData, setEditingIpData] = useState(null);
    const {ipManagement,refreshIpStatus} = useIpFeature();
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);
    const [isLicenseConnect, setIsLicenseConnect] = useState(null);
    const isChecking = useRef(false);    
    // Pagination state
    const [pagination, setPagination] = useState({ page: 0, limit: initialLimit, total_pages: 1,total_items: 0  });    

    const handleAddToBlacklist = () => setShowModal(true);

    const handleCloseModal = () => {
        setShowModal(false);
        setEditingIpData(null);
    };

    const getWhiteListIpRanges = async (page = pagination.page, limit = pagination.limit) => {
        await getIpWhiteList({
            setLoading,
            setIpWhiteListData,
            page: page + 1,
            limit,
            setFeatureEnable,setParentEnable,setIsLicenseConnect,
            setPagination: (paginationData) => {
                setPagination({
                    page: paginationData.page - 1, // Convert back to 0-based
                    limit: paginationData.limit,
                    total_pages: paginationData.total_pages,
                    total_items: paginationData.total_items || 0 
                });
            }
        });
    };

    const handlePageChange = (newPage) => {
        setPagination(prev => ({ ...prev, page: newPage }));
        getWhiteListIpRanges(newPage, pagination.limit);
        // Clear selections when changing pages
        setSelectedItems([]);
        setAllSelected(false);
    };

    const handlePageSizeChange = (newPageSize) => {
        setPagination(prev => ({ ...prev, limit: newPageSize, page: 0 }));
        getWhiteListIpRanges(0, newPageSize);
        setSelectedItems([]);
        setAllSelected(false);
    };

    useEffect(() => {
        getWhiteListIpRanges();
    }, []);

    // Derived synchronously to avoid the empty-table flicker that happened
    // when this lived in a useEffect after blackListData changed.
    const filteredData = useMemo(() => {
        if (searchTerm.trim() === '') return ipWhiteListData;
        const q = searchTerm.toLowerCase();
        return ipWhiteListData.filter(item =>
            item.ip_range.toLowerCase().includes(q) ||
            item.exemption.toLowerCase().includes(q)
        );
    }, [searchTerm, ipWhiteListData]);

    useEffect(() => {
        setSelectedItems([]);
        setAllSelected(false);
    }, [searchTerm]);

    const handleSelectItem = (ipRange) => {
        setSelectedItems(prev =>
            prev.includes(ipRange) ? prev.filter(item => item !== ipRange) : [...prev, ipRange]
        );
    };

    const handleSelectAll = () => {
        if (allSelected) {
            setSelectedItems([]);
        } else {
            const allIpRanges = filteredData.map(item => item.ip_range);
            setSelectedItems(allIpRanges);
        }
        setAllSelected(!allSelected);
    };

    useEffect(() => {
        const allIpRanges = filteredData.map(item => item.ip_range);
        const isAllSelected = allIpRanges.length > 0 && allIpRanges.every(ip => selectedItems.includes(ip));
        setAllSelected(isAllSelected);
    }, [selectedItems, filteredData]);

    const handleDeletAll = async () => {
        const confirmed = await alertService.confirm("This can't be undone. it removes all data from database", "Are you sure to delete all this data?");
        if (!confirmed) {
            return;
        }
        setIsDeleting(true);
        try {
            const payload = { ip_ranges: '', is_delete: true };
            await handleDeleteWhitelistIp({ setLoading, payload, getWhiteListIpRanges: () => getWhiteListIpRanges(pagination.page, pagination.limit) });
            setSelectedItems([]);
            setAllSelected(false);
        } catch (error) {
            console.error('Error deleting items:', error);
        } finally {
            setIsDeleting(false);
        }
    }

    const handleBulkDelete = async () => {
        if (selectedItems.length === 0) return;
        const confirmed = await alertService.confirm("This can't be undone.", "Are you sure to delete ?");
        if (!confirmed) {
            return;
        }
        setIsDeleting(true);
        try {
            const payload = { ip_ranges: selectedItems, is_delete: false };
            await handleDeleteWhitelistIp({ setLoading, payload, getWhiteListIpRanges: () => getWhiteListIpRanges(pagination.page, pagination.limit) });
            setSelectedItems([]);
            setAllSelected(false);
        } catch (error) {
            console.error('Error deleting items:', error);
        } finally {
            setIsDeleting(false);
        }
    };

    const checkWhiteListIpStatus = async () => {
        if (isChecking.current) return;
        isChecking.current = true;
        try {
            await refreshIpStatus();
            setParentEnable(null);
            setFeatureEnable(null);      
            setIpWhiteListData([]);      
            await getWhiteListIpRanges();            
        } finally {
            isChecking.current = false;
        }
    };

    const columns = [...(!widget ? [ <CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={filteredData.length === 0} />] : []), 'Country', 'IP Range', 'Exemption', 'Edit'];
    const renderRow = (rowData) => {
        return (
            <WhiteListIpData widget={widget} rowData={rowData} selectedItems={selectedItems} handleSelectItem={handleSelectItem} setEditingIpData={setEditingIpData} setShowModal={setShowModal} />
        );
    };

    return {
        handleDeletAll, selectedItems,featureEnable,parentEnable,checkWhiteListIpStatus,ipManagement, handleBulkDelete, isDeleting, searchTerm, setSearchTerm, filteredData, handleAddToBlacklist, loading, columns, renderRow, pagination, handlePageChange, ipWhiteListData, showModal, handleCloseModal, getWhiteListIpRanges,handlePageSizeChange,isLicenseConnect, editingIpData
    }
}