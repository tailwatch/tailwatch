import React, { useState } from "react";
import axios from "axios";
import Modal from "../../../Components/Modal/Modal";
import { toast } from 'react-toastify';
/* global tailwatch_ajax */

const ResumeModal = ({
  isOpen,
  onClose,
  fetchLogs,
  setIsScanInProgress,
  setLogs,
  setIsCompleted,
  setProgress,
  setIsOperationInProgress,
  isComponentActive,
  handleCancelOptimize,
  isCanceled,
  setIsLogsVisible,
  setRenderKey,
  setIsFetchingLogs,
  verifyDatabaseOptimizeStatus,
  setIsPausing,
  setIsPaused,
  setIsCanceled,
  setOperationLoading,
  isLastLogIndex,
  setIsLastLogIndex,
}) => {
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isResuming, setIsResuming] = useState(false);    

  if (!isOpen) return null;

  const onResumeOptimize = async () => {
    setIsResuming(true);
    setIsSubmitting(true); 
    try {
      const formData = new FormData();
      formData.append("action", "tailwatch_global_ajax_handler");
      formData.append("action_type", "tailwatch_resume_db_optimize");
      formData.append("nonce", tailwatch_ajax.nonce);

      const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
        headers: { "Content-Type": "multipart/form-data" },
      });      

      if (response.data.success) {        
        setIsScanInProgress(true);
        setIsFetchingLogs(true); 
        fetchLogs(setLogs, setIsCompleted, true, isComponentActive, setIsLogsVisible, setIsScanInProgress, setProgress, setRenderKey, setIsPausing, setIsPaused, setIsCanceled, setOperationLoading, isLastLogIndex, setIsLastLogIndex);
      } else {
        toast.error("Failed to resume the optimization:", response.data);
      }
    } catch (error) {
    } finally {
      setIsResuming(false);
      setIsSubmitting(false);       
    }
  };  
  
  const handleResumeOptimize = async()=>{
    setLogs([]);
    setIsPausing(false);
    setIsOperationInProgress(true); 
    await onResumeOptimize();        
    onClose();
    await verifyDatabaseOptimizeStatus();
     
  }

  const handleCancel = async()=>{
    await handleCancelOptimize();
    onClose(); 
  }

  return (
    <Modal
      isOpen={isOpen}
      onClose={onClose}
      title="Resume optimization"
      message="Do you want to resume or cancel optimization?"
      onConfirm={handleResumeOptimize}
      onCancel={handleCancel}
      confirmLabel={isResuming ? 'Processing...' : 'Resume process'}
      cancelLabel={isCanceled ? 'Canceling...' : 'Cancel process'}
      isSubmitting={isSubmitting}      
    />
  );
};

export default ResumeModal;
