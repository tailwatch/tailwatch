import React, { useEffect, useLayoutEffect, useState, useRef } from "react";
import PushNotification from "./PushNotification/PushNotification.jsx";
import CanvasContent from "./FaturesContent/CanvasContent.jsx";
import Header from "../../Components/Header/Header.jsx";
import { IoClose, IoRefresh } from "react-icons/io5";
import "react-loading-skeleton/dist/skeleton.css";
import Skeleton from "react-loading-skeleton";
import { useFeatureContent } from "../../Components/Hooks/useFeatureContent/useFeatureContent.jsx";
import FeatureCard from "./FeatureCard/FeatureCard.jsx";
import FilterTabs from "./FiltersTab/FiltersTab.jsx";
import IconButton from "../../Components/Buttons/IconButton.jsx";
import { useFeaturesData } from "../../Components/Context/FeaturesDataContext";
import LoadingBar from "react-top-loading-bar";
import PushNotificationToggle from "../../Components/PushNotificationToggle/PushNotificationToggle.jsx";

const Features = () => {
  
  const { loading, setLoading, fetchFeaturesData,featureLicenseData } = useFeaturesData();
const { showCanvas, selectedFeature, loadingFeature, showSkeleton, isModalOpen, notificationEnabled, selectedFeatureData, handleStatusChange, handleOpenModal, handleCloseModal, handleShowCanvas, handleCloseCanvas, handleToggleChange, setFeaturesData, GetData, setActiveCategory, categoryCounts, searchTerm, setSearchTerm, optimizedFeatures, activeCategory, handleRestoreFeatureOptions, } = useFeatureContent();
  const [isBarLoading, setIsBarLoading] = useState(false);

  const ref = useRef(null);

  useLayoutEffect(() => {
    setLoading(true);
  }, []);

  useEffect(() => {
    fetchFeaturesData();
  }, [fetchFeaturesData]);

  // For Top Loading Bar
  useEffect(() => {
    // Determine if any loading process is active
    const isAnyLoading = loading;

    if (isAnyLoading && !isBarLoading) {
      ref.current.continuousStart();
      setIsBarLoading(true);
    } else if (!isAnyLoading && isBarLoading) {
      ref.current.complete();
      setIsBarLoading(false);
    }
  }, [loading, isBarLoading]);

  return (
    <div>
      <LoadingBar ref={ref} height={3} color="#ec5023" />
      <Header
        title="Features"
        searchQuery={searchTerm}
        setSearchQuery={setSearchTerm}
      />
      <div className="p-2 sm:p-3 lg:p-4">
        <FilterTabs
          activeCategory={activeCategory}
          setActiveCategory={setActiveCategory}
          setSearchTerm={setSearchTerm}
          counts={categoryCounts}
          showPushNotificationToggle={true}
          pushNotificationToggle={
            <PushNotificationToggle
              onStatusChange={handleStatusChange}
              showLabel={true}
              labelText="Real-time Notifications"
            />
          }
        />

        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4 lg:gap-6">
          {loading
            ? Array.from({ length: 8 }).map((_, index) => (
                <div
                  key={index}
                  className="rounded-xl overflow-hidden shadow-lg"
                >
                  <Skeleton height={220} className="sm:!h-[250px]" />
                </div>
              ))
            : optimizedFeatures.map((feature) => (
                <FeatureCard
                  key={feature.id}
                  feature={feature}
                  featureLicenseData={featureLicenseData}
                  isLoading={false}
                  showSkeleton={showSkeleton}
                  loadingFeature={loadingFeature}
                  notificationEnabled={notificationEnabled}
                  handleToggleChange={handleToggleChange}
                  handleShowCanvas={handleShowCanvas}
                  handleOpenModal={handleOpenModal}
                />
              ))}
        </div>

        {optimizedFeatures.length === 0 && !loading && (
          <div className="text-center py-8 sm:py-10">
            <p className="text-gray-500 text-sm sm:text-base lg:text-lg px-4">
              {searchTerm
                ? "No features match your search."
                : activeCategory === "active"
                  ? "No active features found."
                  : "No features found for this category."}
            </p>
          </div>
        )}

        {selectedFeatureData && (
          <div
            className={`fixed inset-0 bg-black bg-opacity-50 z-[1000] flex justify-end transition-opacity duration-300 ${showCanvas ? "opacity-100" : "opacity-0 pointer-events-none"}`}
          >
            <div
              className={`bg-white h-full shadow-lg overflow-hidden w-full sm:w-[calc(100vw-100px)] lg:w-[calc(100vw-257px)] ${showCanvas ? "canvas-enter" : "canvas-exit"}`}
              style={{
                borderTopLeftRadius: "15px",
                borderBottomLeftRadius: "15px",
              }}
            >
              <div className="border-b border-gray-200 shadow-md p-3 sm:p-4 flex justify-between items-center gap-2">
                <h2 className="text-base sm:text-lg lg:text-xl font-semibold truncate">
                  {JSON.parse(selectedFeatureData?.value).title}
                </h2>
                <div className="flex items-center gap-2 sm:gap-3 flex-shrink-0">
                  <IconButton
                    icon={IoRefresh}
                    text="Restore Default"
                    onClick={() =>
                      handleRestoreFeatureOptions(selectedFeatureData?.option)
                    }
                    bgColor="bg-gray-200"
                    hoverBgColor="hover:bg-gray-300"
                    textColor="text-gray-700"
                    className="text-xs sm:text-sm hidden sm:flex"
                    tooltip="Reset to original settings"
                  />
                  <IoRefresh
                    className="sm:hidden cursor-pointer text-gray-600 hover:text-gray-900 w-6 h-6"
                    onClick={() =>
                      handleRestoreFeatureOptions(selectedFeatureData?.option)
                    }
                  />
                  <IoClose
                    className="cursor-pointer text-gray-400 bg-transparent hover:bg-gray-200 hover:text-gray-900 rounded-lg text-sm w-7 h-7 sm:w-8 sm:h-8 flex items-center justify-center"
                    onClick={handleCloseCanvas}
                  />
                </div>
              </div>
              <div className="custom-scroll-bar px-3 sm:px-4 pb-16 overflow-y-auto h-full">
                <CanvasContent featureData={selectedFeatureData} />
              </div>
            </div>
          </div>
        )}

        {isModalOpen && selectedFeature && (
          <PushNotification
            handleCloseModal={handleCloseModal}
            selectedFeature={selectedFeature}
            setFeaturesData={setFeaturesData}
            GetData={GetData}
          />
        )}
      </div>
    </div>
  );
};

export default Features;
