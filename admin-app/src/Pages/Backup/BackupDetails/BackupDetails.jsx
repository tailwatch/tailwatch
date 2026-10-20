import { useState } from 'react';
import { SkeletonStatus } from '../../../Components/Skeleton/LoaderSkeleton';
import InfoBar from '../../../Components/InfoBar/InfoBar';
import { fetchBackupDetails } from '../BackupServices/BackupServices';
import { useEffect } from 'react';

// Backups made while more of them were kept, which the lower number kept now does not delete.
const heldMessage = (count) =>
    count === 1
        ? '1 older backup is kept on purpose: it was made while you kept more backups, so your current setting does not remove it. Delete it from the list below when you no longer need it.'
        : `${count} older backups are kept on purpose: they were made while you kept more backups, so your current setting does not remove them. Delete them from the list below when you no longer need them.`;

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
            const held = Number(backupDetails.backupsHeld) || 0;
            return (
                <>
                <InfoBar type="info"
                    message={`Last Scheduled: ${backupDetails.started_time || 'N/A'} 
                            Next Run: ${backupDetails.next_run || 'N/A'}                             
                            Backup Maintain: ${backupDetails.backupMaintain || 'N/A'} 
                            Backup Option: ${backupDetails.backup_option || 'N/A'} 
                            Time Interval: ${backupDetails.time_interval || 'N/A'}
                        `}
                />
                {held > 0 && (
                    <div className="mt-2">
                        <InfoBar type="info" message={heldMessage(held)} />
                    </div>
                )}
                </>
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
