import React, { useState, useEffect, useCallback } from "react";
import { getLocalStorage } from '../../Utils/HelperFunctions/LocalStorageHelper';
import { useFeaturesData } from '../../Context/FeaturesDataContext';


export const UseMigrationFeature = () => {
  const [enableMigration, setEnableMigration] = useState(null);

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        const migrationFeature = localStorageData.find(item => item.option === "default_site_migration");

        if (migrationFeature && migrationFeature.value) {
          const migrationValue = JSON.parse(migrationFeature.value);
          const isMigrationValue = migrationValue?.options?.field_1?.values?.option?.selected === true;

          setEnableMigration({
            migrationSiteFeature: migrationFeature.is_active === "0" || isMigrationValue === false,
            featureId: migrationFeature.id || null,
            isActiveState: migrationFeature.is_active || null
          });
        } else {
          setEnableMigration({ migrationSiteFeature: false, featureId: null, isActiveState: null });
        }
      } else {
        setEnableMigration({ migrationSiteFeature: false, featureId: null, isActiveState: null });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setEnableMigration({ migrationSiteFeature: false, featureId: null, isActiveState: null });
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();
  }, [checkLocalStorage]);

  return { enableMigration, refreshMigrationStatus: checkLocalStorage };
};


export const UseDatabaseOptimizer = () => {
  const [enableDatabase, setEnableDatabase] = useState(null);

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        const databaseOptimizer = localStorageData.find(item => item.option === "default_database_optimizer");

        if (databaseOptimizer && databaseOptimizer.value) {
          const databaseValue = JSON.parse(databaseOptimizer.value);
          const isDatabaseValue = databaseValue?.options?.field_1?.values?.option?.selected === true;

          setEnableDatabase({
            databaseFeature: databaseOptimizer.is_active === "0" || isDatabaseValue === false,
            featureId: databaseOptimizer.id || null,
            isActiveState: databaseOptimizer.is_active || null
          });
        } else {
          setEnableDatabase({ databaseFeature: false, featureId: null, isActiveState: null });
        }
      } else {
        setEnableDatabase({ databaseFeature: false, featureId: null, isActiveState: null });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setEnableDatabase({ databaseFeature: false, featureId: null, isActiveState: null });
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();
  }, [checkLocalStorage]);

  return { enableDatabase, refreshDatabaseStatus: checkLocalStorage };
};


export const useSubOptionsCheck = () => {
  const [enableDatabase, setEnableDatabase] = useState(null);
  const [areAllSubOptionsFalse, setAreAllSubOptionsFalse] = useState(false);

  useEffect(() => {
    const checkLocalStorage = async () => {
      try {
        const localStorageData = getLocalStorage('features_data', 'data');
        if (localStorageData && Array.isArray(localStorageData)) {
          const databaseOptimizer = localStorageData.find(item => item.option === "default_database_optimizer");

          if (databaseOptimizer && databaseOptimizer.value) {
            const databaseValue = JSON.parse(databaseOptimizer.value);

            // Checking if all the sub-options (field_2 to field_10) are false, skipping field_11, field_12, and field_13
            const subOptions = databaseValue?.options?.field_1?.sub_options;
            const fieldsToCheck = Object.keys(subOptions || {}).filter(key => !['field_15', 'field_16'].includes(key));           

            const areAllSubOptionsFalse = fieldsToCheck.length > 0 && fieldsToCheck.every(key => {
              const isSelected = subOptions[key]?.values?.option?.selected === false;              
              return isSelected;
            });
            
            setAreAllSubOptionsFalse(areAllSubOptionsFalse);
          } else {            
            setAreAllSubOptionsFalse(false);
          }
        } else {          
          setAreAllSubOptionsFalse(false);
        }
      } catch (error) {
        console.error("Error checking localStorage:", error);
        setAreAllSubOptionsFalse(false);
      }
    };
    checkLocalStorage();
  }, []);

  return { areAllSubOptionsFalse };
};

export const useDatabaseFeatureOptions = () => {
  const [features, setFeatures] = useState(null); // Initialize as null or an empty object

  useEffect(() => {
    const checkLocalStorage = async () => {
      try {
        const localStorageData = getLocalStorage('features_data', 'data');

        if (localStorageData && Array.isArray(localStorageData)) {
          const databaseOptimizer = localStorageData.find(item => item.option === "default_database_optimizer");

          if (databaseOptimizer && databaseOptimizer.value) {
            const databaseValue = JSON.parse(databaseOptimizer.value);


            const { options } = databaseValue;


            setFeatures(options);
          } else {
            setFeatures(null);
          }
        } else {
          setFeatures(null);
        }
      } catch (error) {
        console.error("Error checking localStorage:", error);
        setFeatures(null); // Or handle error state appropriately
      }
    };

    checkLocalStorage();
  }, []);

  return { features };
};

export const UseSearchReplace = () => {
  const [enableSearchReplace, setEnableSearchReplace] = useState(null);

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        const searchReplace = localStorageData.find(item => item.option === "default_search_replace");

        if (searchReplace && searchReplace.value) {
          const searchReplaceValue = JSON.parse(searchReplace.value);
          const isSearchReplaceValue = searchReplaceValue?.options?.field_1?.values?.option?.selected === true;

          setEnableSearchReplace({
            searchReplaceFeature: searchReplace.is_active === "0" || isSearchReplaceValue === false,
            featureId: searchReplace.id || null,
            isActiveState: searchReplace.is_active || null
          });
        } else {
          setEnableSearchReplace({ searchReplaceFeature: false, featureId: null, isActiveState: null });
        }
      } else {
        setEnableSearchReplace({ searchReplaceFeature: false, featureId: null, isActiveState: null });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setEnableSearchReplace({ searchReplaceFeature: false, featureId: null, isActiveState: null });
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();
  }, [checkLocalStorage]);

  return { enableSearchReplace, refreshSearchReplaceStatus: checkLocalStorage };
};

export const CheckIntegrityFeature = () => {
  // Initialize with an object containing default values instead of null
  const [showFileMonteringLogs, setShowFileMonteringLogs] = useState({
    fileMoniterFeature: false,
    featureId: null,
    isActiveState: null,
    isLocked: null
  });
  
  const checkIntegrityFeatureEnable = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');
      
      if (localStorageData && Array.isArray(localStorageData)) {
        const fileMonitoring = localStorageData.find(item => item.option === "default_file_integrity_check");
        
        if (fileMonitoring && fileMonitoring.value) {
          const fileMonitoringValue = JSON.parse(fileMonitoring.value);
          const isAdditionalConditionMet = fileMonitoringValue?.options?.field_1?.values?.option?.selected === true;
          
          setShowFileMonteringLogs({
            fileMoniterFeature: fileMonitoring.is_active === "0" || isAdditionalConditionMet === false,
            featureId: fileMonitoring.id || null,
            isActiveState: fileMonitoring.is_active || null,
            isLocked: fileMonitoringValue.is_locked || null
          });
        } else {
          setShowFileMonteringLogs({
            fileMoniterFeature: false,
            featureId: null,
            isActiveState: null,
            isLocked: null
          });
        }
      } else {
        setShowFileMonteringLogs({
          fileMoniterFeature: false,
          featureId: null,
          isActiveState: null,
          isLocked: null
        });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setShowFileMonteringLogs({
        fileMoniterFeature: false,
        featureId: null,
        isActiveState: null,
        isLocked: null
      });
    }
  }, []);
  
  useEffect(() => {
    checkIntegrityFeatureEnable();
  }, [checkIntegrityFeatureEnable]);
  
  return {
    showFileMonteringLogs,
    refreshIntegrityStatus: checkIntegrityFeatureEnable
  };
};


export const useRedirectionFeature = () => {
  const [enableRedirectionRule, setRedirectionRule] = useState(null);

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        const redirectionRuleFeature = localStorageData.find(item => item.option === "default_redirection_manager");

        if (redirectionRuleFeature && redirectionRuleFeature.value) {
          const redirectionRule = JSON.parse(redirectionRuleFeature.value);
          const isRedirectionRule = redirectionRule?.options?.field_1?.values?.option?.selected === true;

          setRedirectionRule({
            redirectionRuleFeature: redirectionRuleFeature.is_active === "0" || isRedirectionRule === false,
            featureId: redirectionRuleFeature.id || null,
            isActiveState: redirectionRuleFeature.is_active || null
          });
        } else {
          setRedirectionRule({ redirectionRuleFeature: false, featureId: null, isActiveState: null });
        }
      } else {
        setRedirectionRule({ redirectionRuleFeature: false, featureId: null, isActiveState: null });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setRedirectionRule({ redirectionRuleFeature: false, featureId: null, isActiveState: null });
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();
  }, [checkLocalStorage]);

  return { enableRedirectionRule, refreshRedirectionkStatus: checkLocalStorage };
};


export const useBrokenLinkFeature = () => {
  const [enableBrokenLink, setBrokenLink] = useState(null);

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        const brokenLinkFeature = localStorageData.find(item => item.option === "broken_link_checker_settings");

        if (brokenLinkFeature && brokenLinkFeature.value) {
          const brokenLinkValue = JSON.parse(brokenLinkFeature.value);
          const isBrokenLinkValue = brokenLinkValue?.options?.field_1?.values?.option?.selected === true;

          setBrokenLink({
            brokenLinkFeature: brokenLinkFeature.is_active === "0" || isBrokenLinkValue === false,
            featureId: brokenLinkFeature.id || null,
            isActiveState: brokenLinkFeature.is_active || null
          });
        } else {
          setBrokenLink({ brokenLinkFeature: false, featureId: null, isActiveState: null });
        }
      } else {
        setBrokenLink({ brokenLinkFeature: false, featureId: null, isActiveState: null });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setBrokenLink({ brokenLinkFeature: false, featureId: null, isActiveState: null });
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();
  }, [checkLocalStorage]);

  return { enableBrokenLink, refreshBrokenLinkStatus: checkLocalStorage };
};

export const useHardeningAuditFeature = () => {
  const [enableHardeningAudit, setHardeningAudit] = useState(null);

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        // NOTE: option key is a placeholder — adjust once backend ships the actual key.
        const hardeningAuditFeature = localStorageData.find(item => item.option === "default_hardening_audit");

        if (hardeningAuditFeature && hardeningAuditFeature.value) {
          let isHardeningAuditValue = true;
          try {
            const parsed = JSON.parse(hardeningAuditFeature.value);
            isHardeningAuditValue = parsed?.options?.field_1?.values?.option?.selected === true;
          } catch (e) {
            isHardeningAuditValue = true;
          }

          setHardeningAudit({
            hardeningAuditFeature: hardeningAuditFeature.is_active === "0" || isHardeningAuditValue === false,
            featureId: hardeningAuditFeature.id || null,
            isActiveState: hardeningAuditFeature.is_active || null
          });
        } else {
          setHardeningAudit({ hardeningAuditFeature: false, featureId: null, isActiveState: null });
        }
      } else {
        setHardeningAudit({ hardeningAuditFeature: false, featureId: null, isActiveState: null });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setHardeningAudit({ hardeningAuditFeature: false, featureId: null, isActiveState: null });
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();
  }, [checkLocalStorage]);

  return { enableHardeningAudit, refreshHardeningAuditStatus: checkLocalStorage };
};

export const useMoniteringFeature = () => {
  const [moniteringFeature, setMoniteringFeature] = useState(null);

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        const MoniteringFeature = localStorageData.find(item => item.option === "default_monitoring_logs");

        if (MoniteringFeature && MoniteringFeature.value) {
          const moniteringRule = JSON.parse(MoniteringFeature.value);
          const isMoniteringfeature = moniteringRule?.options?.field_1?.values?.option?.selected === true;

          setMoniteringFeature({
            MoniteringFeature: MoniteringFeature.is_active === "0" || isMoniteringfeature === false,
            featureId: MoniteringFeature.id || null,
            isActiveState: MoniteringFeature.is_active || null,
            isLocked: moniteringRule.is_locked || null            
          });
        } else {
          setMoniteringFeature({ MoniteringFeature: false, featureId: null, isActiveState: null, isLocked: null });
        }
      } else {
        setMoniteringFeature({ MoniteringFeature: false, featureId: null, isActiveState: null, isLocked: null });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setMoniteringFeature({ MoniteringFeature: false, featureId: null, isActiveState: null, isLocked: null });
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();
  }, [checkLocalStorage]);

  return { moniteringFeature, refreshMoniteringStatus: checkLocalStorage };
};

export const useTwoFaFeature = () => {
  const [twoFa, setTwoFa] = useState(null);

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        const TwoFaFeature = localStorageData.find(item => item.option === "default_two_step_authenticate");

        if (TwoFaFeature && TwoFaFeature.value) {
          const twoFaRule = JSON.parse(TwoFaFeature.value);
          const isTwoFaRule = twoFaRule?.options?.field_1?.values?.option?.selected === true;

          setTwoFa({
            TwoFaFeature: TwoFaFeature.is_active === "0" || isTwoFaRule === false,
            featureId: TwoFaFeature.id || null,
            isActiveState: TwoFaFeature.is_active || null,
            isLocked: twoFaRule.is_locked || null            
          });
        } else {
          setTwoFa({ TwoFaFeature: false, featureId: null, isActiveState: null, isLocked: null });
        }
      } else {
        setTwoFa({ TwoFaFeature: false, featureId: null, isActiveState: null, isLocked: null });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setTwoFa({ TwoFaFeature: false, featureId: null, isActiveState: null, isLocked: null });
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();
  }, [checkLocalStorage]);

  return { twoFa, refresTwoFaStatus: checkLocalStorage };
};

export const useCronJobFeature = () => {
  const [cronJobRule, setCronJobRule] = useState(null);

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        const cronJobFeature = localStorageData.find(item => item.option === "default_cron_job_list");

        if (cronJobFeature && cronJobFeature.value) {
          const cronJobRule = JSON.parse(cronJobFeature.value);
          const isCronJobRule = cronJobRule?.options?.field_1?.values?.option?.selected === true;

          setCronJobRule({
            cronJobFeature: cronJobFeature.is_active === "0" || isCronJobRule === false,
            featureId: cronJobFeature.id || null,
            isActiveState: cronJobFeature.is_active || null,
            isLocked: cronJobRule.is_locked || null            
          });
        } else {
          setCronJobRule({ cronJobFeature: false, featureId: null, isActiveState: null, isLocked: null });
        }
      } else {
        setCronJobRule({ cronJobFeature: false, featureId: null, isActiveState: null, isLocked: null });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setCronJobRule({ cronJobFeature: false, featureId: null, isActiveState: null, isLocked: null });
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();
  }, [checkLocalStorage]);

  return { cronJobRule, refreshCronJobStatus: checkLocalStorage };
};


export const useFirewallFeature = () => {
  const [fireallRule, setFirewall] = useState(null);

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        const firewallFeature = localStorageData.find(item => item.option === "default_firewall_settings");

        if (firewallFeature && firewallFeature.value) {
          const firewall = JSON.parse(firewallFeature.value);
          const isFirewallEnable = firewall?.options?.field_1?.values?.option?.selected === true;
          const isFeatureActive = firewallFeature.is_active === "1";

          setFirewall({
            // More descriptive naming
            isEnabled: isFeatureActive && isFirewallEnable,
            isFeatureActive: isFeatureActive,
            isFirewallFeatureEnable: isFirewallEnable,
            featureId: firewallFeature.id || null,
            isActiveState: firewallFeature.is_active || null,
            isLocked: firewall.is_locked || null            
          });
        } else {
          // Feature not found or no value
          setFirewall({ 
            isEnabled: false, 
            isFeatureActive: false,
            isFirewallFeatureEnable: false,
            featureId: null, 
            isActiveState: null, 
            isLocked: null 
          });
        }
      } else {
        // No data in localStorage
        setFirewall({ 
          isEnabled: false, 
          isFeatureActive: false,
          isFirewallFeatureEnable: false,
          featureId: null, 
          isActiveState: null, 
          isLocked: null 
        });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setFirewall({ 
        isEnabled: false, 
        isFeatureActive: false,
        isFirewallFeatureEnable: false,
        featureId: null, 
        isActiveState: null, 
        isLocked: null 
      });
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();    
  }, [checkLocalStorage]);

  return { fireallRule, refreshFirewallStatus: checkLocalStorage };
};  

export const useBruteForceFeature = () => {
  const [bruteForceRule, setBruteForceRule] = useState(null);
  // Subscribe to the context so we re-read localStorage whenever the central
  // features list updates. Without this, this hook's useEffect runs once on
  // mount, before FeaturesDataProvider has populated localStorage on hard
  // refresh — leaving featureId / isActiveState permanently null.
  const { featuresData } = useFeaturesData();

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        const bruteForceFeature = localStorageData.find(item => item.option === "default_login_defender_management");

        if (bruteForceFeature && bruteForceFeature.value) {
          const bruteForce = JSON.parse(bruteForceFeature.value);
          // Defensive chain — the schema may rename/remove field_1 (or any sub-option)
          // independently of this hook, so we never throw, just default to false.
          const isBruteForceEnabled = bruteForce?.options?.field_1?.values?.option?.selected === true;
          const isFeatureActive = bruteForceFeature.is_active === "1";

          setBruteForceRule({
            // More descriptive naming
            isEnabled: isFeatureActive && isBruteForceEnabled,
            isFeatureActive: isFeatureActive,
            isBruteForceOptionEnabled: isBruteForceEnabled,
            featureId: bruteForceFeature.id || null,
            isActiveState: bruteForceFeature.is_active || null,
            isLocked: bruteForce.is_locked || null            
          });
        } else {
          // Feature not found or no value
          setBruteForceRule({ 
            isEnabled: false, 
            isFeatureActive: false,
            isBruteForceOptionEnabled: false,
            featureId: null, 
            isActiveState: null, 
            isLocked: null 
          });
        }
      } else {
        // No data in localStorage
        setBruteForceRule({ 
          isEnabled: false, 
          isFeatureActive: false,
          isBruteForceOptionEnabled: false,
          featureId: null, 
          isActiveState: null, 
          isLocked: null 
        });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setBruteForceRule({ 
        isEnabled: false, 
        isFeatureActive: false,
        isBruteForceOptionEnabled: false,
        featureId: null, 
        isActiveState: null, 
        isLocked: null 
      });
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();
  }, [checkLocalStorage, featuresData]);

  return { bruteForceRule, refreshBruteForceStatus: checkLocalStorage };
};

export const useIpFeature = () => {
  const [ipManagement, setIpManagement] = useState(null);

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        const ipManagementFeature = localStorageData.find(item => item.option === "default_ips_managment");

        if (ipManagementFeature && ipManagementFeature.value) {
          const ipManagementRule = JSON.parse(ipManagementFeature.value);
          // Use optional chaining so a missing field_N doesn't throw and wipe out featureId.
          const whitelistManager = ipManagementRule?.options?.field_1?.values?.option?.selected === true;
          const blacklistManager = ipManagementRule?.options?.field_2?.values?.option?.selected === true;
          const countryManager = ipManagementRule?.options?.field_3?.values?.option?.selected === true;

          setIpManagement({
            ipManagementFeature: ipManagementFeature.is_active === "0" || whitelistManager === false,
            featureId: ipManagementFeature.id || null,
            isActiveState: ipManagementFeature.is_active || null,
            isLocked: ipManagementRule?.is_locked || null,
            whitelistManager,
            blacklistManager,
            countryManager
          });
        } else {
          setIpManagement({ ipManagementFeature: false, featureId: null, isActiveState: null, isLocked: null,whitelistManager: false,blacklistManager: false,countryManager: false
          });
        }
      } else {
        setIpManagement({ ipManagementFeature: false, featureId: null, isActiveState: null, isLocked: null,whitelistManager: false,blacklistManager: false,countryManager: false});
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setIpManagement({ ipManagementFeature: false, featureId: null, isActiveState: null, isLocked: null,whitelistManager: false,blacklistManager: false,countryManager: false});
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();
  }, [checkLocalStorage]);

  return { ipManagement, refreshIpStatus: checkLocalStorage };
};

export const useManualInterceptorFeature = () => {
  const [manualInterceptor, setManualInterceptor] = useState(null);

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        const ManualInterceptorFeature = localStorageData.find(item => item.option === "interceptor_settings");

        if (ManualInterceptorFeature && ManualInterceptorFeature.value) {
          const manualInterceptorRule = JSON.parse(ManualInterceptorFeature.value);
          const isManualInterceptorFeature = manualInterceptorRule?.options?.field_1?.values?.option?.selected === true;

          setManualInterceptor({
            ManualInterceptorFeature: ManualInterceptorFeature.is_active === "0" || isManualInterceptorFeature === false,
            featureId: ManualInterceptorFeature.id || null,
            isActiveState: ManualInterceptorFeature.is_active || null,
            isLocked: manualInterceptorRule.is_locked || null            
          });
        } else {
          setManualInterceptor({ ManualInterceptorFeature: false, featureId: null, isActiveState: null, isLocked: null });
        }
      } else {
        setManualInterceptor({ ManualInterceptorFeature: false, featureId: null, isActiveState: null, isLocked: null });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setManualInterceptor({ ManualInterceptorFeature: false, featureId: null, isActiveState: null, isLocked: null });
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();
  }, [checkLocalStorage]);

  return { manualInterceptor, refreshManualInterceptorStatus: checkLocalStorage };
};


export const UseScannerFeature = () => {
  const [enableScanner, setEnableScanner] = useState(null);

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        const scannerFeature = localStorageData.find(item => item.option === "default_malware_scan");

        if (scannerFeature && scannerFeature.value) {
          const scannerValue = JSON.parse(scannerFeature.value);
          const isScannerValue = scannerValue?.options?.field_1?.values?.option?.selected === true;

          setEnableScanner({
            scannerSiteFeature: scannerFeature.is_active === "0" || isScannerValue === false,
            featureId: scannerFeature.id || null,
            isActiveState: scannerFeature.is_active || null,
            isLocked: isScannerValue.is_locked || null
          });
        } else {
          setEnableScanner({ scannerSiteFeature: false, featureId: null, isActiveState: null, isLocked: null });
        }
      } else {
        setEnableScanner({ scannerSiteFeature: false, featureId: null, isActiveState: null, isLocked: null });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setEnableScanner({ scannerSiteFeature: false, featureId: null, isActiveState: null, isLocked: null });
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();
  }, [checkLocalStorage]);

  return { enableScanner, refreshScannerStatus: checkLocalStorage };
};


export const usePasswordLessFeature = () => {
  const [passwordLess, setPasswordLess] = useState(null);

  const checkLocalStorage = useCallback(async () => {
    try {
      const localStorageData = getLocalStorage('features_data', 'data');

      if (localStorageData && Array.isArray(localStorageData)) {
        const PasswordLessFeature = localStorageData.find(item => item.option === "advanced_user_access_control");

        if (PasswordLessFeature && PasswordLessFeature.value) {
          const passwordLessRule = JSON.parse(PasswordLessFeature.value);
          const isPasswordLessRule = passwordLessRule?.options?.field_1?.values?.option?.selected === true;

          setPasswordLess({
            PasswordLessFeature: PasswordLessFeature.is_active === "0" || isPasswordLessRule === false,
            featureId: PasswordLessFeature.id || null,
            isActiveState: PasswordLessFeature.is_active || null,
            isLocked: passwordLessRule.is_locked || null            
          });
        } else {
          setPasswordLess({ PasswordLessFeature: false, featureId: null, isActiveState: null, isLocked: null });
        }
      } else {
        setPasswordLess({ PasswordLessFeature: false, featureId: null, isActiveState: null, isLocked: null });
      }
    } catch (error) {
      console.error("Error checking localStorage:", error);
      setPasswordLess({ PasswordLessFeature: false, featureId: null, isActiveState: null, isLocked: null });
    }
  }, []);

  useEffect(() => {
    checkLocalStorage();
  }, [checkLocalStorage]);

  return { passwordLess, refreshPasswordLessStatus: checkLocalStorage };
}