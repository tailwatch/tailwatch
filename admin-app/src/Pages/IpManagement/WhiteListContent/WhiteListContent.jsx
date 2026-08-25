import React, { useState } from 'react';
import TableTabs from '../../../Components/TableTabs/TableTabs';
import WhiteListip from './WhiteListIp/WhiteListip';
import WhiteListCountry from './WhiteListCountry/WhiteListCountry';
import { useFeaturesData } from '../../../Components/Context/FeaturesDataContext';
import LockCard from '../../../Components/LockCard/LockCard';

const WhiteListContent = ({ widget = false, limit = 10, isGeoLicense }) => {
  const { proPluginActive } = useFeaturesData();
  // Country allow-listing is a Pro capability. The Countries tab is always shown; when
  // Pro is inactive its panel renders locked behind an upsell overlay instead of hidden.
  const tabs = [
    { key: 'ip-ranges', label: 'IP Ranges' },
    { key: 'countries', label: 'Countries' },
  ];
  const [activeTab, setActiveTab] = useState('ip-ranges');

  return (
    <div>
      {!widget && (
        <TableTabs tabs={tabs} activeTab={activeTab} setActiveTab={setActiveTab} />
      )}
      {activeTab === 'ip-ranges' && (
        <WhiteListip widget={widget} limit={limit} isGeoLicense={isGeoLicense} />
      )}
      {activeTab === 'countries' && (
        <div className="relative">
          {!proPluginActive && <LockCard isLocked={true} featureName="Country Whitelist" featureDescription="Allow visitors from specific countries to bypass login protection." />}
          <WhiteListCountry widget={widget} limit={limit} isGeoLicense={isGeoLicense} />
        </div>
      )}
    </div>
  );
};

export default WhiteListContent;
