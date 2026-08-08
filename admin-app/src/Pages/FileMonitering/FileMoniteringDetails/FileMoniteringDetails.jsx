import React, { useState, useEffect } from 'react'
import InfoBar from '../../../Components/InfoBar/InfoBar.jsx';
import { SkeletonStatus } from '../../../Components/Skeleton/LoaderSkeleton.jsx';
import { toast } from 'react-toastify';
import axios from 'axios';
/* global tailwatch_ajax */

const FileMoniteringDetails = ({ featureEnable, setFeatureEnable, parentEnable, setParentEnable,isLicenseConnect, setIsLicenseConnect }) => {
    const [moniteringDetails, setMoniteringDetails] = useState(null);
    const [loading, setLoading] = useState(true);

    const fetchMoniteringDetails = async () => {
        setLoading(true);
        try {
            const formData = new FormData();
            formData.append('action', 'tailwatch_global_ajax_handler');
            formData.append('action_type', 'tailwatch_get_file_integrity_status');
            formData.append('nonce', tailwatch_ajax.nonce);

            const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
                headers: {
                    'Content-Type': 'multipart/form-data',
                },
            });

            if (response.status === 200 && response.data.success) {
                const backupData = response.data.data.data;

                setMoniteringDetails(backupData);
            } else if (response.data.success === false) {
                const { feature_enable, parent_enable } = response.data.data;
                setFeatureEnable(feature_enable);
                setParentEnable(parent_enable);
            } else {
                console.error('Monitering details do not exist');
            }
        } catch (error) {
            if (error.response.data.data.code === 403) {
                setIsLicenseConnect(true);
            } else {
            }
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchMoniteringDetails();
    }, []);

    const renderContent = () => {
        if (featureEnable === false || parentEnable === false || isLicenseConnect === true) {
            return (
                <InfoBar
                    type="info"
                    message="Please configure your File Monitering settings to continue."
                />
            );
        }

        if (moniteringDetails) {
            return (
                <InfoBar
                    type="info"
                    message={`
                            Started Time: ${moniteringDetails?.started_time === "N/A" ? "--" : moniteringDetails.started_time}
                            File Exist: ${moniteringDetails?.file_exist || '--'}
                            Next Run: ${moniteringDetails?.next_run || '--'}
                        `}
                />
            );
        }

        return (
            <InfoBar
                type="warning"
                message="Integrity Watch details do not exist"
            />
        );
    };

    return (
        <div className="py-2">
            {loading ? (
                <SkeletonStatus />
            ) : (
                renderContent()
            )}
        </div>
    );
}

export default FileMoniteringDetails