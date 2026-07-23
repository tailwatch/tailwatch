import { useState, useEffect, useMemo, useRef } from "react";
import { getBlackList, handleDeleteIp, handleUnblockIp } from '../../../Pages/IpManagement/IpManagementServices/IpManagementServices';
import { BlackListData } from '../../../Pages/IpManagement/BlackListContent/BlackListData/BlackListData';
import { alertService } from "../../AlertService/AlertService";
import { CheckboxField } from "../../Fields/CheckboxField";
import { useIpFeature } from "../Features/UseFeatures";
export const useBlackList = (initialLimit = 10,widget) => {
    const { ipManagement,refreshIpStatus } = useIpFeature();
    const [showModal, setShowModal] = useState(false);
    const [blackListData, setBlackListData] = useState([]);
    const [loading, setLoading] = useState(true);
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedItems, setSelectedItems] = useState([]);
    const [allSelected, setAllSelected] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [editingIpData, setEditingIpData] = useState(null);      
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);  
    const [isLicenseConnect, setIsLicenseConnect] = useState(null);
    const isChecking = useRef(false);    
    // Pagination state
    const [pagination, setPagination] = useState({ page: 0, limit: initialLimit, total_pages: 1,total_items: 0 });   

    const handleAddToBlacklist = () => setShowModal(true);

    const handleCloseModal = () => {
        setShowModal(false);
        setEditingIpData(null);
    };

    const getBlackListIpRanges = async (page = pagination.page, limit = pagination.limit) => {
        await getBlackList({
            setLoading,
            setBlackListData,
            page: page + 1,
            limit,
            setPagination: (paginationData) => {
                setPagination({
                    page: paginationData.page - 1, // Convert back to 0-based
                    limit: paginationData.limit,
                    total_pages: paginationData.total_pages,
                    total_items: paginationData.total_items || 0 
                });
            },
            setFeatureEnable,setParentEnable,setIsLicenseConnect
        });
    };

    const handlePageChange = (newPage) => {
        setPagination(prev => ({ ...prev, page: newPage }));
        getBlackListIpRanges(newPage, pagination.limit);
        // Clear selections when changing pages
        setSelectedItems([]);
        setAllSelected(false);
    };

    const handlePageSizeChange = (newPageSize) => {
        setPagination(prev => ({ ...prev, limit: newPageSize, page: 0 }));
        getBlackListIpRanges(0, newPageSize);
        setSelectedItems([]);
        setAllSelected(false);
    };

    useEffect(() => {       
        getBlackListIpRanges();                    
    }, []);

    // Derived synchronously during render — no useEffect lag → no empty-table
    // flicker between "data arrived" and "filteredData computed".
    const filteredData = useMemo(() => {
        if (searchTerm.trim() === '') return blackListData;
        const q = searchTerm.toLowerCase();
        return blackListData.filter(item =>
            item.ip_range.toLowerCase().includes(q) ||
            item.block_type.toLowerCase().includes(q) ||
            item.reason.toLowerCase().includes(q)
        );
    }, [searchTerm, blackListData]);

    // Reset selections when the search query changes (side effect only).
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
            await handleDeleteIp({ setLoading, payload, getBlackList, getBlackListIpRanges: () => getBlackListIpRanges(pagination.page, pagination.limit) });
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
        const confirmed = await alertService.confirm("This can't be undone. ", "Are you sure to delete ? ");
        if (!confirmed) {
            return;
        }
        setIsDeleting(true);
        try {            
            const payload = { ip_ranges: selectedItems, is_delete: false };
            await handleDeleteIp({ setLoading, payload, getBlackList, getBlackListIpRanges: () => getBlackListIpRanges(pagination.page, pagination.limit) });
            setSelectedItems([]);
            setAllSelected(false);
        } catch (error) {
            console.error('Error deleting items:', error);
        } finally {
            setIsDeleting(false);
        }
    };

    const checkBlackListStatus = async () => {
        if (isChecking.current) return;
        isChecking.current = true;
        try {
            await refreshIpStatus();
            setParentEnable(null);
            setFeatureEnable(null);            
            setIsLicenseConnect(null);
            setBlackListData([]);
            await getBlackListIpRanges();            
        } finally {
            isChecking.current = false;
        }
    };

    const columns = [ ...(!widget ? [ <CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={filteredData.length === 0} />] : []), 'Country', 'IP Range', 'Block Type', 'Duration (min)', 'Created Time', 'Reason', 'Block', 'Edit'];
    const handleToggleUnblock = async (ipRange) => {
        await handleUnblockIp({ setLoading, payload: { ip_range: ipRange }, getBlackListIpRanges: () => getBlackListIpRanges(pagination.page, pagination.limit) });
    };

    const renderRow = (rowData) => {
        return (
            <BlackListData widget={widget} rowData={rowData} selectedItems={selectedItems} handleSelectItem={handleSelectItem} handleToggleUnblock={handleToggleUnblock} setEditingIpData={setEditingIpData} setShowModal={setShowModal} />
        );
    };

    return {
        selectedItems,featureEnable,setParentEnable,setLoading,parentEnable,ipManagement, handleDeletAll,checkBlackListStatus,handlePageSizeChange, handleBulkDelete, isDeleting, searchTerm, setSearchTerm, filteredData, handleAddToBlacklist, loading, columns, renderRow, pagination, handlePageChange, blackListData, showModal, handleCloseModal, getBlackListIpRanges,isLicenseConnect,setIsLicenseConnect, editingIpData
    }
}