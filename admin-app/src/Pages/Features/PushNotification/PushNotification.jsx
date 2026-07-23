import { useState } from 'react'
import { FaBell } from "react-icons/fa";
import { updatePushNotification } from '../FeatureServices/FeatureServices';
import NotificationModal from './NotificationModal';

const PushNotification = ({ handleCloseModal, selectedFeature, GetData, setFeaturesData }) => {    
    const [toggleStates, setToggleStates] = useState({});
    const pushNotificationUpdate = async (feature_id, id, push_notification) => {
        await updatePushNotification({ feature_id, id, push_notification, GetData, setFeaturesData });
    }    

    return (
        <NotificationModal selectedFeature={selectedFeature} featureIcon={<FaBell />} toggleStates={toggleStates} setToggleStates={setToggleStates} pushNotificationUpdate={pushNotificationUpdate} onClose={handleCloseModal} />
    )
}

export default PushNotification