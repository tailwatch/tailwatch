import React, { useState } from "react";
import axios from "axios";
import {updateLocalStorage} from '../../../Components/Utils/HelperFunctions/LocalStorageHelper';
import Modal from "../../../Components/Modal/Modal";
import { toast } from 'react-toastify' ;
/* global wptw_ajax */

const ResumeModal = ({
  isOpen,
  onClose,
  fetchLogs,
  setIsScanInProgress,
  setLogs,
  setIsCompleted,
  isCanceled,
  setIsOperationInProgress,
  isComponentActive,
  setProgress,
  setIsLogsVisible,
  setIsResuming,
  isResuming,
  handleCancelAfterPause,
  setIsPausing,
  setIsPaused,
  setIsCanceled,
  setInfoRevertChange,
  setIsCanceling,
  setOperationLoading,
  isLastLogIndex,
  setIsLastLogIndex,
}) => {
  const [isSubmitting, setIsSubmitting] = useState(false);
  

  if (!isOpen) return null;

  const onResume = async () => {
    setIsResuming(true);
    setIsSubmitting(true); 
    try {      
      const formData = new FormData();
      formData.append("action", "wptw_global_ajax_handler");
      formData.append("action_type", "wptw_resume_search_replace");
      formData.append("nonce", wptw_ajax.nonce);

      const response = await axios.post(wptw_ajax.ajax_url, formData, {
        headers: { "Content-Type": "multipart/form-data" },
      });      

      if (response.data.success) {
        updateLocalStorage('Search&Replace', {
          fetchLogsActive: true,          
        });
        setIsScanInProgress(true);
        fetchLogs(setLogs, setIsCompleted, true, isComponentActive, setProgress, setIsScanInProgress, setIsLogsVisible, setIsPausing, setIsResuming, setIsPaused, setIsCanceled, setInfoRevertChange, setIsCanceling, setOperationLoading, isLastLogIndex, setIsLastLogIndex);
      } else {
        toast.error('Failed to resume ');        
      }
    } catch (error) {      
      toast.error("Network error");
    } finally {
      setIsResuming(false);
      setIsSubmitting(false);
      onClose(); 
    }
  };    
  const handleResume = async()=>{
    setLogs([]);
    setIsOperationInProgress(true); 
    await onResume();    
    setIsPaused(false);
    setIsPausing(false);
  }
  const handleCancelProcess = async ()=> {
    await handleCancelAfterPause();
    setIsPausing(false);
    onClose(); 
  }
  return (
    <Modal
    isOpen={isOpen}
    onClose={onClose}
    title="Resume Process"
    message="Do you want to Resume or Cancel the Process ?"
    onConfirm={handleResume}
    onCancel={handleCancelProcess}
    confirmLabel={isResuming ? 'Processing...' : 'Resume process'}
    cancelLabel={isCanceled ? 'Processing...' : 'Cancel process'}
    isSubmitting={isSubmitting}
    isProcessing={isResuming || isCanceled}
  />
  );
};

export default ResumeModal;
