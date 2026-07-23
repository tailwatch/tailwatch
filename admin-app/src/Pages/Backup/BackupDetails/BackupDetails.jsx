import { useState } from 'react';
import { SkeletonStatus } from '../../../Components/Skeleton/LoaderSkeleton';
import InfoBar from '../../../Components/InfoBar/InfoBar';
import { fetchBackupDetails } from '../BackupServices/BackupServices';
import { useEffect } from 'react';
const BackupDetails = ({ fetchTrigger, featureEnable, parentEnable,setFeatureEnable,setParentEnable }) => {
    const [loading,setLoading] = useState(true);
    const [backupDetails, setBackupDetails] = useState(null);

    useEffect(() => {
        fetchBackupDetails({setBackupDetails,setLoading,setFeatureEnable,setParentEnable})
    }, []);

    const renderContent = () => {
        if (featureEnable === false || parentEnable === false) {
            return (
                <InfoBar
                    type="info"
                    message="Please configure your Backup settings to continue."
                />
            );
        }

        if (backupDetails) {
            return (
                <InfoBar type="info"
                    message={`Last Scheduled: ${backupDetails.started_time || 'N/A'} 
                            Next Run: ${backupDetails.next_run || 'N/A'}                             
                            Backup Maintain: ${backupDetails.backupMaintain || 'N/A'} 
                            Backup Option: ${backupDetails.backup_option || 'N/A'} 
                            Time Interval: ${backupDetails.time_interval || 'N/A'}
                        `}
                />
            );
        }

        return (
            <InfoBar
                type="warning"
                message="Backup details do not exist"
            />
        );
    };

    return (
        <div className="pb-4">
            {loading ? (
                <SkeletonStatus />
            ) : (
                renderContent()
            )}
        </div>
    );
};

export default BackupDetails;
