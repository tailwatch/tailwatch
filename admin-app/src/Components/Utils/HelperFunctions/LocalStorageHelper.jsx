import { showLocalStorageError } from '../../ErrorModal/localStorageErrorHandler';

export const getLocalStorage = (section, key) => {
    try {
        const item = localStorage.getItem('WpTailWatch');
        if (!item) return undefined;
        
        const getItem = JSON.parse(item);
        
        if (getItem && getItem[section] && key in getItem[section]) {
            return getItem[section][key];
        }    
        return undefined;
    } catch (error) {
        console.error('Error parsing localStorage:', error);
        // Clear corrupted data
        localStorage.removeItem('WpTailWatch');
        // Show error modal
        showLocalStorageError(error);
        return undefined;
    }
  };
  
  export const updateLocalStorage = (section, updates, removeKeys = []) => {
    try {
        const item = localStorage.getItem('WpTailWatch');
        const getItem = item ? JSON.parse(item) : {};
        
        const updatedSection = { ...getItem[section], ...updates };
        
        removeKeys.forEach((key) => {
            delete updatedSection[key];
        });
    
        const updatedProcess = {
            ...getItem,
            [section]: updatedSection
        };
    
        localStorage.setItem('WpTailWatch', JSON.stringify(updatedProcess));
    } catch (error) {
        console.error('Error parsing localStorage in updateLocalStorage:', error);
        // Clear corrupted data and initialize fresh
        try {
            const freshData = {
                [section]: updates
            };
            localStorage.setItem('WpTailWatch', JSON.stringify(freshData));
        } catch (setError) {
            console.error('Error setting localStorage after corruption:', setError);
            // Show error modal if setting also fails
            showLocalStorageError(setError);
        }
        // Show error modal for JSON parsing errors
        showLocalStorageError(error);
    }
  };