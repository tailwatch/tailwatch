import { useState } from 'react';
import Table from '../../../Components/Table/Table';
import Pagination from '../../../Components/Pagination/Pagination';
import LoaderSkeleton from '../../../Components/Skeleton/LoaderSkeleton';
import ActionButton from '../../../Components/Buttons/ActionButton';
import PopUpModal from '../../../Components/Modal/PopUpModal';
import CreatePassLessLoginForm from './CreatePassLessLoginForm';
import { Spinner } from '../../../Components/Spinner/Spinner';
import { PasswordLessLoginData } from './PasswordLessLoginData';
import { usePasswordLess } from '../../../Components/Hooks/useUserManagement/usePasswordLess';
import TableControlStrip from '../../../Components/TableControlStrip/TableControlStrip';
import InfoBar from '../../../Components/InfoBar/InfoBar';
import LockCard from '../../../Components/LockCard/LockCard';

const PasswordLessLogin = ({ loading, setLoading, widget = false, limit = 10 }) => {
  const { navigate, handleCreateNew, handleSelectItem,checkPasswordLessStatus,passwordLess, handleBulkDelete, columnsForTempLogin, tempLoginData, onRenew, onEdit, currentPage, pagination, handlePageChange, handlePageSizeChange, isModalOpen, selectedUser, handleModalClose, isFetchingUser, isEditMode, tempRoles, tempLang, tempSlug, fetchUserTempData, searchQuery, setSearchQuery, filteredData, selectedItems, setSelectedItems, isDeleting, handleDeletAll,featureEnable,parentEnable,isLicenseConnect,islicenseReq } = usePasswordLess(setLoading, limit, widget);
  const [isSubmitting, setIsSubmitting] = useState(false);  
  if (loading) {
    return <LoaderSkeleton count={0} />;
  }  
  const handleViewMore = () => {
    navigate('/dashboard/usermangement/passwordless');
  };

  const handleSave = () => {    
    const formElement = document.getElementById('passwordless-form');
    if (formElement) {
      formElement.requestSubmit();
    }
  };

  return (
    <div className="relative">
      {(featureEnable === false || parentEnable === false || isLicenseConnect === true)  && (<InfoBar type="info" message={isLicenseConnect ? "Connect to Tailwatch to use this feature." : "Please Configure your Settings First"}/>)}
      {(islicenseReq?.code === 402)  && (<InfoBar type="info" message={islicenseReq?.message}/>)}
      <div className="flex justify-between items-center pb-4 border-gray-200">        
        {widget && (
          <ActionButton defaultText="View More" onClick={handleViewMore} />
        )}
      </div>

      {!widget && (
        <TableControlStrip featureId={passwordLess?.featureId} isDisabled={parentEnable===false || islicenseReq?.code === 402} CheckStatus={checkPasswordLessStatus} disbaled={filteredData.length === 0} showCanvas={true} searchTerm={searchQuery} handleBulkDelete={handleBulkDelete} selectedFilesCount={selectedItems.length} setSearchTerm={setSearchQuery} isDeleting={isDeleting} handleDeleteAll={handleDeletAll} selectedCount={selectedItems.length} totalCount={tempLoginData.length} showAddButton={true} onAddButtonClick={handleCreateNew} addButtonLabel="Create New" onButtonDisable={parentEnable===false || featureEnable === false || islicenseReq?.code === 402} />
      )}

      <Table columns={columnsForTempLogin}
        data={filteredData}
        renderRow={(data) => (
          <PasswordLessLoginData widget={widget} selectedItems={selectedItems} handleSelectItem={handleSelectItem} tempUser={data} onRenew={onRenew} onEdit={onEdit} loading={loading}
          onSelect={(id) => {
            setSelectedItems(prev => prev.includes(id) ? prev.filter(item => item !== id) : [...prev, id]);
            }}
            isSelected={selectedItems.includes(data.id)}
          />
        )}
        noDataMessage="No temporary logins available."
      />

      {!widget && filteredData.length > 0 && (
        <Pagination currentPage={currentPage} totalPages={pagination.total_pages} onPageChange={handlePageChange} hasData={filteredData.length > 0} showPageSizeFilter={true} pageSize={pagination.limit} onPageSizeChange={handlePageSizeChange} totalItems={pagination.total_items} pageSizeOptions={[5, 10, 20, 50, 100]} />
      )}
      {(!widget && (parentEnable === false || isLicenseConnect === true) ) && <LockCard featureName="Passwordless Login" featureDescription="Enable the ability to generate secure, time-limited login links that do not require a username or password." featureId={passwordLess?.featureId} isActive={passwordLess?.isActiveState} isLocked={isLicenseConnect} afterToggleCallback={checkPasswordLessStatus} showOverlay={false} />}

      {isModalOpen && (
        <PopUpModal title={isEditMode ? "Edit Temporary Login" : "Create Temporary Login"} isLoading={isSubmitting} disabled={isFetchingUser} onSave={handleSave} cancelButtonText="Cancel" saveButtonText={isEditMode && !isFetchingUser ? "Update" : "Save"} onClose={handleModalClose} width="w-[55rem]" showCloseIcon={true} >
          {isFetchingUser ? (
            <div className="flex flex-col justify-center items-center h-64">
              <Spinner />
              <span className="mt-2">loading...</span>
            </div>
          ) : (
            <CreatePassLessLoginForm roles={tempRoles} setIsSubmitting={setIsSubmitting} languages={tempLang} redirectSlugs={tempSlug} fetchUserTempData={() => fetchUserTempData(currentPage, pagination.limit)} initialUser={selectedUser} onClose={handleModalClose} />
          )}
        </PopUpModal>
      )}
    </div>
  );
};
export default PasswordLessLogin;