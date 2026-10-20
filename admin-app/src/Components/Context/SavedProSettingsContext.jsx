import React, { createContext, useCallback, useContext, useEffect, useRef, useState } from "react";
import axios from "axios";
import { useFeaturesData } from "./FeaturesDataContext";

/* global tailwatch_ajax */

// The Tailwatch Pro settings a plan lock set aside: what is kept, what was given back, and
// why the kept ones are not in use. Tailwatch Pro answers for them, so they are read while it is
// active: when the page loads, and again when the licence changes on these screens (a connect, a
// disconnect), since that locks or gives back settings. Every screen shares them.
const SavedProSettingsContext = createContext(null);

const EMPTY = { state: null, saved: [], restored: null };

const callAction = async (actionType) => {
  const formData = new FormData();
  formData.append("action", "tailwatch_global_ajax_handler");
  formData.append("action_type", actionType);
  formData.append("nonce", tailwatch_ajax.nonce);

  const response = await axios.post(tailwatch_ajax.ajax_url, formData, {
    headers: { "Content-Type": "multipart/form-data" },
  });

  return response?.data?.data || {};
};

export const SavedProSettingsProvider = ({ children }) => {
  const { fetchFeaturesData, proPluginActive, featureLicenseData } = useFeaturesData();
  const [settings, setSettings] = useState(EMPTY);
  const lastRead = useRef(null);

  // The licence as the features last reported it; a fetch that finds it unchanged reads nothing.
  const readFor = proPluginActive ? JSON.stringify(featureLicenseData ?? null) : null;

  const refresh = useCallback(async () => {
    try {
      const answer = await callAction("tailwatch_get_saved_pro_settings");
      if (answer.code === 200 && answer.data) {
        setSettings({
          state: answer.data.state ?? null,
          saved: answer.data.saved ?? [],
          restored: answer.data.restored ?? null,
        });
      }
    } catch (error) {}
  }, []);

  // Runs one action, then reads the settings again; returns the controller's answer.
  const run = useCallback(
    async (actionType, refreshFeatures = false) => {
      const answer = await callAction(actionType);
      await refresh();
      if (refreshFeatures) {
        await fetchFeaturesData();
      }
      return answer;
    },
    [refresh, fetchFeaturesData]
  );

  useEffect(() => {
    if (null === readFor || lastRead.current === readFor) return;
    lastRead.current = readFor;

    refresh();
  }, [readFor, refresh]);

  return (
    <SavedProSettingsContext.Provider
      value={{
        ...settings,
        refresh,
        forget: () => run("tailwatch_forget_saved_pro_settings"),
        undo: () => run("tailwatch_undo_restored_pro_settings", true),
        dismiss: () => run("tailwatch_dismiss_restored_pro_settings"),
      }}
    >
      {children}
    </SavedProSettingsContext.Provider>
  );
};

// Empty outside the provider, so a screen rendered on its own shows nothing of it.
export const useSavedProSettings = () => useContext(SavedProSettingsContext) || EMPTY;
