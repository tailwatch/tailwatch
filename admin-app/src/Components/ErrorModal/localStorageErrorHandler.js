let errorModalShowError = null;

// Setup function to be called after ErrorModalProvider is mounted
export const setupLocalStorageErrorHandler = (showErrorFn) => {
  errorModalShowError = showErrorFn;
};

// Function to show error modal for localStorage errors
export const showLocalStorageError = (error) => {
  if (errorModalShowError) {
    const errorMessage = error instanceof SyntaxError 
      ? "Something went wrong. Please refresh the page."
      : "Something went wrong. Please refresh the page.";
    
    // Pass true as the 5th parameter to indicate this is a localStorage error
    errorModalShowError('general', null, 'Error', errorMessage, true);
  }
};