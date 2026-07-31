import React, { useState } from 'react';
import TableTabs from '../../../Components/TableTabs/TableTabs';
import WhiteListip from './WhiteListIp/WhiteListip';
import WhiteListCountry from './WhiteListCountry/WhiteListCountry';
import { useFeaturesData } from '../../../Components/Context/FeaturesDataContext';

const WhiteListContent = ({ widget = false, limit = 10 }) => {
  const { proPluginActive } = useFeaturesData();
  // Country allow-listing is a Pro capability; only expose the Countries tab when Pro is active.
  const tabs = proPluginActive
    ? [
        { key: 'ip-ranges', label: 'IP Ranges' },
        { key: 'countries', label: 'Countries' },
      ]
    : [{ key: 'ip-ranges', label: 'IP Ranges' }];
  const [activeTab, setActiveTab] = useState('ip-ranges');

  return (
    <div>
      {!widget && (
        <TableTabs tabs={tabs} activeTab={activeTab} setActiveTab={setActiveTab} />
      )}
      {activeTab === 'ip-ranges' && (
        <WhiteListip widget={widget} limit={limit} />
      )}
      {activeTab === 'countries' && proPluginActive && (
        <WhiteListCountry widget={widget} limit={limit} />
      )}
    </div>
  );
};

export default WhiteListContent;
