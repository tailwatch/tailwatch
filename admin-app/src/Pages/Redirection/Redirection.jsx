import React, { useEffect, useState, useRef } from 'react';
import Header from '../../Components/Header/Header';
import PopUpModal from '../../Components/Modal/PopUpModal';
import { RedirectionFormContent } from './RedirectionFormContent/RedirectionFormContent';
import { redirectCodes, matchTypeOptions } from './RedirectionFormContent/FormData';
import IconButton from '../../Components/Buttons/IconButton';
import Table from '../../Components/Table/Table';
import { useRedirection } from '../../Components/Hooks/useRedirection/useRedirection';
import { Plus } from 'lucide-react';
import { renderRow } from './RedirectionFormContent/RedirectionRules';
import TableControlStrip from '../../Components/TableControlStrip/TableControlStrip';
import LoadingBar from 'react-top-loading-bar';
import LockCard from '../../Components/LockCard/LockCard';
import { useRedirectionFeature } from "../../Components/Hooks/Features/UseFeatures";
import InfoBar from '../../Components/InfoBar/InfoBar';
import Pagination from '../../Components/Pagination/Pagination';
import { CheckboxField } from '../../Components/Fields/CheckboxField';
import LoaderSkeleton from '../../Components/Skeleton/LoaderSkeleton';

const Redirection = () => {
    const { enableRedirectionRule, refreshRedirectionkStatus } = useRedirectionFeature();
    const [isBarLoading, setIsBarLoading] = useState(false);
    const [pendingFetch, setPendingFetch] = useState(false);
    const loadingBarRef = useRef(null);
    const [isUpdating, setIsUpdating] = useState(false);
    const isChecking = useRef(false);
    const { showAdvancedOptions, showRedirectModal, userRoles, isLoading, control, handlePostChange, handleSubmit, onSubmit, handleRoleSelection, preselectedValues, handleCloseModal,
        errors, showModal, formValues, setValue, setShowAdvancedOptions, showInclusionRules, setShowInclusionRules, fetchRedirectRules, handleSelectionChange, featureEnable, parentEnable, setFeatureEnable, setParentEnable,
        redirectRules, isEditMode, setIsEditMode, isLoadingRules, isDeleting, setSearchTerm, handleEdit, filteredRules, handleToggle, handleBulkDelete, handleRuleSelect, selectedRules, searchTerm,
        pagination, handlePageChange,handleDeletAll,allSelected,handlePageSizeChange,handleSelectAll } = useRedirection(setIsUpdating);

    const columns = [<CheckboxField checked={allSelected} onChange={handleSelectAll} disabled={filteredRules.length===0}/>, "Redirect", "Last Count", "Last Access", "Enabled", "Actions"];

    const checRedirectionStatus = async () => {
        if (isChecking.current) return;
        isChecking.current = true;
        try {
            await refreshRedirectionkStatus();
            setFeatureEnable(null);
            setParentEnable(null);
            await fetchRedirectRules();
        } finally {
            isChecking.current = false;
        }
    };

    useEffect(() => {
        if (isLoadingRules) setPendingFetch(false);

        if (isLoadingRules && !isBarLoading) {
            loadingBarRef.current.continuousStart();
            setIsBarLoading(true);
        } else if (!isLoadingRules && isBarLoading) {
            loadingBarRef.current.complete();
            setIsBarLoading(false);
        }
    }, [isLoadingRules, isBarLoading]);

    useEffect(() => {
        fetchRedirectRules();
    }, []);

    const handleRulesData = (rowData) => {
        return renderRow({
            rowData, selectedRules, handleRuleSelect, handleToggle, isUpdating, IconButton, handleEdit: (rule) => {
                setIsEditMode(true);
                handleEdit(rule);
            }
        });
    };

    return (
        <div className='relative overflow-x-hidden'>
            <LoadingBar ref={loadingBarRef} height={3} color="#ec5023" />
            <Header title="301 Redirection" />
            <div className="px-4 py-6">
                <div className=" min-h-[500px] relative">
                    <div>
                        {(pendingFetch || isLoadingRules) ? (
                            <LoaderSkeleton count='0' />
                        ) : (
                            <>
                                {(featureEnable === false || parentEnable === false) && (
                                    <InfoBar type="info" message="Please Configure your Settings First" />
                                )}
                                <TableControlStrip featureId={enableRedirectionRule?.featureId} isDisabled={parentEnable===false} CheckStatus={checRedirectionStatus} disbaled={filteredRules.length===0} showCanvas={true} handleDeleteAll={handleDeletAll} selectedFilesCount={selectedRules.length} handleBulkDelete={handleBulkDelete} isDeleting={isDeleting} searchTerm={searchTerm} setSearchTerm={setSearchTerm} hasEligibleFiles={redirectRules.length > 0} showAddButton={true} addButtonLabel="Add Rule" onAddButtonClick={showRedirectModal} onButtonDisable={featureEnable === false || parentEnable === false} />
                                <Table columns={columns} data={filteredRules || []} renderRow={handleRulesData} noDataMessage="No redirection rules found." />
                                <Pagination currentPage={pagination.page} totalPages={pagination.total_pages} onPageChange={handlePageChange} hasData={redirectRules.length > 0} pageSizeOptions={[5, 10, 20, 30, 50, 100]} totalItems={pagination.total_items} showPageSizeFilter={true} pageSize={pagination.limit} onPageSizeChange={handlePageSizeChange} />
                            </>
                        )}
                    </div>
                    {(parentEnable===false) && <LockCard featureName="301 Redirection" featureDescription="Automatically redirects old URLs to new ones using 301 status codes to preserve SEO and prevent broken links." featureId={enableRedirectionRule?.featureId} isActive={enableRedirectionRule?.isActiveState} afterToggleCallback={checRedirectionStatus} showOverlay={false}/>}
                </div>
            </div>
            {showModal && (
                <PopUpModal disabled={isLoading} isLoading={isLoading} title={isEditMode ? "Edit redirect" : "Add a redirect"} onClose={handleCloseModal} onSave={handleSubmit(onSubmit)} saveButtonText={isEditMode ? "Update this redirect!" : "Add this redirect!"} cancelButtonText="Cancel" width="w-full max-w-4xl" height="max-h-[90vh]" showExpandIcon={true}>
                    <form onSubmit={handleSubmit(onSubmit)} className="space-y-6">
                        <RedirectionFormContent control={control} errors={errors} formValues={formValues} isLoading={isLoading} userRoles={userRoles} setValue={setValue} handleRoleSelection={handleRoleSelection} handlePostChange={handlePostChange} showAdvancedOptions={showAdvancedOptions} setShowAdvancedOptions={setShowAdvancedOptions} showInclusionRules={showInclusionRules} setShowInclusionRules={setShowInclusionRules} redirectCodes={redirectCodes} matchTypeOptions={matchTypeOptions} initialPostType={preselectedValues.postType} initialPostId={preselectedValues.postId} initialCustomUrl={preselectedValues.customUrl} handleSelectionChange={handleSelectionChange} />
                    </form>
                </PopUpModal>
            )}
        </div>
    );
};

export default Redirection;