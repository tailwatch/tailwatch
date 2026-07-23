import { useState, useEffect, useMemo, useRef } from "react"
import { getBlockCountries, handleDeleteCountry, handleUnblockCountry } from '../../../Pages/IpManagement/IpManagementServices/IpManagementServices';
import { CountryListData } from "../../../Pages/IpManagement/CountryList/CountryListModal/CountryLIstData";
import { alertService } from "../../AlertService/AlertService";
import { CheckboxField } from "../../Fields/CheckboxField";
import { useIpFeature } from "../Features/UseFeatures";
export const useCountryList = (initialLimit = 10,widget) => {
    const [blockCountries, setBlockCountriesData] = useState([]);
    const [loading, setLoading] = useState(true);
    const [searchTerm, setSearchTerm] = useState('');
    const [selectedItems, setSelectedItems] = useState([]);
    const [allSelected, setAllSelected] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [editingCountryData, setEditingCountryData] = useState(null);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const {ipManagement,refreshIpStatus} = useIpFeature();
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
    const [pagination, setPagination] = useState({ page: 0, limit: initialLimit, total_pages: 1,total_items: 0 });    

    const getBlockCountriesData = async (page = pagination.page, limit = pagination.limit) => {
        await getBlockCountries({
            setLoading, setBlockCountriesData, page: page + 1, limit,setFeatureEnable,setParentEnable,setIsLicenseConnect,
            setPagination: (paginationData) => { setPagination({ page: paginationData.page - 1, limit: paginationData.limit, total_pages: paginationData.total_pages, total_items: paginationData.total_items || 0 }); }
        });
    };

    const handlePageChange = (newPage) => {
        setPagination(prev => ({ ...prev, page: newPage }));
        getBlockCountriesData(newPage, pagination.limit);
        setSelectedItems([]);
        setAllSelected(false);
    };
    const handlePageSizeChange = (newPageSize) => {
        setPagination(prev => ({ ...prev, limit: newPageSize, page: 0 })); // Reset to first page
        getBlockCountriesData(0, newPageSize);
        setSelectedItems([]);
        setAllSelected(false);
    };
    useEffect(() => {
        getBlockCountriesData();
    }, []);

    const filteredData = useMemo(() => {
        if (searchTerm.trim() === '') return blockCountries;
        const q = searchTerm.toLowerCase();
        return blockCountries.filter(item =>
            item?.country_code?.toLowerCase().includes(q) ||
            item?.block_type?.toLowerCase().includes(q) ||
            item?.reason?.toLowerCase().includes(q)
        );
    }, [searchTerm, blockCountries]);

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
            await handleDeleteCountry({ setLoading, payload, getBlockCountriesData: () => getBlockCountriesData(pagination.page, pagination.limit) });
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
        const confirmed = await alertService.confirm("This can't be undone.", "Are you sure to delete all this data?");
        if (!confirmed) {
            return;
        }
        setIsDeleting(true);
        try {
            const payload = { country_codes: selectedItems, is_delete: false };
            await handleDeleteCountry({ setLoading, payload, getBlockCountriesData: () => getBlockCountriesData(pagination.page, pagination.limit) });
            setSelectedItems([]);
            setAllSelected(false);
        } catch (error) {
            console.error('Error deleting items:', error);
        } finally {
            setIsDeleting(false);
        }
    };

    const handleToggleUnblock = async (country_code) => {
        await handleUnblockCountry({ setLoading, payload: { country_code: country_code }, getBlockCountriesData: () => getBlockCountriesData(pagination.page, pagination.limit) });
    };

    const checkCountryListStatus = async () => {
        if (isChecking.current) return;
        isChecking.current = true;
        try {
            await refreshIpStatus();    
            setParentEnable(null);
            setFeatureEnable(null);        
            setBlockCountriesData([]);
            await getBlockCountriesData();            
        } finally {
            isChecking.current = false;
        }
    };

    const columns = [...(!widget ? [ <CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={filteredData.length === 0} />] : []), 'Country', 'Block Type', 'Duration (min)', 'Created Time', 'Reason', 'Scope', 'Block', 'Edit'];
    const renderRow = (rowData) => {
        return (
            <CountryListData widget={widget} rowData={rowData} selectedItems={selectedItems} handleSelectItem={handleSelectItem} handleToggleUnblock={handleToggleUnblock} setEditingCountryData={setEditingCountryData} setShowModal={setIsModalOpen} />
        );
    };

    return {
        handleDeletAll,featureEnable,parentEnable,ipManagement,checkCountryListStatus, editingCountryData, setEditingCountryData, selectedItems, handleBulkDelete, isDeleting, searchTerm, setSearchTerm, filteredData, loading, columns, renderRow, pagination, handlePageChange, blockCountries, handleOpenModal, handleCloseModal, isModalOpen,handlePageSizeChange, getBlockCountriesData,isLicenseConnect,
    }
}