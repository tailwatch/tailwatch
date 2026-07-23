import { useState, useEffect } from 'react';
import PopUpModal from '../../../Components/Modal/PopUpModal';
import InputField from '../../../Components/Fields/InputField';
import GetCronSchedule from '../../../Components/GetCronSchedule/GetCronSchedule';
import { editCronJob } from '../CronJobServices/CronJobServices'

const EditCronJobModal = ({ isOpen, onClose, cronJobData, fetchCronJobs }) => {
  const [formData, setFormData] = useState({ hook: '', arguments: '', nextRunDate: '', nextRunTime: '', schedule: '' });
  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState('');
  const [argumentsError, setArgumentsError] = useState('');

  useEffect(() => {
    if (cronJobData) {
      let nextRunDate = '';
      let nextRunTime = '';
      if (cronJobData.next_run_formatted && cronJobData.next_run_formatted !== 'N/A') {
        try {
          // Backend sends site-local time as "YYYY-MM-DD HH:MM:SS" (or with a 'T' separator).
          // Split the string directly so we never go through Date — that would shift the value
          // by the browser's UTC offset (toISOString returns UTC, mismatching toTimeString's local).
          const [datePart, timePartRaw] = cronJobData.next_run_formatted.split(/[\sT]/);
          if (datePart) nextRunDate = datePart;
          if (timePartRaw) nextRunTime = timePartRaw.substring(0, 5); // HH:MM
        } catch (e) {
          console.error("Error parsing date:", e);
        }
      }

      const newFormData = {
        hook: cronJobData.hook || '',
        arguments: cronJobData.arguments || '',
        nextRunDate,
        nextRunTime,
        schedule: cronJobData.schedule || ''
      };

      setFormData(newFormData);      
    }
  }, [cronJobData]);

  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: value
    }));
    if (name === 'arguments') {
      setArgumentsError('');
    }
  };

  const handleScheduleSelect = (selectedOption) => {
    setFormData(prev => ({
      ...prev,
      schedule: selectedOption.slug
    }));
  };

  // Validate JSON array format
  const validateArguments = (args) => {
    if (!args.trim()) return true;

    try {
      const parsed = JSON.parse(args);
      if (!Array.isArray(parsed)) {
        setArgumentsError('Arguments must be a JSON array (e.g. ["value",123])');
        return false;
      }
      return true;
    } catch (e) {
      setArgumentsError('Invalid JSON format. Use a JSON encoded array, e.g. [25], ["asdf"], or ["i","want",25,"cakes"]');
      return false;
    }
  };

  const handleSubmit = async () => {
    try {
      if (formData.arguments && !validateArguments(formData.arguments)) {
        return;
      }
      setIsLoading(true);
      setError('');

      const payload = {
        hash: cronJobData.hash,
        schedule: formData.schedule
      };

      try {
        const args = formData.arguments.trim() ? JSON.parse(formData.arguments) : [];
        payload.args = JSON.stringify(args);
      } catch (e) {
        setArgumentsError('Invalid JSON format');
        setIsLoading(false);
        return;
      }
      if (formData.nextRunDate && formData.nextRunTime) {
        // Send exactly what the user picked (site-local time). Going through new Date(...) +
        // toISOString() would silently shift the value by the browser's UTC offset.
        payload.execution_time = `${formData.nextRunDate} ${formData.nextRunTime}:00`;
      }

      await editCronJob({ payload, fetchCronJobs });
      onClose();
    } catch (err) {
      setError(err.message || 'Failed to update cron job');
    } finally {
      setIsLoading(false);
    }
  };

  if (!isOpen) return null;

  return (
    <PopUpModal title="Edit Cron Event" onClose={onClose} onSave={handleSubmit} saveButtonText="Save Changes" cancelButtonText="Cancel" loading={false} isLoading={isLoading} error={error} width="w-full max-w-2xl">
      <div className="space-y-4 px-1">
        <div>
          <InputField label="Hook Name" type='text' id='hook' name='hook' value={formData.hook} readOnly={true} className="bg-gray-100" />
        </div>

        <div>
          <label htmlFor="arguments" className="block text-sm font-medium text-gray-700 mb-1">
            Arguments (optional)
          </label>
          <div className="space-y-1">
            <textarea id="arguments" name="arguments" value={formData.arguments} onChange={handleChange} rows="3" className="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-1 focus:ring-[#007980] focus:border-[#007980]" placeholder='["param1", "param2", 123]' />
            {argumentsError && (
              <p className="text-sm text-red-600">{argumentsError}</p>
            )}
            <p className="text-xs text-gray-500">
              Use a JSON encoded array, e.g. [25], ["asdf"], or ["i","want",25,"cakes"]
            </p>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <InputField label="Next Run Date" type="date" id="nextRunDate" name="nextRunDate" value={formData.nextRunDate} onChange={handleChange} />
          </div>
          <div>
            <InputField label="Next Run Time" type="time" id="nextRunTime" name="nextRunTime" value={formData.nextRunTime} onChange={handleChange} />
          </div>
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700 mb-1">
            Schedule
          </label>
          <GetCronSchedule slug={formData.schedule} onOptionSelect={handleScheduleSelect} />
        </div>
      </div>
    </PopUpModal>
  );
};

export default EditCronJobModal;