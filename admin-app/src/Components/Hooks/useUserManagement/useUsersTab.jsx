import { useState, useEffect, useMemo } from 'react';
import { fetchUserData, fetchRoles, userRoleChangeSubmit, unblockUser, blockUser } from '../../../Pages/UserManagement/MangementServices/ManagementServices';
import { useNavigate } from 'react-router-dom';
import { alertService } from '../../AlertService/AlertService';
import { usePasswordLessFeature } from '../Features/UseFeatures';

export const useUsersTab = (setLoading, widget) => {
    // User-management settings are stored under the same feature key
    // (advanced_user_access_control) that drives the PasswordLessLogin tab,
    // so the Change Settings button on the Users tab opens the same canvas.
    const { passwordLess, refreshPasswordLessStatus } = usePasswordLessFeature();

    const [userData, setUserData] = useState([]);
    const [roles, setRoles] = useState([]);
    const [TwoFA, setTwoFA] = useState([]);
    const [currentPage, setCurrentPage] = useState(0);
    const [twoFaLoading, setTwoFaLoading] = useState({});
    const [selectedUser, setSelectedUser] = useState(null);
    const [showRoleChangePopup, setShowRoleChangePopup] = useState(false);
    const [ShowUserRestrictionPopup, setShowUserRestrictionPopup] = useState(false);
    const [showCreateEditUserModal, setShowCreateEditUserModal] = useState(false);
    const [editUser, setEditUser] = useState(null);
    const [showDeleteUserModal, setShowDeleteUserModal] = useState(false);
    const [deleteTargetUser, setDeleteTargetUser] = useState(null);
    const [showBlockUserModal, setShowBlockUserModal] = useState(false);
    const [blockTargetUser, setBlockTargetUser] = useState(null);
    const [searchTerm, setSearchTerm] = useState('');
    const [pagination, setPagination] = useState({ page: 1, limit: widget ? 5 : 10, total_pages: 0, total_items: 0 });

    const navigate = useNavigate();

    const filteredUserData = useMemo(() => {
        if (!searchTerm.trim()) return userData;
        const searchLower = searchTerm.toLowerCase().trim();
        return userData.filter(user => {
            const userName = typeof user.name === 'string' ? user.name : (user.name?.first_name && user.name?.last_name) ? `${user.name.first_name} ${user.name.last_name}` : user.username || '';
            const email = user.email || '';
            const status = user.status || '';
            return userName.toLowerCase().includes(searchLower) || email.toLowerCase().includes(searchLower) || status.toLowerCase().includes(searchLower);
        });
    }, [userData, searchTerm]);

    const paginatedData = useMemo(() => {
        if (searchTerm.trim()) {
            const startIndex = currentPage * pagination.limit;
            return filteredUserData.slice(startIndex, startIndex + pagination.limit);
        }
        return filteredUserData;
    }, [filteredUserData, currentPage, pagination.limit, searchTerm]);

    const totalFilteredPages = useMemo(() => {
        if (searchTerm.trim()) return Math.ceil(filteredUserData.length / pagination.limit);
        return pagination.total_pages || 1;
    }, [filteredUserData.length, pagination.limit, pagination.total_pages, searchTerm]);

    useEffect(() => {
        fetchUserDataSet();
    }, [pagination.page, pagination.limit]);

    useEffect(() => {
        setCurrentPage(0);
    }, [searchTerm]);

    const fetchUserDataSet = async () => {
        setLoading(true);
        try {
            await fetchRoles({ setRoles });
            await fetchUserData({ setUserData, setLoading, setPagination, page: pagination.page, limit: pagination.limit });
        } catch (err) {
        } finally {
            setLoading(false);
        }
    };

    // Tab-level state for the Change Settings button. License / pro-plugin /
    // parent-feature flags inside user_status are tenant-level (same across
    // all rows), so we can derive the gate from any user's payload.
    // Mirrors the gating used by the per-user menu items in UserRow.
    const tabBlockSettingsState = useMemo(() => {
        const sample = userData?.[0]?.user_status;
        if (!sample) return { disabled: false, tooltip: '' };
        if (sample.is_upgrade_feature === true) {
            return { disabled: true, tooltip: 'Upgrade now' };
        }
        if (sample.license_connected === false) {
            return { disabled: true, tooltip: 'Connect to Tailwatch' };
        }
        if (sample.pro_plugin_active === false) {
            return { disabled: true, tooltip: 'This feature requires a Pro Plugin.' };
        }
        // Mirror the LockCard gate on Passwordless Login: when the feature itself
        // isn't active (isActiveState falsy) the settings canvas has nothing to
        // configure, so disable the Change Settings entry too.
        const isPasswordLessActive = passwordLess?.isActiveState && passwordLess.isActiveState !== '0';
        if (passwordLess && !isPasswordLessActive) {
            return { disabled: true, tooltip: 'Enable User Management feature' };
        }
        return { disabled: false, tooltip: '' };
    }, [userData, passwordLess]);

    // Refresh callback wired to the Change Settings canvas close — pulls fresh
    // feature data from localStorage AND re-fetches the user list so the row
    // gates reflect any setting changes the user just made.
    const checkUserMgmtStatus = async () => {
        await refreshPasswordLessStatus?.();
        await fetchUserDataSet();
    };

    const handlePageClick = (selectedPage) => {
        setCurrentPage(selectedPage);
        if (!searchTerm.trim()) {
            setPagination(prev => ({ ...prev, page: selectedPage + 1 }));
        }
    };

    const handlePageSizeChange = (newPageSize) => {
        setPagination(prev => ({ ...prev, limit: newPageSize, page: 1 }));
        setCurrentPage(0);
    };

    const handleRoleChangeClick = (user) => {
        setSelectedUser(user);
        setShowRoleChangePopup(true);
    };

    const handleUserRestrictionClick = (user) => {
        setSelectedUser(user);
        setShowUserRestrictionPopup(true);
    };

    const handleCreateUserClick = () => {
        setEditUser(null);
        setShowCreateEditUserModal(true);
    };

    const handleEditUserClick = (user) => {
        setEditUser(user);
        setShowCreateEditUserModal(true);
    };

    const handleDeleteUserClick = (user) => {
        setDeleteTargetUser(user);
        setShowDeleteUserModal(true);
    };

    const handleBlockUserClick = (user) => {
        setBlockTargetUser(user);
        setShowBlockUserModal(true);
    };

    const handleUnblockUserClick = async (user) => {
        const confirmed = await alertService.confirm(
            `This will immediately restore login access for "${user.username}".`,
            'Unblock this user?',
            'Unblock',
            'Cancel'
        );
        if (!confirmed) return;
        await unblockUser({
            payload: { user_id: user.id },
            setLoading,
            getData: fetchUserDataSet,
        });
    };

    const handleChangeToPermanentClick = async (user) => {
        const confirmed = await alertService.confirm(
            `"${user.username}" will be permanently blocked from logging in until you manually unblock them.`,
            'Change this block to permanent?',
            'Yes',
            'Cancel'
        );
        if (!confirmed) return;
        await blockUser({
            payload: {
                user_id: user.id,
                block_type: 'permanent',
                duration: 0,
                reason: user.user_status?.data?.reason || '',
            },
            setLoading,
            getData: fetchUserDataSet,
        });
    };

    const handleRoleChangeSubmit = async (userId, newRole) => {
        const result = await userRoleChangeSubmit(userId, newRole, setUserData, setShowRoleChangePopup);
        if (result) fetchUserDataSet();
        return result;
    };

    return {
        navigate,
        searchTerm,
        setSearchTerm,
        paginatedData,
        filteredUserData,
        currentPage,
        totalFilteredPages,
        handlePageSizeChange,
        pagination,
        TwoFA,
        setTwoFaLoading,
        twoFaLoading,
        setTwoFA,
        handleUserRestrictionClick,
        handleRoleChangeClick,
        handleRoleChangeSubmit,
        handlePageClick,
        setUserData,
        fetchUserDataSet,
        selectedUser,
        setSelectedUser,
        showRoleChangePopup,
        setShowRoleChangePopup,
        roles,
        ShowUserRestrictionPopup,
        setShowUserRestrictionPopup,
        showCreateEditUserModal,
        setShowCreateEditUserModal,
        editUser,
        setEditUser,
        handleCreateUserClick,
        handleEditUserClick,
        handleDeleteUserClick,
        showDeleteUserModal,
        setShowDeleteUserModal,
        deleteTargetUser,
        handleBlockUserClick,
        handleUnblockUserClick,
        handleChangeToPermanentClick,
        showBlockUserModal,
        setShowBlockUserModal,
        blockTargetUser,
        userData,
        passwordLess,
        tabBlockSettingsState,
        checkUserMgmtStatus,
    };
};
