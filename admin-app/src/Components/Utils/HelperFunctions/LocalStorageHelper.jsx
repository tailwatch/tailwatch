import { showLocalStorageError } from '../../ErrorModal/localStorageErrorHandler';

const STORAGE_KEY = 'WpTailWatch';

// A QuotaExceededError means the cached blob is larger than the browser localStorage
// limit (~5MB). This cache is a best-effort fast-path only: the same data also lives in
// memory (React state / FeaturesDataContext), so a failed write must never crash the app
// or show an error modal - it is simply skipped.
const isQuotaError = (error) =>
    !!error && (
        error.name === 'QuotaExceededError' ||
        error.name === 'NS_ERROR_DOM_QUOTA_REACHED' ||
        error.code === 22 ||
        error.code === 1014
    );

export const getLocalStorage = (section, key) => {
    try {
        const item = localStorage.getItem(STORAGE_KEY);
        if (!item) return undefined;

        const parsed = JSON.parse(item);
        if (parsed && parsed[section] && key in parsed[section]) {
            return parsed[section][key];
        }
        return undefined;
    } catch (error) {
        // Unparseable (corrupted) value: drop it so future writes start clean, then fall
        // back to undefined. Callers already treat a missing cache as "not set".
        console.error('Error parsing localStorage:', error);
        try {
            localStorage.removeItem(STORAGE_KEY);
        } catch (removeError) {
            // Nothing more we can do on the read path.
        }
        return undefined;
    }
};

export const updateLocalStorage = (section, updates, removeKeys = []) => {
    try {
        const item = localStorage.getItem(STORAGE_KEY);
        const store = item ? JSON.parse(item) : {};

        const updatedSection = { ...store[section], ...updates };
        removeKeys.forEach((key) => {
            delete updatedSection[key];
        });

        const next = {
            ...store,
            [section]: updatedSection
        };

        localStorage.setItem(STORAGE_KEY, JSON.stringify(next));
    } catch (error) {
        // Out of quota: the data is too large to cache. The app keeps working from
        // in-memory state, so skip the write silently - no modal, no crash.
        if (isQuotaError(error)) {
            console.warn('Tailwatch: localStorage quota exceeded; skipping cache write for', section);
            return;
        }

        // Not a quota error - the store is likely corrupted. Reset it once with just this
        // update so future reads/writes start clean.
        console.error('Error updating localStorage:', error);
        try {
            localStorage.removeItem(STORAGE_KEY);
            localStorage.setItem(STORAGE_KEY, JSON.stringify({ [section]: updates }));
        } catch (resetError) {
            if (isQuotaError(resetError)) {
                console.warn('Tailwatch: localStorage quota exceeded on reset; skipping cache write.');
                return;
            }
            console.error('Error resetting localStorage:', resetError);
            showLocalStorageError(resetError);
        }
    }
};
