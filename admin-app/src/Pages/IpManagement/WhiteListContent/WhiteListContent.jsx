import React, { useState } from 'react';
import TableTabs from '../../../Components/TableTabs/TableTabs';
import WhiteListip from './WhiteListIp/WhiteListip';
import WhiteListCountry from './WhiteListCountry/WhiteListCountry';

const WhiteListContent = ({ widget = false, limit = 10, isGeoLicense }) => {
  // Country allow-listing is a Pro capability. The Countries tab is always shown; without
  // Pro its panel says so in its own notice and keeps its buttons off.
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
        <WhiteListCountry widget={widget} limit={limit} isGeoLicense={isGeoLicense} />
      )}
    </div>
  );
};

export default WhiteListContent;
