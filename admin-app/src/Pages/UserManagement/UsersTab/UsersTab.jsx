import Table from '../../../Components/Table/Table';
import Pagination from '../../../Components/Pagination/Pagination';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton';
import { UserRow } from '../UserManagementRows/UserManagemntTabs';
import UserRole from '../UserRole/UserRole';
import UserRestrictionModal from '../UserRole/UserRestrictionModal';
import CreateEditUserModal from '../UserRole/CreateEditUserModal';
import DeleteUserModal from '../UserRole/DeleteUserModal';
import BlockUserModal from '../UserRole/BlockUserModal';
import ActionButton from '../../../Components/Buttons/ActionButton';
import { useUsersTab } from '../../../Components/Hooks/useUserManagement/useUsersTab';
import TableControlStrip from '../../../Components/TableControlStrip/TableControlStrip';

const UsersTab = ({ loading, setLoading, widget = false }) => {
  const { navigate, searchTerm, setSearchTerm, paginatedData, filteredUserData, currentPage, totalFilteredPages, handlePageSizeChange, pagination, TwoFA, setTwoFaLoading,
    twoFaLoading, setTwoFA, handleUserRestrictionClick, handleRoleChangeClick, handleRoleChangeSubmit, handlePageClick, setUserData, fetchUserDataSet, selectedUser, setSelectedUser, showRoleChangePopup, setShowRoleChangePopup, roles, ShowUserRestrictionPopup,
    setShowUserRestrictionPopup, showCreateEditUserModal, setShowCreateEditUserModal, editUser, setEditUser, handleCreateUserClick, handleEditUserClick,
    handleDeleteUserClick, showDeleteUserModal, setShowDeleteUserModal, deleteTargetUser,
    handleBlockUserClick, handleUnblockUserClick, handleChangeToPermanentClick, showBlockUserModal, setShowBlockUserModal, blockTargetUser, userData,
    passwordLess, tabBlockSettingsState, checkUserMgmtStatus } = useUsersTab(setLoading, widget);

  const columnsForUser = ["User Name", "Email", "Role", "2FA", "Status", "Action"];

  if (loading) {
    return <LoaderSkeleton count={0} />;
  }

  const handleViewMore = () => {
    navigate('/dashboard/usermangement/users');
  };

  return (
    <div className='relative'>
      {widget && (
        <ActionButton defaultText="View More" onClick={handleViewMore} className="mb-4" />
      )}
      {!widget && (
        <TableControlStrip
          searchTerm={searchTerm}
          setSearchTerm={setSearchTerm}
          showDeleteButton={false}
          showControls={false}
          showAddButton={true}
          onAddButtonClick={handleCreateUserClick}
          addButtonLabel="Create User"
          showCanvas={true}
          featureId={passwordLess?.featureId}
          CheckStatus={checkUserMgmtStatus}
          isDisabled={tabBlockSettingsState?.disabled}
        />
      )}

      <Table columns={columnsForUser} data={paginatedData} noDataMessage="No users found"
        renderRow={(data) => (
          <UserRow user={data} twoFAuser={TwoFA.find((u) => u.id === data.id)} setTwoFaLoading={setTwoFaLoading} handleUserRestrictionClick={handleUserRestrictionClick} handleRoleChangeClick={handleRoleChangeClick} handleEditUserClick={handleEditUserClick} handleDeleteUserClick={handleDeleteUserClick} handleBlockUserClick={handleBlockUserClick} handleUnblockUserClick={handleUnblockUserClick} handleChangeToPermanentClick={handleChangeToPermanentClick} twoFaLoading={twoFaLoading} setTwoFA={setTwoFA} setUserData={setUserData} fetchUserDataSet={fetchUserDataSet} setLoading={setLoading} />
        )}
      />

      {!widget && filteredUserData.length > 0 && (
        <Pagination currentPage={currentPage} totalPages={totalFilteredPages} onPageChange={handlePageClick} hasData={filteredUserData.length > 0} showPageSizeFilter={true} pageSize={pagination.limit} onPageSizeChange={handlePageSizeChange} totalItems={searchTerm.trim() ? filteredUserData.length : pagination.total_items} pageSizeOptions={[5, 10, 20, 50, 100]} />
      )}

      {selectedUser && (
        <UserRole show={showRoleChangePopup} handleClose={() => setShowRoleChangePopup(false)} user={selectedUser} roles={roles} onSave={handleRoleChangeSubmit} />
      )}

      {selectedUser && (
        <UserRestrictionModal show={ShowUserRestrictionPopup} handleClose={() => { setShowUserRestrictionPopup(false); setSelectedUser(null); }} user={selectedUser} fetchUserDataSet={fetchUserDataSet} onSave={handleRoleChangeSubmit} />
      )}

      <CreateEditUserModal show={showCreateEditUserModal} handleClose={() => { setShowCreateEditUserModal(false); setEditUser(null); }} user={editUser} roles={roles} fetchUserDataSet={fetchUserDataSet} />

      <DeleteUserModal show={showDeleteUserModal} handleClose={() => { setShowDeleteUserModal(false); }} user={deleteTargetUser} fetchUserDataSet={fetchUserDataSet} />

      <BlockUserModal show={showBlockUserModal} handleClose={() => { setShowBlockUserModal(false); }} user={blockTargetUser} getData={fetchUserDataSet} />
    </div>
  );
};

export default UsersTab;
