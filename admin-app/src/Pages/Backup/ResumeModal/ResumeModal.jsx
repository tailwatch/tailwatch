import React, { useState } from "react";
import axios from "axios";
import Modal from "../../../Components/Modal/Modal";
import { onResumeBackup } from "../BackupServices/BackupServices";
/* global wptw_ajax */

const ResumeModal = ({
  setOperationLoading,
  isOpen,
  onClose,
  fetchLogs,
  setIsScanInProgress,
  setLogs,
  setIsCompleted,  
  setProgress,  
  setIsOperationInProgress,    
  isComponentActive,
  setIsLogsVisible,
  setRenderKey,
  checkBackupStatus,
  setIsFetchingLogs,
  setRenderBackupDetail,
  setErrorMessages,
  setIsPaused,
  setIsCanceled,
  handleInstantScan,
  isLastLogIndex,
  setIsLastLogIndex,
}) => {
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isResuming, setIsResuming] = useState(false);
  const [isRestarting, setIsRestarting] = useState(false);

  if (!isOpen) return null;


    const handleResumeBackup = async () => {
    setLogs([]);
    setIsResuming(true);
    setIsSubmitting(true);
    setIsOperationInProgress(true);
    try {
      await onResumeBackup({
        setIsScanInProgress,
        setIsFetchingLogs,
        checkBackupStatus,
        fetchLogs,
        setLogs,
        setIsCompleted,
        isComponentActive,
        setProgress,
        setIsLogsVisible,
        setRenderKey,
        setRenderBackupDetail,
        setErrorMessages,
        setIsPaused,
        setIsCanceled,
        setOperationLoading,
        isLastLogIndex,
        setIsLastLogIndex,
      });
    } catch (error) {
      setErrorMessages((prevErrors) => [...prevErrors, error]);
      setIsScanInProgress(false);
      setIsLogsVisible(false);
    } finally {
      setIsResuming(false);
      setIsSubmitting(false);
      setRenderBackupDetail((prevKey) => prevKey + 1);
      setRenderKey((prevKey) => prevKey + 1);
      onClose();
    }
  };
 


  const handleRestartBackup = async () => {
    setLogs([]);
    setIsRestarting(true);
    setIsSubmitting(true);
    setIsOperationInProgress(true); 
    try {
      await handleInstantScan(); 
    } catch (error) {
      console.error("Error during restart:", error);
      setErrorMessages((prevErrors) => [...prevErrors, "Failed to restart the backup"]);
    } finally {
      setIsScanInProgress(true);
      setIsRestarting(false);
      setIsSubmitting(false);
      onClose();
    }
  };

  return (
    <Modal
    isOpen={isOpen}
    onClose={onClose}
    title="Resume Backup"
    message="Do you want to resume or Restart Backup ?"
    onConfirm={handleResumeBackup}
    onCancel={handleRestartBackup}
    confirmLabel={isResuming ? 'Processing...' : 'Resume process'}
    cancelLabel={isRestarting ? 'Processing...' : 'Restart process'}
    isSubmitting={isSubmitting}
    
  />
  );
};

export default ResumeModal;
