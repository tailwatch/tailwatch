import { useState, useEffect, useMemo, useRef } from "react"
import { getWhitelistCountries, handleDeleteWhitelistCountry } from '../../../Pages/IpManagement/IpManagementServices/IpManagementServices';
import { WhiteListCountryData } from "../../../Pages/IpManagement/WhiteListContent/WhiteListCountry/WhiteListCountryData";
import { alertService } from "../../AlertService/AlertService";
import { CheckboxField } from "../../Fields/CheckboxField";
import { useIpFeature } from "../Features/UseFeatures";

export const useCountryWhitelist = (inititalLimit = 10,widget) => {
    const [whitelistCountries, setWhitlistCountries] = useState([]);
    const [loading, setLoading] = useState(true);
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedItems, setSelectedItems] = useState([]);
    const [allSelected, setAllSelected] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [editingCountryData, setEditingCountryData] = useState(null);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const {ipManagement,refreshIpStatus}= useIpFeature();
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);
    const [isLicenseConnect, setIsLicenseConnect] = useState(null);
    const isChecking = useRef(false);
    const handleOpenModal = () => {
        setIsModalOpen(true);
    };

    const handleCloseModal = () => {
        setIsModalOpen(false);
        setEditingCountryData(null);
    };
    // Pagination state
    const [pagination, setPagination] = useState({ page: 0, limit: inititalLimit, total_pages: 1,total_items: 0 });    

    const getWhitelistCountryData = async (page = pagination.page, limit = pagination.limit) => {
        await getWhitelistCountries({
            setLoading, setWhitlistCountries, page: page + 1, limit,setFeatureEnable,setParentEnable,setIsLicenseConnect,
            setPagination: (paginationData) => { setPagination({ page: paginationData.page - 1, limit: paginationData.limit, total_pages: paginationData.total_pages, total_items: paginationData.total_items || 0 }); }
        });
    };

    const handlePageChange = (newPage) => {
        setPagination(prev => ({ ...prev, page: newPage }));
        getWhitelistCountryData(newPage, pagination.limit);
        setSelectedItems([]);
        setAllSelected(false);
    };

    const handlePageSizeChange = (newPageSize) => {
        setPagination(prev => ({ ...prev, limit: newPageSize, page: 0 })); // Reset to first page
        getWhitelistCountryData(0, newPageSize);
        setSelectedItems([]);
        setAllSelected(false);
    };

    useEffect(() => {
        getWhitelistCountryData();
    }, []);

    const filteredData = useMemo(() => {
        if (searchTerm.trim() === '') return whitelistCountries;
        const q = searchTerm.toLowerCase();
        return whitelistCountries.filter(item =>
            item?.country_code?.toLowerCase().includes(q) ||
            item?.exemption?.toLowerCase().includes(q)
        );
    }, [searchTerm, whitelistCountries]);

    useEffect(() => {
        setSelectedItems([]);
        setAllSelected(false);
    }, [searchTerm]);

    const handleSelectItem = (countryCode) => {
        setSelectedItems(prev =>
            prev.includes(countryCode) ? prev.filter(item => item !== countryCode) : [...prev, countryCode]
        );
    };

    const handleSelectAll = async () => {
        if (allSelected) {
            setSelectedItems([]);
        } else {
            const allCountryCodes = filteredData.map(item => item.country_code);
            setSelectedItems(allCountryCodes);
        }
        setAllSelected(!allSelected);
    };

    useEffect(() => {
        const allCountryCodes = filteredData.map(item => item.country_code);
        const isAllSelected = allCountryCodes.length > 0 && allCountryCodes.every(country_code => selectedItems.includes(country_code));
        setAllSelected(isAllSelected);
    }, [selectedItems, filteredData]);

     const handleDeletAll = async () => {
        const confirmed = await alertService.confirm("This can't be undone. it removes all data from database", "Are you sure to delete all this data?");
        if (!confirmed) {
            return;
        }
        setIsDeleting(true);
        try {
            const payload = { country_codes: '', is_delete: true };
            await handleDeleteWhitelistCountry({ setLoading, payload, getWhitelistCountryData: () => getWhitelistCountryData(pagination.page, pagination.limit) });
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
            const payload = { country_codes: selectedItems, is_delete: false };
            await handleDeleteWhitelistCountry({ setLoading, payload, getWhitelistCountryData: () => getWhitelistCountryData(pagination.page, pagination.limit) });
            setSelectedItems([]);
            setAllSelected(false);
        } catch (error) {
            console.error('Error deleting items:', error);
        } finally {
            setIsDeleting(false);
        }
    };

    const checkWhiteCountryListStatus = async () => {
        if (isChecking.current) return;
        isChecking.current = true;
        try {
            await refreshIpStatus();  
            setParentEnable(null);
            setFeatureEnable(null);   
            setWhitlistCountries([]);
            await getWhitelistCountryData();            
        } finally {
            isChecking.current = false;
        }
    };

    const columns = [...(!widget ? [ <CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={filteredData.length === 0} />] : []), 'Country', 'Exemption', 'Edit'];
    const renderRow = (rowData) => {
        return (
            <WhiteListCountryData widget={widget} rowData={rowData} selectedItems={selectedItems} handleSelectItem={handleSelectItem} setEditingCountryData={setEditingCountryData} setShowModal={setIsModalOpen} />
        );
    };

    return {
        handleDeletAll,featureEnable,parentEnable,ipManagement,checkWhiteCountryListStatus, editingCountryData, setEditingCountryData, selectedItems, handleBulkDelete, isDeleting, searchTerm, setSearchTerm, filteredData, loading, columns,handlePageSizeChange, renderRow, pagination, handlePageChange, whitelistCountries, handleOpenModal, handleCloseModal, isModalOpen, getWhitelistCountryData,setIsLicenseConnect,isLicenseConnect,
    }
}