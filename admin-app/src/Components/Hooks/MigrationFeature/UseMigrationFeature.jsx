import { useState, useMemo, useCallback } from 'react';
import { getLocalStorage } from '../../Utils/HelperFunctions/LocalStorageHelper';
import { useFeaturesData } from '../../Context/FeaturesDataContext';

/**
 * The Restore & Migration feature, as the screens need to see it.
 *
 * Two levels, and they mean different things to the operator:
 *
 * - **parent** — whether the feature card is switched on at all. Off means there is
 *   nothing to configure yet, so the screen offers to switch it on.
 * - **restore / migration** — the two checkboxes inside it. On a switched-on feature these
 *   can still be individually unticked, and then the answer is not "turn the feature on",
 *   it is "open its settings and tick that box". Sending someone to the wrong one of those
 *   is how a person ends up convinced the plugin is broken.
 *
 * Read from the features the app already holds in memory. The browser copy only covers
 * the first paint: it is skipped when it outgrows the storage quota, and it is empty on a
 * first visit, so on its own it would leave the screens with no feature id and their
 * buttons would silently do nothing.
 */
const OPTION = 'default_site_migration';

const ABSENT = {
  featureId: null,
  isActiveState: null,
  parentEnable: false,
  restoreEnabled: false,
  migrationEnabled: false,
};

// null means "not known yet", which the screens treat as neither on nor off.
const describe = (rows) => {
  if (!Array.isArray(rows) || rows.length === 0) {
    return null;
  }

  const row = rows.find((item) => item.option === OPTION);
  if (!row || !row.value) {
    return ABSENT;
  }

  try {
    const parsed = JSON.parse(row.value);
    // A row that exists but is switched off is not "enabled with the boxes unticked" —
    // it is off, and the offer should be to switch it on.
    const parentEnable = String(row.is_active) !== '0';

    return {
      featureId: row.id || null,
      isActiveState: row.is_active != null ? String(row.is_active) : null,
      parentEnable,
      restoreEnabled:
        parentEnable && parsed?.options?.field_1?.values?.option?.selected === true,
      migrationEnabled:
        parentEnable && parsed?.options?.field_2?.values?.option?.selected === true,
    };
  } catch (error) {
    // A stored copy from an older schema: report the feature as absent rather than
    // taking the screen down with it.
    console.error('Error reading the Restore & Migration feature:', error);
    return ABSENT;
  }
};

const fromStorage = () => describe(getLocalStorage('features_data', 'data'));

const UseMigrationFeature = () => {
  const { featuresData, fetchFeaturesData } = useFeaturesData();
  const [stored, setStored] = useState(fromStorage);

  const live = useMemo(() => describe(featuresData), [featuresData]);
  const migrationFeature = live || stored;

  // Asks the site again. The in-memory list updates from this, and the result is also
  // handed back so a click handler can act on it without waiting for a re-render.
  const refreshMigrationFeature = useCallback(async () => {
    const rows = await fetchFeaturesData();
    const fresh = describe(rows) || fromStorage();
    setStored(fresh);
    return fresh;
  }, [fetchFeaturesData]);

  return { migrationFeature, refreshMigrationFeature };
};

export default UseMigrationFeature;
