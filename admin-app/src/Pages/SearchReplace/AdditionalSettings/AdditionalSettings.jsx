import React from 'react';
import { SettingItem } from './SettingItem';

const AdditionalSettings = ({ caseInsensitive, setCaseInsensitive, replaceGuids, setReplaceGuids, dryRun, setDryRun, disabled }) => {

    return (
        <div>
            <h2 className="text-lg font-bold text-gray-800 mb-3 relative pl-4">
                <div className="absolute left-0 top-1/2 transform -translate-y-1/2 w-1 h-5 bg-gradient-to-r from-amber-500 to-rose-500 rounded-full"></div>
                Additional Settings
            </h2>
            <div className="bg-gray-50 rounded-xl border-2 border-gray-200 p-3">
                <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
                    <SettingItem id="case-insensitive-checkbox" label="Case-Insensitive" description="Searches are case-sensitive by default." checked={caseInsensitive} onChange={(e) => setCaseInsensitive(e.target.checked)} disabled={disabled} />

                    <SettingItem id="replace-guids-checkbox" label="Replace GUIDs" description="If left unchecked, all database columns titled 'guid' will be skipped." checked={replaceGuids} onChange={(e) => setReplaceGuids(e.target.checked)} disabled={disabled} />

                    <SettingItem id="dry-run-checkbox" label="Run as dry run" description="If checked, no changes will be made to the database, allowing you to check the results beforehand." checked={dryRun} onChange={(e) => setDryRun(e.target.checked)} disabled={disabled} />
                </div>
            </div>
        </div>
    );
};

export default AdditionalSettings;
