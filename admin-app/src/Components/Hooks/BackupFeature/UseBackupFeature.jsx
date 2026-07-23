import { useState, useEffect,useCallback } from 'react';
import { getLocalStorage } from '../../Utils/HelperFunctions/LocalStorageHelper';
const UseBackupFeature = () => {
  const [enableBackup, setEnableBackup] = useState(null);

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        const backupScanner = localStorageData.find(item => item.option === "default_backup_enable");          

        if (backupScanner && backupScanner.value) {
          const backupValue = JSON.parse(backupScanner.value);

          const isBackupEnabled =
            backupValue?.options?.field_1?.values?.option?.selected === true;

          const isRestoreWebsiteEnabled =
            backupValue?.options?.field_9?.values?.option?.selected === true;

          setEnableBackup({
            backupFeature:
              backupScanner.is_active === "0" || isBackupEnabled === false,
            restoreWebsiteFeature: isRestoreWebsiteEnabled || false,
            featureId: backupScanner.id || null,
            isActiveState: backupScanner.is_active || null,
          });
        } else {
          setEnableBackup({ backupFeature: false, restoreWebsiteFeature: false, featureId: null, isActiveState: null });
        }
      } else {
        setEnableBackup({ backupFeature: false, restoreWebsiteFeature: false, featureId: null, isActiveState: null });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setEnableBackup({ backupFeature: false, restoreWebsiteFeature: false, featureId: null, isActiveState: null });
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();
  }, [checkLocalStorage]);

  return { enableBackup, refreshBackupStatus: checkLocalStorage };
};

export default UseBackupFeature;
