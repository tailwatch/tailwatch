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
import { useFeaturesData } from '../../Components/Context/FeaturesDataContext';
import { useState } from 'react';

const IpManagement = () => {
    const { ipManagement,refreshIpStatus } = useIpFeature();
    const { activeTab, handleTabChange } = useIpManagement();                
    const [refreshTrigger, setRefreshTrigger] = useState(0);
    const {featureEnable,parentEnable,setParentEnable,isLicenseConnect,setIsLicenseConnect} = useBlackList();
    const { proPluginActive } = useFeaturesData();
    // Country allow/block is a Pro capability; hide its tab + panel unless the Pro plugin is active.
    const visibleTabs = proPluginActive ? Iptabs : Iptabs.filter((tab) => tab.key !== 'county');

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
                <TableTabs tabs={visibleTabs} activeTab={activeTab} setActiveTab={handleTabChange} />
                {(parentEnable === false || isLicenseConnect === true) && <LockCard featureName="Geo-blocking" featureDescription="Block or allow individual IPs or IP ranges, temporarily or permanently." featureId={ipManagement?.featureId} isActive={ipManagement?.isActiveState} afterToggleCallback={handleFeatureToggle}/>}
                {activeTab === 'black-list' && <BlackListContent refreshTrigger={refreshTrigger} />}
                {activeTab === 'white-list' && <WhiteListContent />}
                {activeTab === 'county' && proPluginActive && <CountryListContent />}
                
            </div>           
        </div>
    );
};
export default IpManagement;