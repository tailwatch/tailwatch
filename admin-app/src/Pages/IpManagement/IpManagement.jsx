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
    const { activeTab, handleTabChange, isGeoLicense } = useIpManagement();                
    const [refreshTrigger, setRefreshTrigger] = useState(0);
    const {featureEnable,parentEnable,setParentEnable,isLicenseConnect,setIsLicenseConnect} = useBlackList();
    const { proPluginActive } = useFeaturesData();
    // Country allow/block is a Pro capability. The tab is always shown; when Pro is
    // inactive its panel renders locked behind an upsell overlay instead of being hidden.

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
                {(parentEnable === false || isLicenseConnect === true) && <LockCard featureName="Geo-blocking" featureDescription="Block or allow individual IPs or IP ranges, temporarily or permanently." featureId={ipManagement?.featureId} isActive={ipManagement?.isActiveState} afterToggleCallback={handleFeatureToggle}/>}
                {activeTab === 'black-list' && <BlackListContent refreshTrigger={refreshTrigger} isGeoLicense={isGeoLicense} />}
                {activeTab === 'white-list' && <WhiteListContent isGeoLicense={isGeoLicense} />}
                {activeTab === 'county' && (
                    <div className="relative">
                        {!proPluginActive && <LockCard isLocked={true} featureName="Country Blocking" featureDescription="Block or allow visitors by country, applied to login forms or your entire site." afterToggleCallback={handleFeatureToggle} />}
                        <CountryListContent isGeoLicense={isGeoLicense} />
                    </div>
                )}
                
            </div>           
        </div>
    );
};
export default IpManagement;