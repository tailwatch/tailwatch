import axios from "axios";
import { toast } from 'react-toastify';
import { alertService } from "../../../Components/AlertService/AlertService";
/* global tailwatch_ajax */

export const getCronJobLists = async ({ setCronJobData, setLoading, page = 1, limit = 10, setPagination, setParentEnable, setFeatureEnable }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_get_cron_jobs_with_source');
        formData.append('data', JSON.stringify({ page, limit }));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data.data.code === 200) {
            setCronJobData(response.data.data.data);
            if (setPagination && response.data.data.pagination) {
                setPagination({ page: response.data.data.pagination.page, limit: response.data.data.pagination.limit, total_pages: response.data.data.pagination.total_pages, total_records: response.data.data.pagination.total_jobs });
            }
        } else if (response.data.success === false) {
            const { feature_enable, parent_enable } = response.data.data;
            setFeatureEnable(feature_enable);
            setParentEnable(parent_enable);
        }
        else {
            setCronJobData([]);
        }
    } catch (error) {
        console.error('Error fetching Cron Job Data:', error);
        toast.error('Network Error: Unable to fetch cron jobs');
        setCronJobData([]);
    } finally {
        setLoading(false);
    }
};

export const getSheduleCron = async ({ setScheduleCronData, setLoading, page = 1, limit = 10, setPagination, setParentEnable, setFeatureEnable }) => {
    setLoading(true);
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_get_schedules');
        formData.append('data', JSON.stringify({ page, limit }));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        if (response.data.data.code === 200) {
            setScheduleCronData(response.data.data.data);
            if (setPagination && response.data.data.pagination) {
                setPagination({ page: response.data.data.pagination.page, limit: response.data.data.pagination.limit, total_pages: response.data.data.pagination.total_pages, total_schedules: response.data.data.pagination.total_schedules });
            }
        } else if (response.data.success === false) {
            const { feature_enable, parent_enable } = response.data.data;
            setFeatureEnable(feature_enable);
            setParentEnable(parent_enable);
        }
        else {
            console.error('Failed to get Schedule Cron Data', response.data.data.message);
            setScheduleCronData([]);
        }
    } catch (error) {
        console.error('Error fetching Schedule Cron Data:', error);
        toast.error('Network Error: Unable to fetch schedule jobs');
        setScheduleCronData([]);
    } finally {
        setLoading(false);
    }
};

export const bulkActions = async ({ type, hashes, bulk_action }) => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_bulk_cron_action');
        const payload = { hashes: hashes, bulk_action: bulk_action };
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data.data.code === 200) {
            return response.data.data;
        } else {
            throw new Error(response.data.data.message || `Failed to perform bulk ${bulk_action}`);
        }
    } catch (error) {
        console.error(`Error performing bulk ${bulk_action}:`, error);
        throw error;
    }
};

export const pauseCronJob = async ({ hash, fetchCronJobs }) => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_pause_cron_job');
        const payload = { hash: hash };
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data.data.code === 200) {
            toast.success("Cron job paused successfully");
            await fetchCronJobs();
        } else {
            toast.error("Failed to pause cron job");
        }
    } catch (error) {
        toast.error("Network Error: Unable to pause cron job");
    }
}

export const deleteCronJob = async ({ hash, fetchCronJobs }) => {
    const confirmed = await alertService.confirm("This can't be undone.", "Are you sure to delete this?");
    if (!confirmed) {
        return;
    }
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_delete_cron_job');
        const payload = { hash: hash };
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data.data.code === 200) {
            toast.success("Cron job deleted successfully");
            await fetchCronJobs();
        } else {
            toast.error("Failed to Delete cron job");
        }
    } catch (error) {
        toast.error("Network Error: Unable to Delete cron job");
    }
}

export const deleteCronSchedule = async ({ slug, fetchScheduleJobs,setDeleting }) => {
     const confirmed = await alertService.confirm("This can't be undone.", "Are you sure to delete this?");
          if (!confirmed) {
            return;
          }    
    try {
        setDeleting(true);
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_delete_schedule');
        const payload = { slug: slug };
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data.data.code === 200) {
            toast.success("Cron Schedule deleted successfully");
            await fetchScheduleJobs();
        } else {
            toast.error("Failed to Delete cron Schedule");
        }
    } catch (error) {
        toast.error("Network Error: Unable to Delete cron Schedule");
    }finally{
        setDeleting(false);
    }
}

export const runCronJob = async ({ hash, fetchCronJobs }) => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_run_cron_job');
        const payload = { hash: hash };
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data.data.code === 200) {
            toast.success("Cron job run successfully");
            await fetchCronJobs();
        } else {
            toast.error("Failed to Run cron job");
        }
    } catch (error) {
        toast.error("Network Error: Unable to Run cron job");
    }
}

export const resumeCronJob = async ({ hash, fetchCronJobs }) => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_resume_cron_job');
        const payload = { hash: hash };
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data.data.code === 200) {
            toast.success("Cron job resumed successfully");
            await fetchCronJobs();
        } else {
            toast.error("Failed to Resume cron job");
        }
    } catch (error) {
        toast.error("Network Error: Unable to Resume cron job");
    }
}

export const editCronJob = async ({ payload, fetchCronJobs }) => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_edit_cron_job');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });
        

        if (response.data.data.code === 200) {
            toast.success("Cron job updated successfully");
            await fetchCronJobs();
        } else {
            toast.error(response.data.data.message || "Failed to update cron job");
        }
    } catch (error) {
        toast.error("Network Error");
    }
}

export const createCronJob = async ({ payload, fetchCronJobs }) => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_add_cron_job');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data.data.code === 200) {
            toast.success("Cron job created successfully");
            await fetchCronJobs();
        } else {
            toast.error(response.data.data.message || "Failed to create cron job");
        }
    } catch (error) {
        toast.error("Network Error: Unable to create cron job");
    }
}

export const createCronSchedules = async ({ payload, fetchCronJobs,onClose }) => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_add_schedule');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });

        if (response.data.data.code === 200) {
            toast.success("Cron Schedule created successfully");
            onClose();
            await fetchCronJobs();
        } else {
            toast.error(response.data.data.message || "Failed to update cron job");
        }
    } catch (error) {
        toast.error("Network Error: Unable to create cron schedule");
    }
}

export const editCronShedule = async ({ payload, fetchCronJobs,onClose }) => {
    try {
        const formData = new FormData();
        formData.append('action', 'tailwatch_global_ajax_handler');
        formData.append('action_type', 'tailwatch_edit_schedule');
        formData.append('data', JSON.stringify(payload));
        formData.append('nonce', tailwatch_ajax.nonce);

        const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
            headers: { 'Content-Type': 'multipart/form-data' },
        });        
        if (response.data.data.code === 200) {
            toast.success("Cron Schedule updated successfully");
            onClose();
            await fetchCronJobs();
        } else {
            toast.error(response.data.data.message || "Failed to update cron job");
        }
    } catch (error) {
        toast.error("Network Error");
    }
}
export const fetchLogsCron = async ({ setLoading, setLogsData, page = 1, limit = 10,setParentEnable, setFeatureEnable, setPagination }) => {

  setLoading(true);
  try {
    const formData = new FormData();
    formData.append('action', 'tailwatch_global_ajax_handler');
    formData.append('action_type', 'tailwatch_logs_feature');
    formData.append('data', JSON.stringify({ key: "default_cron_jobs", option: "cron_jobs_activity",page,limit }));
    formData.append('nonce', tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    });
    if (response.data.success) {
      const parsedData = response.data.data.data.map(item => {
        try {
          return { ...item, parsedValue: JSON.parse(item.value) };
        } catch (e) {
          console.error('Error parsing value:', item.value, e);
          return item;
        }
      });      
      setLogsData(parsedData);
      if (setPagination && response.data.data) {
        setPagination({ page: response.data.data.pagination.page, limit: response.data.data.pagination.limit, total_pages: response.data.data.pagination.total_pages,total_items: response.data.data.pagination.total });
      }
    }else if (response.data.success === false) {
            const { feature_enable, parent_enable } = response.data.data;
            setFeatureEnable(feature_enable);
            setParentEnable(parent_enable);
    } else {
      setLogsData([]);
    }
  } catch (error) {
    console.error("Error fetching data:", error);
    setLogsData([]);
  }
  setLoading(false);
};

export const handleDeleteCronLogs = async (deleteData, setIsDeleting, fetchLogsData) => {
  const { ids, is_delete } = deleteData;  
  setIsDeleting(true);
  try {
    const formData = new FormData();
    formData.append("action", "tailwatch_global_ajax_handler");
    formData.append("action_type", "tailwatch_delete_logs");
    formData.append("data", JSON.stringify({ ids, key: "default_cron_jobs", option: "cron_jobs_activity", is_delete }));
    formData.append("nonce", tailwatch_ajax.nonce);

    const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
      headers: {
        "Content-Type": "multipart/form-data",
      },
    });

    if (response.data.data.code === 200) {
      await fetchLogsData();
      toast.success("Logs deleted successfully");
    } else {
      console.error("Failed to delete logs:", response.data.data.message);
      toast.error("Failed to delete logs");
    }
  } catch (error) {
    console.error("Error deleting logs:", error);
    toast.error("Network Error");
  } finally {
    setIsDeleting(false);
  }
};