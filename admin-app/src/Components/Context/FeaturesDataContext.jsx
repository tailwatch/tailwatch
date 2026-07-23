import { createContext, useContext, useState, useCallback, useMemo, useEffect, useRef } from 'react';
import { GetData } from '../Api/Api';
import { getLocalStorage } from '../Utils/HelperFunctions/LocalStorageHelper';

// Create the context
const FeaturesDataContext = createContext();

// Custom hook to use the context
export const useFeaturesData = () => {
  const context = useContext(FeaturesDataContext);
  if (!context) {
    throw new Error('useFeaturesData must be used within a FeaturesDataProvider');
  }
  return context;
};

// Provider component
export const FeaturesDataProvider = ({ children }) => {
  const [featuresData, setFeaturesData] = useState([]);
  const [loading, setLoading] = useState(true);
  const [proPluginActive, setProPluginActive] = useState(false);
  const [featureLicenseData, setFeatureLicenseData] = useState(null);
  const isFetchingRef = useRef(false);

  const fetchFeaturesData = useCallback(async () => {
    // Guard: if a fetch is already in progress, skip
    if (isFetchingRef.current) return;
    isFetchingRef.current = true;
    setLoading(true);
    try {
      const { features, featureslicenseStatus } = await GetData();
      setFeaturesData(features);
      const proActive = getLocalStorage('features_data', 'pro_plugin_active');
      setProPluginActive(proActive ?? false);
      setFeatureLicenseData(featureslicenseStatus?.license_status ?? null);
      return features;
    } catch (error) {
      setLoading(false);
      return undefined;
    } finally {
      setLoading(false);
      isFetchingRef.current = false;
    }
  }, []);

  // Auto-fetch on app mount — covers hard refresh on any page
  useEffect(() => {
    fetchFeaturesData();
  }, [fetchFeaturesData]);

  const value = useMemo(() => ({
    featuresData,
    featureLicenseData,
    setFeaturesData,
    setLoading,
    loading,
    fetchFeaturesData,
    proPluginActive,
  }), [featuresData, featureLicenseData, loading, proPluginActive, fetchFeaturesData]);

  return (
    <FeaturesDataContext.Provider value={value}>
      {children}
    </FeaturesDataContext.Provider>
  );
};