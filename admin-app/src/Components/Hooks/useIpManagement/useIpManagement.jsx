import { useState, useEffect } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';
import { getGeoLiteConnection } from '../../../Pages/IpManagement/IpManagementServices/IpManagementServices';
export const useIpManagement = () => {
    const navigate = useNavigate();
    const location = useLocation();
    const [isGeoLicense, setGeoLicense] = useState(null);

    useEffect(() => {
        (async () => { setGeoLicense(await getGeoLiteConnection()); })();
    }, []);

    const getActiveTabFromURL = () => {
        if (location.pathname.includes('/white-list')) {
            return 'white-list';
        } else if (location.pathname.includes('/county')) {
            return 'county';
        }
        return 'black-list';
    };

    const [activeTab, setActiveTab] = useState(getActiveTabFromURL());

    useEffect(() => {
        const newPath = `/dashboard/geo-blocking/${activeTab}`;
        if (location.pathname !== newPath) {
            navigate(newPath, { replace: true });
        }
    }, [activeTab, navigate, location.pathname]);

    useEffect(() => {
        const currentTab = getActiveTabFromURL();
        if (currentTab !== activeTab) {
            setActiveTab(currentTab);
        }
    }, [location.pathname]);

    const handleTabChange = (newTab) => {
        setActiveTab(newTab);
    };

    return {
        activeTab, handleTabChange, isGeoLicense
    }
}