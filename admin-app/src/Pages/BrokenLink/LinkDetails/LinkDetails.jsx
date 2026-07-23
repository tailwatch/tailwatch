import React, { useState } from 'react';
import { SkeletonStatus } from '../../../Components/Skeleton/LoaderSkeleton';
import InfoBar from '../../../Components/InfoBar/InfoBar';


const LinkDetails = ({fetchTrigger}) => {    
    const [linkDetails, setLinkDetails] = useState(null);
    const [loading, setLoading] = useState(false);              
    
    return (
        <div className="px-4 pt-4">
            {loading ? (
                <SkeletonStatus />
            ) : (
                linkDetails ? (
                    <InfoBar
                        type="info"
                    />
                ) : (
                    <InfoBar
                        type="info"
                        message="Link details do not exist"
                    />
                )
            )}
        </div>
    );
};

export default LinkDetails;
