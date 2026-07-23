import React, { useState } from "react";
import axios from "axios";
import Modal from "../../../Components/Modal/Modal";
/* global wptw_ajax */

const ResumeModal = ({ setOperationLoading, isOpen, onClose, fetchLogs, handleCancel, setIsScanInProgress, setLogs, setIsCompleted, setProgress, setIsOperationInProgress, isComponentActive, setIsLogsVisible, setIsFetchingLogs, setErrorMessages, setIsPaused, setIsCanceled, handleInstantScan, setRenderKey, isLastLogIndex, setIsLastLogIndex }) => {
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isResuming, setIsResuming] = useState(false);
  const [isCanceling, setIsCanceling] = useState(false);

  if (!isOpen) return null;

  const onResume = async () => {
    setIsResuming(true);
    setIsSubmitting(true); 
    try {      
      const formData = new FormData();
      formData.append("action", "wptw_global_ajax_handler");
      formData.append("action_type", "wptw_resume_broken_link_checker");
      formData.append("nonce", wptw_ajax.nonce);

      const response = await axios.post(wptw_ajax.ajax_url, formData, {
        headers: { "Content-Type": "multipart/form-data" },
      });      

      if (response.data.success) {
       
        setIsScanInProgress(true);
        setIsFetchingLogs(true);         
        fetchLogs(setLogs, setIsCompleted, true, isComponentActive, setProgress, setIsScanInProgress, setIsLogsVisible, setErrorMessages, setIsPaused, setIsCanceled, setRenderKey, setOperationLoading, isLastLogIndex, setIsLastLogIndex);

      } else {
        setErrorMessages((prevErrors)=>[...prevErrors, "Failed to resume the backup:" ]);            
        setIsScanInProgress(false);
        setIsLogsVisible(false);        
      }
    } catch (error) {
      setErrorMessages((prevErrors)=>[...prevErrors, error ]);            
      setIsScanInProgress(false);
      setIsLogsVisible(false);      
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
  }

  const handleCancelProcess = async () => {
    setIsCanceling(true);
    handleCancel();
    setIsCanceling(false);
    onClose();    
  };

  return (
    <Modal isOpen={isOpen} onClose={onClose} title="Resume Broken Link" message="Do you want to resume or Cancel Broken Link ?"
    onConfirm={handleResume} onCancel={handleCancelProcess} confirmLabel={isResuming ? 'Processing...' : 'Resume process'} cancelLabel={isCanceling ? 'Canceling...' : 'Cancel Process'} isSubmitting={isSubmitting}    
  />
  );
};

export default ResumeModal;
