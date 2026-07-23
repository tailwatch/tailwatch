import { useState } from 'react';
import PopUpModal from '../../../Components/Modal/PopUpModal';
import InputField from '../../../Components/Fields/InputField';
import { createCronSchedules } from '../CronJobServices/CronJobServices';

const CreateCronSchedule = ({ isOpen, onClose, fetchCronJobs }) => {
    const [formData, setFormData] = useState({ interval: '', displayName: '', slug: '' });

    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState('');
    const [intervalError, setIntervalError] = useState('');
    const [displayNameError, setDisplayNameError] = useState('');
    const [slugError, setSlugError] = useState('');

    const handleChange = (e) => {
        const { name, value } = e.target;
        setFormData(prev => ({
            ...prev,
            [name]: value
        }));
        if (name === 'interval') setIntervalError('');
        if (name === 'displayName') setDisplayNameError('');
        if (name === 'slug') setSlugError('');
    };

    const handleSubmit = async () => {

        let hasError = false;

        if (!formData.interval) {
            setIntervalError('Interval is required');
            hasError = true;
        }
        if (!formData.displayName) {
            setDisplayNameError('Display name is required');
            hasError = true;
        }
        if (!formData.slug) {
            setSlugError('Internal name is required');
            hasError = true;
        }
        if (hasError) return;
        try {
            setIsLoading(true);
            setError('');
            const payload = { slug: formData.slug, name: formData.displayName, interval: parseInt(formData.interval) };

            await createCronSchedules({ payload, fetchCronJobs, onClose });
            setFormData({ interval: '', displayName: '', slug: '' });
        } catch (err) {
            setError(err.message || 'Failed to create cron job');
        } finally {
            setIsLoading(false);
        }
    };

    if (!isOpen) return null;

    return (
        <PopUpModal title="Add Schedule" onClose={onClose} onSave={handleSubmit} saveButtonText="Submit" cancelButtonText="Cancel" loading={false} isLoading={isLoading} width="w-full max-w-2xl" >
            <div className="space-y-4">
                <div className='px-1 py-1'>
                    <InputField label="Interval seconds" type="number" id="interval" name="interval" value={formData.interval} onChange={handleChange} required />
                    {intervalError && <div className='text-red-500 mb-3 text-xs'>{intervalError}</div>}
                    <InputField label="Display name" type="text" id="displayName" name="displayName" value={formData.displayName} onChange={handleChange} required />
                    {displayNameError && <div className='text-red-500 mb-3 text-xs'>{displayNameError}</div>}
                    <InputField label="Internal name" type="text" id="slug" name="slug" value={formData.slug} onChange={handleChange} required />
                    {slugError && <div className='text-red-500 mb-3 text-xs'>{slugError}</div>}
                </div>
            </div>
        </PopUpModal>
    );
};

export default CreateCronSchedule;