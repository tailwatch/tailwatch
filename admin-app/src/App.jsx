import Sidebar from "./Components/Sidebar/Sidebar.jsx";
import { Outlet } from "react-router-dom";
import { useNavigate } from "react-router-dom";
import { LoadingProvider } from './Components/Context/LoadingContext.jsx';
import { Spinner } from "./Components/Spinner/Spinner.jsx";
import { useVerifyVisitStatus } from './Components/Hooks/VerifyVisitStatus/VerifyVisitStatus.jsx';
import { useVerifyStatus } from "./Components/Hooks/VerifyStatus/VerifyStatus.jsx";
import "react-loading-skeleton/dist/skeleton.css";
import { ToastContainer } from 'react-toastify';
import 'react-toastify/dist/ReactToastify.css';
import { FormProvider } from "./Components/Context/FormContext.jsx";
import { FeaturesDataProvider } from './Components/Context/FeaturesDataContext';
import { UserProvider } from './Components/Context/UserContext.jsx';
import { ErrorModalProvider } from './Components/ErrorModal/ErrorModalContext';
import ErrorModal from './Components/ErrorModal/ErrorModal';
import { setupAxiosErrorInterceptor } from './Components/ErrorModal/axiosErrorInterceptor';
import { setupLocalStorageErrorHandler } from './Components/ErrorModal/localStorageErrorHandler';
import { useEffect } from 'react';
import { useErrorModal } from './Components/ErrorModal/ErrorModalContext';
import { Provider } from "react-redux";
import store from "./Components/Redux/Store.jsx";
import { LicenseProvider } from "./Components/Context/LicenseProvider.jsx";
import { useLicense } from './Components/Context/LicenseProvider.jsx';

function ErrorHandlersSetup() {
  const { showError } = useErrorModal();

  useEffect(() => {
    setupAxiosErrorInterceptor(showError);
    setupLocalStorageErrorHandler(showError);
  }, [showError]);

  return null;
}

function MainAppContent({ isLoadingSpinner }) {
  if (isLoadingSpinner) return null;

  return (
    <UserProvider>
      <FeaturesDataProvider>
        <FormProvider>
            <div className="flex h-screen bg-gray-100">
              <div className="h-full flex-shrink-0">
                <Sidebar />
              </div>
              <div className="flex-1 flex flex-col transition-all duration-300 ease-in-out overflow-hidden">
                <div className="h-full overflow-y-auto overflow-x-hidden">
                  <div className="bg-white shadow-md h-full overflow-auto custom-scroll-bar">
                    <Outlet />
                  </div>
                </div>
              </div>
            </div>
            <ToastContainer position="top-center" autoClose={5000} hideProgressBar={false} newestOnTop={false} closeOnClick rtl={false} pauseOnFocusLoss draggable pauseOnHover style={{ width: "auto", minWidth: "200px", maxWidth: "800px" }} />
        </FormProvider>
      </FeaturesDataProvider>
    </UserProvider>
  );
}

function AppContent() {
  const navigate = useNavigate();
  const { isVerifyLoading, visitCompleted } = useVerifyVisitStatus(navigate);
  const { isLoading: isVerifyStatusLoading } = useVerifyStatus(navigate);
  const { loading: isLicenseLoading } = useLicense();
  
  const isLoading = isVerifyLoading || isLicenseLoading || isVerifyStatusLoading;

  return (
    <ErrorModalProvider>
      <ErrorHandlersSetup />
      <ErrorModal />
      <LoadingProvider>
        {isLoading && (
          <div className="fixed inset-0 bg-white z-[500] flex flex-col items-center justify-center">
            <div className="flex justify-center mb-8">
              <Spinner className="w-8 h-8 text-blue-600" />
            </div>
            <div className="text-center mb-6">
              <h2 className="text-3xl font-semibold text-black mb-3">WP Tailwatch</h2>
              <p className="text-black font-medium text-lg">Verifying Request</p>
            </div>
            <div className="text-center mb-12 max-w-md">
              <p className="text-black leading-relaxed">
                Please wait while we authenticate your session...
              </p>
            </div>
          </div>
        )}
        {!isLoading && visitCompleted && (
          <MainAppContent />
        )}
      </LoadingProvider>
    </ErrorModalProvider>
  );
}

function App() {
  return (
    <Provider store={store}>
      <LicenseProvider>
        <AppContent />
      </LicenseProvider>
    </Provider>
  );
}
export default App;