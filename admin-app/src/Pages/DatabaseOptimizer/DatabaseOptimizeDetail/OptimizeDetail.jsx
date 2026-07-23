import React from 'react';
import { SkeletonStatus } from '../../../Components/Skeleton/LoaderSkeleton';
import InfoBar from '../../../Components/InfoBar/InfoBar';

const OptimizeDetail = ({ schedule, optimizerEnabled, loading }) => {
    const renderContent = () => {
        if (optimizerEnabled === false) {
            return (
                <InfoBar
                    type="info"
                    message="Please configure your database optimization settings to continue."
                />
            );
        }

        if (schedule) {
            return (
                <InfoBar
                    type="info"
                    message={`Automatically runs ${schedule.interval || 'N/A'}, the next scheduled run starts ${schedule.next_run || 'N/A'}`}
                />
            );
        }

        return (
            <InfoBar
                type="warning"
                message="Database details do not exist"
            />
        );
    };

    return (
        <div className="py-2">
            {loading ? <SkeletonStatus /> : renderContent()}
        </div>
    );
};

export default OptimizeDetail;
