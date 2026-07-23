import React, { useState, useEffect, useMemo,useRef } from 'react';
import { fetchTempLoginData, tempUserRoles, manageLoginStatus, deleteTempUsers, getTemporaryUsers } from '../../../Pages/UserManagement/MangementServices/ManagementServices';
import { toast } from 'react-toastify';
import { alertService } from '../../AlertService/AlertService';
import { useNavigate } from 'react-router-dom';
import { CheckboxField } from '../../Fields/CheckboxField';
import { usePasswordLessFeature } from '../Features/UseFeatures';
export const usePasswordLess = (setLoading, limit, widget,setIsFetching) => {
    const {passwordLess,refreshPasswordLessStatus}=usePasswordLessFeature();;
    const [tempLoginData, setTempLoginData] = useState([]);
    const [currentPage, setCurrentPage] = useState(0);
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [tempRoles, setTempRoles] = useState({});
    const [tempLang, setTempLang] = useState({});
    const [tempSlug, setTempSlug] = useState({});
    const [selectedUser, setSelectedUser] = useState(null);
    const [isFetchingUser, setIsFetchingUser] = useState(false);
    const [isEditMode, setIsEditMode] = useState(false);
    const [pagination, setPagination] = useState({ page: 1, limit: limit, total_pages: 0, total_items: 0 });
    const [searchQuery, setSearchQuery] = useState('');
    const [selectedItems, setSelectedItems] = useState([]);
    const [allSelected, setAllSelected] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);
    const [isLicenseConnect,setIsLicenseConnect] = useState(null);
    const [islicenseReq,setIsLicenseReq] = useState(null);
    const isChecking = useRef(false);
    const navigate = useNavigate();
   
   
  const fetchUserTempData = async (page = 0, pageSize = limit) => {
    const apiPage = page + 1;

    setIsFetching?.(true); // 👈 start (safety)

    try {
        await fetchTempLoginData({
            setTempLoginData,
            setLoading,
            setFeatureEnable, 
            setParentEnable, 
            setIsLicenseConnect,
            setIsLicenseReq,
            setPagination: (paginationData) => {
                setPagination({
                    page: paginationData.page,
                    limit: paginationData.limit,
                    total_pages: paginationData.total_pages,
                    total_items: paginationData.total_items
                });
            },
            page: apiPage,
            limit: pageSize
        });
    } catch (e) {
        console.error(e);
    } finally {
        setIsFetching?.(false); // ✅ REAL END → NO flicker
    }
};

    useEffect(() => {
        fetchUserTempData(currentPage, pagination.limit);
    }, []);

    const handlePageChange = (newPage) => {
        setCurrentPage(newPage);
        fetchUserTempData(newPage, pagination.limit);
    };

    const handlePageSizeChange = (newPageSize) => {
        setPagination(prev => ({ ...prev, limit: newPageSize }));
        setCurrentPage(0); // Reset to first page
        fetchUserTempData(0, newPageSize);
    };

    const handleCreateNew = async () => {
        setIsEditMode(false);
        setIsFetchingUser(true);
        setIsModalOpen(true);

        await tempUserRoles({ setTempRoles, setTempLang, setTempSlug, setLoading });

        setIsFetchingUser(false);
    };

    const onRenew = async (tempUser) => {
    const action = tempUser.is_expired ? "enable" : "disable";
    const message = `Are you sure you want to ${action} the user <strong>${tempUser.username}</strong>?`;
    const title = tempUser.is_expired ? "Enable User" : "Disable User";

    const result = await alertService.manageLoginPopup(message, title, "OK", "Cancel", tempUser.is_expired);
    if (result) {
        setLoading(true);
        const payload = {
            user_id: tempUser.user_id,
            enable: tempUser.is_expired,
            reset_login_count: result.reset_login_count,  // ✅ always included (true/false)
        };

        if (tempUser.is_expired) {
            payload.expiry_time = result.expiry_time;     // ✅ read from object
        }

        try {
            const response = await manageLoginStatus(payload);
            if (response.success) {
                toast.success(`User ${action}d successfully!`, 'success');
            } else {
                toast.error(response.message || `Failed to ${action} user`, 'error');
            }
        } catch (error) {
        } finally {
            fetchUserTempData(currentPage, pagination.limit);
        }
    }
};

    const handleDeletAll = async () => {
        const confirmed = await alertService.confirm("This can't be undone. it removes all data from database", "Are you sure to delete all this data?");
        if (!confirmed) {
            return;
        }
        setIsDeleting(true);
        try {
            const payload = { user_ids: '', is_delete: true };
            await deleteTempUsers({ setIsDeleting, payload, fetchUserTempData: () => fetchUserTempData(currentPage, pagination.limit) });
            setSelectedItems([]);
            setAllSelected(false);
        } catch (error) {
            console.error('Error deleting items:', error);
        } finally {
            setIsDeleting(false);
        }
    }

    const handleSelectItem = (userId) => {
        setSelectedItems(prev => {
            const newSelectedItems = prev.includes(userId) ? prev.filter(item => item !== userId) : [...prev, userId];
                        
            setAllSelected(newSelectedItems.length === filteredData.length && filteredData.length > 0);
            
            return newSelectedItems;
        });
    };
    
    const handleBulkDelete = async () => {
        if (selectedItems.length === 0) return;

        const confirmed = await alertService.confirm(
            "This can't be undone. ",
            "Are you sure to delete these ?"
        );

        if (!confirmed) {
            return;
        }
        setIsDeleting(true);
        try {
            const payload = { user_ids: selectedItems, is_delete: false };
            await deleteTempUsers({ setIsDeleting, payload, fetchUserTempData: () => fetchUserTempData(currentPage, pagination.limit) });
            setSelectedItems([]);
            setAllSelected(false);           
        } catch (error) {
            console.error('Error deleting selected users:', error);
        } finally {
            setIsDeleting(false);
        }
    };

    const onEdit = async (tempUser) => {
        setIsEditMode(true);
        setIsFetchingUser(true);
        setIsModalOpen(true);

        try {
            // Fetch user data
            const response = await getTemporaryUsers({ user_id: tempUser.user_id });
            const userData = response;

            setSelectedUser({
                user_id: tempUser.user_id,
                email: userData.email,
                first_name: userData.first_name,
                last_name: userData.last_name,
                role: userData.role,
                redirect_to: userData.redirect_to,
                locale: userData.locale,
                expiry_time: userData.expiry_time,
                max_login_limit: userData.max_login_limit,
            });

            await tempUserRoles({ setTempRoles, setTempLang, setTempSlug, setLoading });
            setIsFetchingUser(false);
        } catch (error) {
            console.error('Error fetching user data:', error);
            setIsModalOpen(false);
            setIsFetchingUser(false);
        }
    };

    const filteredData = useMemo(() => {
        if (!searchQuery.trim()) return tempLoginData;
        return tempLoginData.filter(item =>
            Object.values(item).some(value =>
                String(value).toLowerCase().includes(searchQuery.toLowerCase())
            )
        );
    }, [tempLoginData, searchQuery]);

    const handleSelectAll = () => {
        if (allSelected) {
            setSelectedItems([]);
        } else {
            const allIds = filteredData.map(item => item.user_id);
            setSelectedItems(allIds);
        }
        setAllSelected(!allSelected);
    };

    const columnsForTempLogin = [
        ...(!widget ? [<CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={filteredData.length === 0} />] : []), "Users", "Role", "Last Login", "Count", "Expiry", "Actions"
    ];

    const handleModalClose = () => {
        setIsModalOpen(false);
        setSelectedUser(null);
        setIsFetchingUser(false);
        setIsEditMode(false);
    };
    const checkPasswordLessStatus = async () => {
        if (isChecking.current) return;
        isChecking.current = true;
        try {
            await refreshPasswordLessStatus();
            setFeatureEnable(null);
            setParentEnable(null);
            await fetchUserTempData();
        } finally {
            isChecking.current = false;
        }
    };
    return { navigate,checkPasswordLessStatus,passwordLess, handleDeletAll,handleSelectItem, handleBulkDelete,featureEnable,parentEnable, handleCreateNew, isDeleting, columnsForTempLogin, tempLoginData, onRenew, onEdit, currentPage, pagination, handlePageChange, handlePageSizeChange, isModalOpen, selectedUser, handleModalClose, isFetchingUser, isEditMode, tempRoles, tempLang, tempSlug, fetchUserTempData, searchQuery, setSearchQuery, filteredData, selectedItems, setSelectedItems,isLicenseConnect,islicenseReq };
}