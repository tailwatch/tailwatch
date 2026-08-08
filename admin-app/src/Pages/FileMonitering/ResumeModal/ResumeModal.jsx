import React, { useState } from "react";
import axios from "axios";
import Modal from "../../../Components/Modal/Modal";
import { toast } from 'react-toastify' ;
/* global tailwatch_ajax */

const ResumeModal = ({
  setOperationLoading,
  isOpen,
  onClose,
  fetchLogs,
  setIsScanInProgress,
  setLogs,
  setIsCompleted,
  setProgress,
  handleCancel,
  isCanceled,
  setIsOperationInProgress,
  isComponentActive,
  setIsLogsVisible,
  setIsFetchingLogs,
  checkMonitoringStatus,
  setRenderKey,
  setTrigerMoniteringDetails,
  setIsPaused,
  setIsCanceled,
  isLastLogIndex,
  setIsLastLogIndex,
}) => {
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isResuming, setIsResuming] = useState(false);

  if (!isOpen) return null;

  const onResume = async () => {
    setIsResuming(true);
    setIsSubmitting(true);
    try {
      const formData = new FormData();
      formData.append("action", "tailwatch_global_ajax_handler");
      formData.append("action_type", "tailwatch_resume_files_integrity");
      formData.append("nonce", tailwatch_ajax.nonce);

      const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
        headers: { "Content-Type": "multipart/form-data" },
      });

      if (response.data.success) {
        setIsScanInProgress(true);
        setIsFetchingLogs(true);
        fetchLogs(setLogs, setIsCompleted, true, isComponentActive, setIsScanInProgress, setIsLogsVisible, setProgress, setRenderKey, setTrigerMoniteringDetails, setIsPaused, setIsCanceled, setOperationLoading, isLastLogIndex, setIsLastLogIndex);
      } else {
        toast.error('Failed to resume ');
        console.error("Failed to resume integrity", response.data);
      }
    } catch (error) {
    } finally {      
      setIsSubmitting(false);
    }
  };
  const handleResume = async () => {
    setIsSubmitting(true);
    setLogs([]);
    setIsOperationInProgress(true);

    try {            
      await onResume();
      await checkMonitoringStatus();
      onClose();
    } catch (error) {
    } finally {
      setIsResuming(false);
      setIsSubmitting(false);
    }
  };
  const handleCancelIntegrity = async () => {
    await handleCancel();
    onClose();
  }
  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Resume Process"
      message="Do you want to Resume or Cancel File Integrity ?"
      onConfirm={handleResume}
      onCancel={handleCancelIntegrity}
      confirmLabel={isResuming ? 'Processing...' : 'Resume process'}
      cancelLabel={isCanceled ? 'Processing...' : 'Cancel process'}
      isSubmitting={isSubmitting}
      isProcessing={isResuming || isCanceled}
    />
  );
};

export default ResumeModal;
