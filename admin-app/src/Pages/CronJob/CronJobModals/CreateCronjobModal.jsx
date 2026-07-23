import { useState } from 'react';
import PopUpModal from '../../../Components/Modal/PopUpModal';
import InputField from '../../../Components/Fields/InputField';
import GetCronSchedule from '../../../Components/GetCronSchedule/GetCronSchedule';
import { createCronJob } from '../CronJobServices/CronJobServices';

const CreateCronjobModal = ({ isOpen, onClose, fetchCronJobs }) => {
  const [formData, setFormData] = useState({ hook: '', arguments: '', nextRunDate: '', nextRunTime: '', schedule: '' });

  const [isLoading, setIsLoading] = useState(false);
  const [error, setError] = useState('');
  const [hookError, setHookError] = useState('');
  const [scheduleError, setScheduleError] = useState('');
  const [dateError, setDateError] = useState('');
  const [timeError, setTimeError] = useState('');
  const [argumentsError, setArgumentsError] = useState('');


  const handleChange = (e) => {
    const { name, value } = e.target;
    setFormData(prev => ({
      ...prev,
      [name]: value
    }));

    if (name === 'nextRunDate') setDateError('');
    if (name === 'nextRunTime') setTimeError('');
    if (name === 'hook') setHookError('');
    if (name === 'arguments') {
      setArgumentsError('');
    }
  };

  const handleScheduleSelect = (selectedOption) => {
    setFormData(prev => ({
      ...prev,
      schedule: selectedOption.slug
    }));
    setScheduleError('');
  };

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
      if (!formData.hook.trim()) {
        setHookError('Hook name is required');
        return;
      }

      if (formData.arguments && !validateArguments(formData.arguments)) {
        return;
      }
      if (!formData.nextRunDate) {
        setDateError('Next run date is required');
      }
      if (!formData.nextRunTime) {
        setTimeError('Next run time is required');
      }
      if (!formData.nextRunDate || !formData.nextRunTime) {
        return;
      }

      if (!formData.schedule) {
        setScheduleError('Schedule is required');
        return;
      }
      setIsLoading(true);
      setHookError('');
      setScheduleError('');
      setError('');
      setDateError('')
      setTimeError('');
      setArgumentsError('');

      const payload = { hook: formData.hook, schedule: formData.schedule };
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

      await createCronJob({ payload, fetchCronJobs });
      setFormData({ hook: '', arguments: '', nextRunDate: '', nextRunTime: '', schedule: '' });
      onClose();
    } catch (err) {
      setError(err.message || 'Failed to create cron job');
    } finally {
      setIsLoading(false);
    }
  };

  if (!isOpen) return null;

  return (
    <PopUpModal title="Add Cron Event" onClose={onClose} onSave={handleSubmit} saveButtonText="Add Cron Event" cancelButtonText="Cancel" loading={false} isLoading={isLoading} error={error} width="w-full max-w-2xl">
      <div className="space-y-4 px-1">
        <div>
          <InputField label="Hook Name" type="text" id="hook" name="hook" value={formData.hook} onChange={handleChange} required />
          {hookError && <div className='text-red-500 mb-3 text-xs'>{hookError}</div>}
        </div>

        <div>
          <label htmlFor="arguments" className="block text-sm font-medium text-black mb-1">
            Arguments (optional)
          </label>
          <div className="space-y-1">
            <textarea id="arguments" name="arguments" value={formData.arguments} onChange={handleChange} rows="3" className="!w-full !px-3 !py-2 !border !border-gray-300 !rounded-md !shadow-sm focus:outline-none focus:ring-1 focus:ring-[#007980] focus:border-[#007980]" placeholder='["param1", "param2", 123]' />
            {argumentsError && (
              <p className="text-sm text-red-600">{argumentsError}</p>
            )}
            <p className="text-xs text-black">
              Use a JSON encoded array, e.g. [25], ["asdf"], or ["i","want",25,"cakes"]
            </p>
          </div>
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
          <div>
            <InputField label="Next Run Date" type="date" id="nextRunDate" name="nextRunDate" value={formData.nextRunDate} onChange={handleChange} />
            {dateError && <div className='text-red-500 mt-1 text-xs'>{dateError}</div>}

          </div>
          <div>
            <InputField label="Next Run Time" type="time" id="nextRunTime" name="nextRunTime" value={formData.nextRunTime} onChange={handleChange} />
            {timeError && <div className='text-red-500 mt-1 text-xs'>{timeError}</div>}  {/* Yeh add karo */}

          </div>
        </div>


        <div>
          <label className="block text-sm font-medium text-black mb-1">
            Schedule
          </label>
          <GetCronSchedule slug={formData.schedule} onOptionSelect={handleScheduleSelect} />
          {scheduleError && <div className='text-red-500 mb-3 text-xs'>{scheduleError}</div>}

        </div>
      </div>
    </PopUpModal>
  );
};

export default CreateCronjobModal;