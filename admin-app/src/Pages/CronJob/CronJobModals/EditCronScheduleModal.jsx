import { useState, useEffect } from 'react';
import PopUpModal from '../../../Components/Modal/PopUpModal';
import InputField from '../../../Components/Fields/InputField';
import { editCronShedule } from '../CronJobServices/CronJobServices';

const EditCronSchedule = ({ isOpen, onClose, fetchCronJobs, scheduleData }) => {
    const [formData, setFormData] = useState({ interval: '', displayName: '', slug: '' });
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState('');
    
    const convertTimeStringToSeconds = (timeString) => {
        if (!timeString || typeof timeString !== 'string') return '';
        
        let totalSeconds = 0;                
        const minutesMatch = timeString.match(/(\d+)\s*minutes?/i);
        if (minutesMatch) {
            totalSeconds += parseInt(minutesMatch[1]) * 60;
        }                
        const secondsMatch = timeString.match(/(\d+)\s*seconds?/i);
        if (secondsMatch) {
            totalSeconds += parseInt(secondsMatch[1]);
        }                
        const hoursMatch = timeString.match(/(\d+)\s*hours?/i);
        if (hoursMatch) {
            totalSeconds += parseInt(hoursMatch[1]) * 3600;
        }                
        const daysMatch = timeString.match(/(\d+)\s*days?/i);
        if (daysMatch) {
            totalSeconds += parseInt(daysMatch[1]) * 86400;
        }
        
        return totalSeconds.toString();
    };    
        
    useEffect(() => {
        if (scheduleData && isOpen) {
            setFormData({
                interval: convertTimeStringToSeconds(scheduleData.interval) || '',
                displayName: scheduleData.display_name || scheduleData.display || '',
                slug: scheduleData.slug || ''
            });
        }
    }, [scheduleData, isOpen]);

    const handleChange = (e) => {
        const { name, value } = e.target;
        setFormData(prev => ({
            ...prev,
            [name]: value
        }));
    };

    const handleSubmit = async () => {
        try {
            if (!formData.interval || !formData.displayName || !formData.slug) {
                setError('All fields are required');
                return;
            }
            setIsLoading(true);
            setError('');
            
            const payload = { 
                slug: formData.slug, 
                name: formData.displayName, 
                interval: parseInt(formData.interval) 
            };

            await editCronShedule({ payload, fetchCronJobs, onClose });
            
            setFormData({ interval: '', displayName: '', slug: '' });            
        } catch (err) {
            setError(err.message || 'Failed to update cron schedule');
        } finally {
            setIsLoading(false);
        }
    };

    const handleClose = () => {
        setFormData({ interval: '', displayName: '', slug: '' });
        setError('');
        onClose();
    };

    if (!isOpen) return null;

    return (
        <PopUpModal title="Edit Cron Schedule" onClose={handleClose} onSave={handleSubmit} saveButtonText="Update Cron Schedule" cancelButtonText="Cancel" loading={false} isLoading={isLoading} error={error} 
            width="w-full max-w-2xl"
        >
            <div className="space-y-4">
                <div className='px-1 py-1'>
                    <InputField label="Interval seconds" type="number" id="interval" name="interval" value={formData.interval} onChange={handleChange} required />
                    <InputField label="Display Name" type="text" id="displayName" name="displayName" value={formData.displayName} onChange={handleChange} required />
                    <InputField label="Internal name" type="text" id="slug" name="slug" value={formData.slug} onChange={handleChange} required disabled />
                </div>
            </div>
        </PopUpModal>
    );
};

export default EditCronSchedule;