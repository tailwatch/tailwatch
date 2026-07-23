import { Iptabs } from './TabsData/TabsData';
import Header from '../../Components/Header/Header';
import TableTabs from '../../Components/TableTabs/TableTabs';
import { useIpManagement } from '../../Components/Hooks/useIpManagement/useIpManagement';
import BlackListContent from './BlackListContent/BlackListContent';
import WhiteListContent from './WhiteListContent/WhiteListContent';
import CountryListContent from './CountryList/CountryListContent';
import { useIpFeature } from '../../Components/Hooks/Features/UseFeatures';
import LockCard from '../../Components/LockCard/LockCard';
import { useBlackList } from '../../Components/Hooks/useIpManagement/useBlackList';
import { useState } from 'react';

const IpManagement = () => {
    const { ipManagement,refreshIpStatus } = useIpFeature();
    const { activeTab, handleTabChange,isGeoLicense } = useIpManagement();                
    const [refreshTrigger, setRefreshTrigger] = useState(0);
    const {featureEnable,parentEnable,setParentEnable,isLicenseConnect,setIsLicenseConnect} = useBlackList();

    const handleFeatureToggle = async () => {
    await refreshIpStatus();
    setIsLicenseConnect(null);
    setParentEnable(null);
    setRefreshTrigger(prev => prev + 1); 
    };

    return (
        <div>
            <Header title="Geo-blocking" />
            <div className='p-4'>
                <TableTabs tabs={Iptabs} activeTab={activeTab} setActiveTab={handleTabChange} />
                {(parentEnable === false || isLicenseConnect === true) && <LockCard featureName="Geo-blocking" featureDescription="Block or allow individual IPs, IP ranges, or entire countries temporarily or permanently." featureId={ipManagement?.featureId} isActive={ipManagement?.isActiveState} afterToggleCallback={handleFeatureToggle}/>}
                {activeTab === 'black-list' && <BlackListContent refreshTrigger={refreshTrigger} isGeoLicense={isGeoLicense} />}
                {activeTab === 'white-list' && <WhiteListContent isGeoLicense={isGeoLicense} />}
                {activeTab === 'county' && <CountryListContent isGeoLicense={isGeoLicense} />}
                
            </div>           
        </div>
    );
};
export default IpManagement;