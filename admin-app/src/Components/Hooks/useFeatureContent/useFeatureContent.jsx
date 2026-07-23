import { useState, useEffect, useMemo, useCallback } from 'react';
import { toast } from 'react-toastify';
import { restoreFeatureOptions } from '../../../Pages/Features/FeatureServices/FeatureServices';
import { GetData, InsertData } from '../../../Components/Api/Api';
import { useFeaturesData } from '../../../Components/Context/FeaturesDataContext';
import { useFormData } from '../../Context/FormContext';
import { alertService } from '../../../Components/AlertService/AlertService';
export const useFeatureContent = () => {  
  const [searchTerm, setSearchTerm] = useState("");
  const { featuresData, loading,fetchFeaturesData, setFeaturesData } = useFeaturesData();
  const { setLoading, clearFormData } = useFormData();
  const [showCanvas, setShowCanvas] = useState(false);
  const [selectedFeature, setSelectedFeature] = useState(null);
  const [loadingFeature, setLoadingFeature] = useState(null);
  const [showSkeleton, setShowSkeleton] = useState(false);
  const [isModalOpen, setIsModalOpen] = useState(false);
  const [notificationEnabled, setNotificationEnabled] = useState(false);
  const [activeCategory, setActiveCategory] = useState('all');
  const [categoryCounts, setCategoryCounts] = useState({});


  // Parse each feature value once — reused by both counts and filter
  const parsedFeatures = useMemo(() => {
    if (loading) return [];
    return featuresData.map(feature => ({
      feature,
      featureValue: JSON.parse(feature.value),
    }));
  }, [featuresData, loading]);

  // Update category counts whenever parsedFeatures changes
  useEffect(() => {
    if (loading) return;
    const counts = {
      all: featuresData.length,
      active: 0, security: 0, optimization: 0,
      performance: 0, recovery: 0, monitoring: 0, pro: 0,
    };
    parsedFeatures.forEach(({ feature, featureValue }) => {
      const categories = featureValue.category || [];
      if (feature.is_active === "1") counts.active++;
      if (featureValue.feature_type === 'pro') counts.pro++;
      if (Array.isArray(categories)) {
        categories.forEach(cat => {
          const catLower = cat.toLowerCase();
          if (counts[catLower] !== undefined) counts[catLower]++;
        });
      }
    });
    setCategoryCounts(counts);
  }, [parsedFeatures, featuresData.length, loading]);

  // Derived filtered list — no state, no extra render
  const filteredFeatures = useMemo(() => {
    return parsedFeatures
      .filter(({ feature, featureValue }) => {
        const categories = featureValue.category || [];
        if (activeCategory === 'all') return true;
        if (activeCategory === 'active') return feature.is_active === "1";
        if (activeCategory === 'pro') return featureValue.feature_type === 'pro';
        return Array.isArray(categories) &&
          categories.some(cat => cat.toLowerCase() === activeCategory.toLowerCase());
      })
      .map(({ feature }) => feature);
  }, [parsedFeatures, activeCategory]);

  const handleStatusChange = useCallback((status) => {
    setNotificationEnabled(status);
  }, []);

  const handleOpenModal = useCallback((featureId) => {
    const feature = featuresData.find((feature) => feature.id === featureId);
    if (notificationEnabled) {
      setIsModalOpen(true);
      setSelectedFeature(feature);
    }
  }, [featuresData, notificationEnabled]);

  const handleCloseModal = useCallback(() => {
    setIsModalOpen(false);
  }, []);

  const handleShowCanvas = useCallback((featureId, featuresOverride) => {
    const list = featuresOverride || featuresData;
    const feature = list.find((feature) => feature.id === featureId);
    if (feature) {
      setSelectedFeature(featureId);
      setShowCanvas(true);
    }
  }, [featuresData]);

  const handleCloseCanvas = useCallback((callback) => {
    setShowCanvas(false);
    setSelectedFeature(null);
    clearFormData();
    if (callback && typeof callback === 'function') {
      callback();
    }
  }, [clearFormData]);

  const handleToggleChange = useCallback(async (featureId, is_active, afterInsertCallback = null) => {
    if (loadingFeature) return;
    setLoadingFeature(featureId);
    setShowSkeleton(true);

    const rollbackOptimisticUpdate = () => {
      setFeaturesData((prevFeaturesData) =>
        prevFeaturesData.map((feature) =>
          feature.id === featureId ? { ...feature, is_active: is_active } : feature
        )
      );
    };

    const togglePromise = new Promise(async (resolve, reject) => {
      try {
        // Convert string "1"/"0" to boolean for sending to backend
        const newActiveState = is_active === "1" ? false : true;

        setFeaturesData((prevFeaturesData) =>
          prevFeaturesData.map((feature) =>
            feature.id === featureId
              ? { ...feature, is_active: newActiveState ? "1" : "0" }
              : feature
          )
        );

        const response = await InsertData(featureId, newActiveState);

        // InsertData returns response1.data, so the shape here is { success, data: { … } }.
        // Read the success flag at response.success and the error message at response.data.message.
        if (response?.success === false) {
          const errorMessage = response?.data?.message || 'Failed to update feature status.';
          toast.error(errorMessage);
          rollbackOptimisticUpdate();
          reject();
          return;
        }

        // InsertData returns undefined for blocked-by-process / 409 — the alertService
        // warning already surfaced the reason, so just bail out without a success toast.
        if (!response) {
          rollbackOptimisticUpdate();
          reject();
          return;
        }

        // Execute the callback after InsertData if it exists
        if (afterInsertCallback && typeof afterInsertCallback === 'function') {
          await afterInsertCallback(response, newActiveState);
        }

        resolve();
      } catch (error) {
        rollbackOptimisticUpdate();
        reject();
      } finally {
        setLoadingFeature(null);
        setShowSkeleton(false);
      }
    });

    togglePromise
      .then(() => {
        toast.success(
          `Feature ${is_active === "1" ? "deactivated" : "activated"} successfully`
        );
      })
      .catch(() => {
        // failure toasts are emitted from inside the promise with the real backend message
      });

    // Return the underlying togglePromise so callers (e.g. LockCard) can await it
    // and roll back their own local UI state when the toggle fails.
    return togglePromise;
  }, [loadingFeature, setFeaturesData]);

  const selectedFeatureData = useMemo(
    () => featuresData.find(feature => feature.id === selectedFeature),
    [featuresData, selectedFeature]
  );

  // Optimize feature layout function
  const optimizeFeatureLayout = (features) => {
    if (!features || features.length === 0) return [];

    // Pre-parse all values once into a Map — no repeated JSON.parse
    const parsedMap = new Map(
      features.map(f => [f.id, parseInt(JSON.parse(f.value).col || "1")])
    );

    const sortedFeatures = [...features].sort(
      (a, b) => parsedMap.get(b.id) - parsedMap.get(a.id)
    );

    const result = [];
    const rows = [];
    const rowSize = 4;
    const placedIds = new Set(); // O(1) lookup instead of result.find()

    // First pass: place large items (2+ columns)
    sortedFeatures.forEach(feature => {
      const colSize = parsedMap.get(feature.id);
      if (colSize <= 1) return;

      let placed = false;
      for (let i = 0; i < rows.length; i++) {
        if (rows[i] + colSize <= rowSize) {
          rows[i] += colSize;
          result.push({ ...feature, rowIndex: i });
          placedIds.add(feature.id);
          placed = true;
          break;
        }
      }
      if (!placed) {
        rows.push(colSize);
        result.push({ ...feature, rowIndex: rows.length - 1 });
        placedIds.add(feature.id);
      }
    });

    // Second pass: fill with small items (1 column)
    sortedFeatures.forEach(feature => {
      const colSize = parsedMap.get(feature.id);
      if (colSize > 1) return;
      if (placedIds.has(feature.id)) return; // O(1)

      let placed = false;
      for (let i = 0; i < rows.length; i++) {
        if (rows[i] + colSize <= rowSize) {
          rows[i] += colSize;
          result.push({ ...feature, rowIndex: i });
          placed = true;
          break;
        }
      }
      if (!placed) {
        rows.push(colSize);
        result.push({ ...feature, rowIndex: rows.length - 1 });
      }
    });

    result.sort((a, b) => {
      if (a.rowIndex !== b.rowIndex) return a.rowIndex - b.rowIndex;
      return parsedMap.get(b.id) - parsedMap.get(a.id);
    });

    return result.map(({ rowIndex, ...rest }) => rest);
  };

  // Apply search filter and optimize layout
  const optimizedFeatures = useMemo(() => {
    const filtered = filteredFeatures.filter((feature) => {
      const featureValue = JSON.parse(feature.value);
      return featureValue.title.toLowerCase().includes(searchTerm.toLowerCase());
    });
    
    // Apply optimization only if we have features to optimize
    return optimizeFeatureLayout(filtered);
  }, [filteredFeatures, searchTerm]);

  const handleRestoreFeatureOptions = async (featureOption) => {    
    const confirmed = await alertService.InfoData("This can't be undone. it resets to default", "Are you sure to reset feature data?");
        if (!confirmed) {
            return;
        }
    await restoreFeatureOptions({ featureOption,fetchFeaturesData,setLoading });
  }
  return {
    featuresData,
    loading,
    showCanvas,
    selectedFeature,
    loadingFeature,
    showSkeleton,
    isModalOpen,
    notificationEnabled,
    selectedFeatureData,    
    handleStatusChange,
    handleOpenModal,
    handleCloseModal,
    handleShowCanvas,
    handleCloseCanvas,
    handleToggleChange,
    setFeaturesData,
    GetData,
    setActiveCategory,
    categoryCounts,
    filteredFeatures,
    activeCategory,
    optimizeFeatureLayout,
    optimizedFeatures,
    searchTerm, setSearchTerm,handleRestoreFeatureOptions,fetchFeaturesData
  };
};
