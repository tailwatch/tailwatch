import "react-loading-skeleton/dist/skeleton.css";
import Header from "../../Components/Header/Header.jsx";
import GauegeChart from "../Graphs/GauegeChart";
import { HomeSkelton } from "../../Components/Skeleton/LoaderSkeleton.jsx";
import Widgets from "./Widgets/Widgets.jsx";
import DashboardNotifications from "./DashboardNotifications/DashboardNotifications.jsx";
import { verifySslDetail } from './HomeServices/HomeServices.jsx'
import ScanningWidgets from "./ScanningWidgets/ScanningWidgets.jsx";
import VerifySsl from './VerifySsl/VerifySsl.jsx';
import PieChart from "../Graphs/PieChart.jsx";
import DiskManagement from "./DiskManagement/DiskManagement.jsx";
import { Loader2 } from 'lucide-react';
import LoadingBar from 'react-top-loading-bar';
import { useHome } from "../../Components/Hooks/useHome/useHome.jsx";
import UserManagemntWidget from "./UserManagemntWidget/UserManagemntWidget.jsx";


const Home = () => {
  const { ref, loadingLogs, widgetData, loadingGraph, isValue, handleCalculateScore, notificationsData, diskData, fetchData, loadingDisk, loadingVerify, verifySslData,
    setVerifysslData, setLoadingVerify, isDisabled, setIsDisabled, loadingScanning, scanningFeatures } = useHome();

  return (
    <div>
      <Header title="Dashboard" />
      <LoadingBar ref={ref} height={3} color="#ec5023" />
      <div className="p-4">
        {loadingLogs ? (
          <HomeSkelton section="logs" />
        ) : (
          <Widgets
            widgetData={widgetData}
            verifySslData={verifySslData}
            verifySslDetail={verifySslDetail}
            setVerifysslData={setVerifysslData}
            setLoadingVerify={setLoadingVerify}
            sslIsDisabled={isDisabled}
            setIsDisabled={setIsDisabled}
          />)}
        {loadingGraph ? (
          <div className="pt-5 mt-5 h-full flex bg-gray-100 items-center justify-center min-h-[456px] rounded-lg">
            <div className="text-center">
              <div className="mb-3">
                <div className="mx-auto h-8 w-8 border-4 border-gray-300 border-t-orange-600 rounded-full animate-spin" />
              </div>
              <p className="text-lg font-medium text-gray-700">Analyzing Security, please wait...</p>
            </div>
          </div>
        ) : (
          <div className="pt-5 h-full">
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <div className="col-span-1">
                <GauegeChart isValue={isValue} onRefetch={() => handleCalculateScore()} />
              </div>
              <div className="col-span-1">
                <DashboardNotifications notificationsData={notificationsData} handleCalculateScore={handleCalculateScore} />
              </div>
            </div>
          </div>
        )}
        <div className="py-5">
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <div className="col-span-1">
              <PieChart data={diskData} fetchData={fetchData} isLoading={loadingDisk} />
            </div>
            <div className="col-span-1">
              <DiskManagement data={diskData || {}} fetchData={fetchData} isLoading={loadingDisk} />
            </div>
          </div>
        </div>

        {loadingScanning ? (
          <HomeSkelton section="scanning" />
        ) : (
          <>
            <ScanningWidgets scanningFeatures={scanningFeatures} />

          </>)}
        <div className="py-5">
          <UserManagemntWidget />
        </div>
      </div>
    </div>
  );
};

export default Home;