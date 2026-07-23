


import axios from 'axios';

let errorModalShowError = null;

export const setupAxiosErrorInterceptor = (showErrorFn) => {
  errorModalShowError = showErrorFn;

  axios.interceptors.response.use(
    (response) => {
      // Skip ErrorModal for requests tagged with skipErrorModal (e.g. migration polling)
      if (response?.config?.skipErrorModal) return response;

      const data = response?.data;

      // ✅ Check success false + data = 'Invalid nonce'
      if (data?.success === false && data?.data === 'Invalid nonce') {
        if (errorModalShowError) {
          errorModalShowError(
            'general',
            response.config,  // For retry
             'Something Went Wrong',
            'Your session may have expired or the network connection was lost. Please refresh the page and try again.',
            false
          );
        }

        const error = new Error('Invalid nonce');
        error.response = response;
        error.isHandled = true;
        return Promise.reject(error);
      }

      return response;
    },
    (error) => {
      if (error.isHandled) return Promise.reject(error);

      // Skip ErrorModal for requests tagged with skipErrorModal (e.g. migration polling)
      if (error?.config?.skipErrorModal) return Promise.reject(error);

      if (errorModalShowError) {
        const isNetworkError = !navigator.onLine || error.message === 'Network Error' || !error.response;
        errorModalShowError(isNetworkError ? 'network' : 'general', error.config);
      }

      return Promise.reject(error);
    }
  );
};
