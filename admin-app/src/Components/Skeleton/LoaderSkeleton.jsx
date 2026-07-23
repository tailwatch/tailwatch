import React from "react";
import Skeleton from "react-loading-skeleton";
import "react-loading-skeleton/dist/skeleton.css";


export const PluginCardSkeleton = () => {
  return (
    <div className="border border-gray-200 rounded-lg p-4 bg-white">
      <div className="flex items-start gap-4 mb-3">
        {/* Plugin Icon */}
        <Skeleton width={100} height={100} className="rounded-lg flex-shrink-0" />
        
        <div className="flex-1">
          {/* Plugin Title and Install Button */}
          <div className="flex items-start justify-between mb-2">
            <Skeleton width="100%" height={24} />
            {/* <Skeleton width={100} height={36} className="rounded" /> */}
          </div>
          
          {/* Plugin Description */}
          <div className="space-y-2 mb-2">
            <Skeleton width="100%" height={16} />
            <Skeleton width="100%" height={16} />
          </div>
          
          {/* Author */}
          <Skeleton width={120} height={14} />
        </div>
      </div>
      
      {/* Bottom Stats Row */}
      <div className="flex items-center justify-between pt-3 border-t border-gray-100">
        <div className="flex items-center gap-4">
          <Skeleton width={100} height={16} />
          <Skeleton width={180} height={16} />
        </div>
        {/* <div className="flex items-center gap-2">
          <Skeleton width={150} height={16} />
          <Skeleton width={200} height={16} />
        </div> */}
      </div>
    </div>
  );
};

export const TwoFaAuthenticateLoader = () => {
  return (
    <div className="flex flex-col gap-4 p-6">
 
      {/* Authentication Overview Card */}
      <div className="border border-gray-200 rounded-xl p-6 bg-white shadow-md">
        {/* Title */}
        <Skeleton width={220} height={24} className="mb-2" />
 
        {/* Description */}
        <Skeleton width="80%" height={14} className="mb-1" />
        <Skeleton width="60%" height={14} className="mb-5" />
 
        {/* Buttons */}
        <div className="flex gap-3">
          <Skeleton width={210} height={36} borderRadius={6} />
          <Skeleton width={150} height={36} borderRadius={6} />
        </div>
      </div>
 
      {/* Google Authenticator Setup Card */}
      <div className="border border-gray-200 rounded-xl bg-white overflow-hidden shadow-md">
 
        {/* Card Header */}
        <div className="px-6 pt-6 pb-5">
          <Skeleton width={240} height={22} className="mb-2" />
          <Skeleton width={320} height={14} />
        </div>
 
        <hr className="border-gray-200" />
 
        {/* Step 1 */}
        <div className="flex items-start gap-4 px-6 py-5">
          <Skeleton circle width={32} height={32} className="shrink-0" />
          <div className="flex-1">
            <Skeleton width={220} height={18} className="mb-2" />
            <Skeleton width="70%" height={14} />
          </div>
        </div>
 
        <hr className="border-gray-200" />
 
        {/* Step 2 */}
        <div className="flex items-start gap-4 px-6 py-5">
          <Skeleton circle width={32} height={32} className="shrink-0" />
          <div className="flex-1">
            <Skeleton width={180} height={18} className="mb-2" />
            <Skeleton width="55%" height={14} />
          </div>
        </div>
 
      </div>
    </div>
  );
};
export const SystemSettingSkeleton = () => {
  return (
    <div className="p-4">
      {/* Tab Bar */}
      <div className="flex gap-6 mb-6">
        {Array(6)
          .fill(0)
          .map((_, i) => (
            <div key={i} className="h-8 w-24 bg-gray-200 animate-pulse rounded-md" />
          ))}
      </div>

      {/* Two-column card layout */}
      <div className="grid grid-cols-2 gap-4">
        {/* Left Card — PHP Configuration */}
        <div className="border border-gray-200 rounded-xl p-6 bg-white">
          {/* Card Header */}
          <div className="flex justify-between items-center mb-6">
            <div className="flex items-center gap-2">
              <div className="h-5 w-5 bg-gray-200 animate-pulse rounded" />
              <div className="h-5 w-40 bg-gray-200 animate-pulse rounded" />
            </div>
            <div className="h-4 w-4 bg-gray-200 animate-pulse rounded" />
          </div>

          {/* Rows */}
          {Array(6)
            .fill(0)
            .map((_, i) => (
              <div key={i} className="flex justify-between items-start mb-5">
                <div className="space-y-1.5">
                  <div className="h-3.5 w-32 bg-gray-200 animate-pulse rounded" />
                  <div className="h-3 w-24 bg-gray-200 animate-pulse rounded" />
                </div>
                <div className="h-3.5 w-12 bg-gray-200 animate-pulse rounded" />
              </div>
            ))}
        </div>

        {/* Right Card — PHP Settings */}
        <div className="border border-gray-200 rounded-xl p-6 bg-white">
          {/* Card Header */}
          <div className="flex items-center gap-2 mb-6">
            <div className="h-5 w-5 bg-gray-200 animate-pulse rounded-full" />
            <div className="h-5 w-28 bg-gray-200 animate-pulse rounded" />
          </div>

          {/* Rows */}
          {[128, 80, 112, 64, 80, 64, 64, 144, 176, 192, 48].map((w, i) => (
            <div key={i} className="flex justify-between items-center mb-5">
              <div className="h-3.5 w-32 bg-gray-200 animate-pulse rounded" />
              <div
                className="h-3.5 bg-gray-200 animate-pulse rounded"
                style={{ width: `${w}px` }}
              />
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

export const HomeSkelton = ({ section }) => {
  switch (section) {
    case "logs":
      return (
   <div className="pb-4 mt-5 flex flex-col lg:flex-row gap-6">
  {[...Array(4)].map((_, i) => (
    <div
      key={i}
      className="w-full lg:w-[23%]"
    >
      <div className="relative flex flex-col min-w-0 break-words bg-[#F3F4F6] shadow-soft-xl rounded-2xl bg-clip-border">
        <div className="flex-auto px-4 py-8">
          <Skeleton width="80%" height={25} className="mb-2" />
          <Skeleton width="60%" height={35} />
        </div>
      </div>
    </div>
  ))}
</div>
      );
    case "graph":
      return (
        <div className="py-6">
          <Skeleton height={456} borderRadius={12} />
        </div>
      );
    case "features":
      return (
        <div className="py-6">
          <Skeleton height={247} borderRadius={12} />
        </div>
      );
    case "verify":
      return (
        <div className="py-6">
          <Skeleton height={247} borderRadius={12} />
        </div>
      );
    case "scanning":
      return (
        <div className="flex py-6 space-x-5">
          {[...Array(2)].map((_, i) => (
            <div key={i} style={{ flex: "1 0 auto" }}>
              <Skeleton height={456} borderRadius={12} />
            </div>
          ))}
        </div>
      );
    default:
      return null;
  }
};

export const LoaderBackupInner = () => {
  return (
    <div className="p-6 bg-white">
      {/* Header Section */}
      <div className="mb-6">
        <Skeleton height={32} width={200} className="mb-2" />
        <Skeleton height={16} width={350} />
      </div>

      {/* Migration XML Card */}
      <div className="mb-8 p-4 bg-gray-50 rounded-lg border">
        <div className="flex items-center justify-between">
          <div className="flex items-center gap-4">
            <Skeleton height={48} width={48} />
            <div>
              <div className="flex items-center gap-2 mb-1">
                <Skeleton height={20} width={120} />
                <Skeleton height={16} width={40} />
              </div>
              <Skeleton height={14} width={200} />
            </div>
          </div>
          <Skeleton height={40} width={140} />
        </div>
      </div>

      {/* Backup Files Section */}
      <div className="border-2 border-dashed border-gray-300 rounded-lg p-16 text-center bg-gray-50">
        <div className="flex flex-col items-center">
          <Skeleton height={64} width={64} className="mb-6" />
          <Skeleton height={24} width={180} className="mb-4" />
          <Skeleton height={16} width={320} className="mb-8" />
          <Skeleton height={44} width={180} />
        </div>
      </div>
    </div>
  );
};

export const GeneraSkeleton = () => {
  return (
    <div className="space-y-6 px-4 py-6">
      {/* Section Title */}
     <Skeleton width="100%" height={20} />
      {/* Import & Export Boxes */}
      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        {/* Import Features Data */}
        <div className="border rounded-lg p-6">
          <div className="flex items-center space-x-3">
            <Skeleton circle width={40} height={40} />
            <div className="flex-1">
              <Skeleton width={150} height={18} />
              <Skeleton className="w-full md:w-[250px]" height={14} />
            </div>
          </div>
          <div className="mt-4">
            <Skeleton width={120} height={36} />
            <Skeleton width={90} height={14} className="mt-2" />
          </div>
        </div>

        {/* Export Features Data */}
        <div className="border rounded-lg p-6">
          <div className="flex items-center space-x-3">
            <Skeleton circle width={40} height={40} />
            <div className="flex-1">
              <Skeleton width={150} height={18} />
              <Skeleton className="w-full md:w-[250px]" height={14} />
            </div>
          </div>
          <div className="mt-4">
            <Skeleton width={120} height={36} />
            <Skeleton width={90} height={14} className="mt-2" />
          </div>
        </div>
   
   

   {/* Reset Data Section */}
       <div className="border rounded-lg p-6">
          <div className="flex items-center space-x-3">
            <Skeleton circle width={40} height={40} />
            <div className="flex-1">
              <Skeleton width={150} height={18} />
              <Skeleton className="w-full md:w-[250px]" height={14} />
            </div>
          </div>
          <div className="mt-4">
            <Skeleton width={120} height={36} />
            <Skeleton width={90} height={14} className="mt-2" />
          </div>
        </div>

      </div>

   

      {/* Important Notice */}
      <div className="bg-gray-100 rounded-md px-4 py-3">
        <Skeleton className="w-full md:w-[250px]" height={14} />
      </div>
    </div>
  );
};
export const MigrationSkeleton = () => {
  return (
    <div className="max-w-4xl mx-auto p-6 bg-white">
      {/* Header */}
      <div className="text-center mb-8">
        <Skeleton height={32} width={250} className="mx-auto mb-3" />
        <Skeleton height={20}  className="mx-auto" />
      </div>

      {/* Migration Cards Container */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        
        {/* Auto Migration Card Skeleton */}
        <div className="border border-gray-200 rounded-lg p-6 bg-gray-50">
          {/* Icon */}
          <div className="flex justify-center mb-4">
            <Skeleton circle height={60} width={60} />
          </div>
          
          {/* Title */}
          <div className="text-center mb-4">
            <Skeleton height={28} width={180} className="mx-auto" />
          </div>
          
          {/* Description */}
          <div className="text-center mb-6">
            <Skeleton height={16} width="100%" className="mb-2" />
            <Skeleton height={16} width="90%" className="mx-auto mb-2" />
            <Skeleton height={16} width="80%" className="mx-auto" />
          </div>
          
          {/* Features List */}
          <div className="space-y-3 mb-6">
            {[1, 2, 3].map((item) => (
              <div key={item} className="flex items-center gap-3">
                <Skeleton circle height={16} width={16} />
                <Skeleton height={16} width={150} />
              </div>
            ))}
          </div>
          
          {/* Quick & Easy Badge */}
          <div className="bg-green-100 rounded-lg p-3 text-center">
            <Skeleton height={18} width={120} className="mx-auto" />
          </div>
        </div>

        {/* Manual Migration Card Skeleton */}
        <div className="border border-gray-200 rounded-lg p-6 bg-gray-50">
          {/* Icon */}
          <div className="flex justify-center mb-4">
            <Skeleton circle height={60} width={60} />
          </div>
          
          {/* Title */}
          <div className="text-center mb-4">
            <Skeleton height={28} width={200} className="mx-auto" />
          </div>
          
          {/* Description */}
          <div className="text-center mb-6">
            <Skeleton height={16} width="100%" className="mb-2" />
            <Skeleton height={16} width="95%" className="mx-auto mb-2" />
            <Skeleton height={16} width="85%" className="mx-auto" />
          </div>
          
          {/* Features List */}
          <div className="space-y-3 mb-6">
            {[1, 2, 3].map((item) => (
              <div key={item} className="flex items-center gap-3">
                <Skeleton circle height={16} width={16} />
                <Skeleton height={16} width={170} />
              </div>
            ))}
          </div>
          
          {/* Full Control Badge */}
          <div className="bg-orange-100 rounded-lg p-3 text-center">
            <Skeleton height={18} width={100} className="mx-auto" />
          </div>
        </div>
      </div>

      {/* Footer Help Text */}
      <div className="text-center">
        <Skeleton height={16} width={300} className="mx-auto" />
      </div>
    </div>
  );
};

export const LicenseTabSkeleton = () => {
  return (
    <div>
      <div className="mb-4 w-full">
        <div className="w-full h-10 bg-gray-300 rounded animate-pulse"></div>
      </div>
      <div className="mt-2 w-full h-[176px] bg-gray-300"></div>
    </div>
  );
};

export const GenraltabSkeleton = () => {
  return (
    <div className="flex flex-col gap-5">
      <div className="mb-4 w-[400px] h-[38px]">
        <div className="w-full h-10 bg-gray-300 rounded animate-pulse"></div>
      </div>
      <div className="mb-4 flex gap-3">
        <div className="w-[40px] h-[26px] bg-gray-300 animate-pulse"></div>
        <div className="w-[300px] h-[26px] bg-gray-300 rounded animate-pulse"></div>
      </div>
      <div className="mb-4 flex gap-3">
        <div className="w-[40px] h-[26px] bg-gray-300 animate-pulse"></div>
        <div className="w-[300px] h-[26px] bg-gray-300 rounded animate-pulse"></div>
      </div>
      <div className="mb-4 flex gap-3">
        <div className="w-[40px] h-[26px] bg-gray-300 animate-pulse"></div>
        <div className="w-[300px] h-[26px] bg-gray-300 rounded animate-pulse"></div>
      </div>
    </div>
  )
}

export const TwoFALoader  = () => {
  return (
    <div className="p-6">
     
      <div className="flex items-start mb-6">
        <Skeleton circle height={32} width={32} className="mr-4" />
        <div>
          <Skeleton height={20} width={220} className="mb-2" />
          <Skeleton height={15} width={300} />
        </div>
      </div>

      <div className="flex items-start mb-6">
        <Skeleton circle height={32} width={32} className="mr-4" />
        <div>
          <Skeleton height={20} width={180} className="mb-2" />
          <Skeleton height={15} width={280} />
        </div>
      </div>

      <div className="flex justify-center my-8 p-5 bg-white rounded-lg shadow-sm">
        <Skeleton height={180} width={180} />
      </div>

      <div className="flex items-start mt-8">
        <Skeleton circle height={32} width={32} className="mr-4" />
        <div className="flex-1">
          <Skeleton height={20} width={250} className="mb-2" />
          <Skeleton height={15} width={300} className="mb-4" />
          <div className="sm:flex items-center gap-3">
            <Skeleton height={40} width={120} className="mb-3 sm:mb-0" />
            <Skeleton height={40} width={160} />
          </div>
        </div>
      </div>
    </div>
  )
}

export const SkeletonBrokenLink = () => {
  return (
    <div className="w-full bg-gray-100 p-4">
      {/* Header Skeleton */}
      <div className="flex justify-between items-center mb-6">
        <div className="w-48 h-6 bg-gray-200 rounded animate-pulse"></div>
        <div className="w-56 h-10 bg-gray-200 rounded animate-pulse"></div>
      </div>

      {/* Controls Skeleton */}
      <div className="bg-gray-200 p-4 rounded-md mb-6">
        <div className="flex justify-between items-center">
          <div className="flex gap-3">
            <div className="w-24 h-8 bg-gray-300 rounded animate-pulse"></div>
            <div className="w-36 h-8 bg-gray-300 rounded animate-pulse"></div>
            <div className="w-36 h-8 bg-gray-300 rounded animate-pulse"></div>
          </div>
          <div className="w-64 h-10 bg-gray-300 rounded animate-pulse"></div>
        </div>
      </div>

      {/* Table Header Skeleton */}
      <div className="h-12 bg-gray-300 rounded-t-md mb-1 animate-pulse"></div>

      {/* Table Rows Skeleton */}
      {[1, 2, 3].map((item) => (
        <div 
          key={item} 
          className="h-20 bg-gray-200 mb-1 rounded animate-pulse"
          style={{ animationDelay: `${item * 100}ms` }}
        ></div>
      ))}
      
      {/* Pagination Skeleton */}
      <div className="flex justify-end mt-4">
        <div className="w-64 h-8 bg-gray-200 rounded animate-pulse"></div>
      </div>
    </div>
  );
};

export const UpdateHeaderLoader = () => (
  <div className="w-full">
    <div className="flex justify-between items-center mb-6 w-full">
      <div className="flex items-center space-x-4 w-full">
        <div className="w-12 h-12 bg-gray-300 rounded-full"></div>
        <div className="w-full">
          <div className="w-32 h-6 bg-gray-300 mb-2"></div>
        </div>
      </div>
    </div>
  </div>
);

export const UpdateDescriptionLoader = () => (
  <div className="max-w-full mt-[25px] mx-auto animate-pulse w-full">
    <div className="flex gap-x-4 space-x-8 w-full">
      <div className="w-full">
        <div className="border-b border-zinc-300 mb-4 w-full">
          <nav className="flex space-x-4 w-full">
            <div className="w-16 h-4 bg-gray-300"></div>
          </nav>
        </div>
        <div className="w-full">
          <div className="w-32 h-6 bg-gray-300 mb-2"></div>
          <div className="w-full h-6 bg-gray-300 mb-2"></div>
          <div className="w-full h-6 bg-gray-300 mb-2"></div>
          <div className="w-full h-6 bg-gray-300 mb-2"></div>
          <div className="w-1/2 h-6 bg-gray-300"></div>
        </div>
      </div>
    </div>
  </div>
);

export const UpdateDetailLoader = () => (
  <div className="w-full mt-[7px]">
    <div className="border-b border-zinc-300 mb-4 w-full">
      <div className="w-32 h-5 bg-gray-300 mb-2"></div>
    </div>
    <div className="w-full">
      {Array(6).fill(0).map((_, index) => (
        <div className="flex justify-between pt-[10px] mb-2 w-full" key={index}>
          <div className="w-1/3 h-4 bg-gray-300"></div>
          <div className="w-1/3 h-4 bg-gray-300"></div>
        </div>
      ))}
    </div>
    <div className="w-24 h-8 bg-gray-300 mt-[15px] rounded-lg"></div>
  </div>
);

export const CustomRoleSkeleton = () => {
  return (
    <div className="animate-pulse w-full">
      <div className="h-6 bg-gray-200 rounded w-3/4 mb-4"></div>

      <div className="space-y-4">
        {[1, 2, 3, 4, 5].map((index) => (
          <div key={index} className="flex items-center">
            <div className="flex-1">
              <div className="h-4 bg-gray-200 rounded w-1/2 mb-1"></div>
              <div className="h-3 bg-gray-200 rounded w-1/3"></div>
            </div>
            <div className="w-4 h-4 rounded-full bg-gray-200 mr-2"></div>
          </div>
        ))}
      </div>

      <div className="flex justify-end mt-6">
        <div className="h-10 bg-gray-200 rounded w-24 mr-3"></div>
        <div className="h-10 bg-gray-200 rounded w-24"></div>
      </div>
    </div>
  );
};

export const NotificationSkelton = () => {
  return (
    <div>
      <div className="mb-4 ml-[10px] flex gap-3">
        <div className="w-[40px] h-[26px] bg-gray-300 animate-pulse"></div>
        <div className="w-[150px] h-[26px] bg-gray-300 rounded animate-pulse"></div>
      </div>
    </div>
  );
}

export const HeaderSkeleton = () => (
  <div className="space-y-4 p-6">
    <div className="flex -mt-2 justify-between">
      <div>
        <Skeleton width={200} height={45} />
      </div>
      <div className="flex flex-col items-end space-x-2">
        <div>
          <Skeleton circle={true} width={40} height={40} />
        </div>
        <div className="flex items-center mt-4 space-x-2">
          <Skeleton width={210} height={30} />
        </div>
      </div>
    </div>
  </div>
);

export const FeatureSkelton = () => {
  return (
    <div className="">
      {[1].map((index) => (
        <Skeleton key={index} height={56} />
      ))}
    </div>
  )
}

export const TableContentSkeleton = () => (

  <div className="w-full">

    <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-3 gap-4 mt-[30px]">
      {Array(9).fill().map((_, index) => (
        <div
          className="border cursor-pointer rounded-lg shadow-sm p-4 bg-gray-200 relative flex items-center animate-pulse"
          key={index}
        >
          <div className="w-10 rounded-full"><Skeleton height={40} /></div>

          <div className="ml-4">
            <div className="rounded w-32 mb-2"><Skeleton height={16} /></div>
            <div className="rounded w-24"><Skeleton height={12} /></div>
          </div>
        </div>
      ))}
    </div>
  </div>

)

export const SkeletonButton = () => (
  <div className="pb-4 flex gap-4">
        <Skeleton height={44} width={140} />
        <Skeleton height={44} width={140} />
      </div>
);

export const UserManagementButton = () => (
  <div>
    <Skeleton width={40} height={25} />
  </div>
);

export const UserManagementBlock = () => (
  <div>
    <Skeleton width={73} height={35} />
  </div>
);

export const SkeletonStatus = () => (
  <div className="">
    <Skeleton height={56} />
  </div>
);

export const SkeletonBar = () => (
  <div className="">
    <Skeleton height={65} />
  </div>
);

export const SearchReplaceSkelton = () => {
  return (
    <div className="p-6 bg-gray-50">
      {/* Search and Replace Input Section */}
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
        {/* Search For */}
        <div>
          <div className="mb-2">
            <Skeleton height={20} width={80} />
          </div>
          <Skeleton height={40} />
        </div>

        {/* Replace With */}
        <div>
          <div className="mb-2">
            <Skeleton height={20} width={100} />
          </div>
          <Skeleton height={40} />
        </div>
      </div>

      {/* Select Tables Section */}
      <div className="bg-white rounded-lg p-6 mb-6">
        <div className="flex justify-between items-center mb-6">
          <div className="flex items-center gap-3">            
            <Skeleton height={24} width={120} />
          </div>
          <Skeleton height={36} width={80} />
        </div>

        {/* Table Grid */}
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          {Array.from({ length: 12 }).map((_, index) => (
            <div key={index} className="flex items-center gap-3 p-3 border rounded-lg">
              <Skeleton height={16} width={16} />
              <Skeleton height={16} width={120} />
            </div>
          ))}
        </div>
      </div>

      {/* Additional Settings Section */}
      <div className="bg-white rounded-lg p-6 mb-6">
        <div className="flex items-center gap-3 mb-6">          
          <Skeleton height={24} width={150} />
        </div>

        <div className="grid grid-cols-1 md:grid-cols-3 gap-8">
          {/* Case-Insensitive */}
          <div>
            <div className="flex items-center gap-3 mb-2">
              <Skeleton height={16} width={16} />
              <Skeleton height={20} width={110} />
            </div>
            <Skeleton height={16} width={200} />
          </div>

          {/* Replace GUIDs */}
          <div>
            <div className="flex items-center gap-3 mb-2">
              <Skeleton height={16} width={16} />
              <Skeleton height={20} width={110} />
            </div>
            <Skeleton height={16} width={200} />
          </div>

          {/* Run as dry run */}
          <div>
            <div className="flex items-center gap-3 mb-2">
              <Skeleton height={16} width={16} />
              <Skeleton height={20} width={110} />
            </div>
            <Skeleton height={16} width={200} />
          </div>
        </div>
      </div>      
      
    </div>
  );
}

export const IpDetailSkeleton = () => {
  return (
    <div className="p-6 bg-gray-50 min-h-screen">            
      <div className="grid grid-cols-1 lg:grid-cols-4 gap-6">
        {/* Left Column - Connection Intelligence */}
        <div className="lg:col-span-1">
          <div className="bg-white rounded-lg border p-4 mb-4">
            <div className="flex items-center gap-2 mb-6">
              <Skeleton circle height={20} width={20} />
              <Skeleton width={150} />
            </div>
            
            <div className="space-y-6">
              <div>
                <Skeleton width={60} className="mb-2" />
                <div className="flex items-center gap-2">
                  <Skeleton width={30} />
                  <Skeleton height={24} width={48} />
                </div>
              </div>
              
              <div>
                <Skeleton width={60} className="mb-2" />
                <Skeleton width={80} />
              </div>
              
              <div>
                <Skeleton width={50} className="mb-2" />
                <div className="flex items-center gap-2">
                  <Skeleton width={100} />
                  <Skeleton circle height={20} width={20} />
                </div>
              </div>
            </div>
          </div>
        </div>

        {/* Middle Column - Threat Analysis */}
        <div className="lg:col-span-2">
          <div className="bg-white rounded-lg border p-4 mb-4">
            <div className="flex items-center gap-2 mb-6">
              <Skeleton height={20} width={20} />
              <Skeleton width={100} />
            </div>
            
            <div className="space-y-6">
              <div>
                <div className="flex justify-between items-center mb-2">
                  <Skeleton width={100} />
                  <Skeleton circle height={32} width={32} />
                </div>
                <Skeleton height={8} />
                <Skeleton width={60} className="mt-1" />
              </div>
              
              <div>
                <div className="flex justify-between items-center mb-2">
                  <Skeleton width={90} />
                  <Skeleton circle height={32} width={32} />
                </div>
                <Skeleton height={8} />
                <Skeleton width={60} className="mt-1" />
              </div>
              
              <div className="flex justify-between items-center">
                <Skeleton width={100} />
                <Skeleton height={24} width={60} />
              </div>
            </div>
          </div>

          {/* System Response */}
          <div className="bg-white rounded-lg border p-4">
            <div className="flex items-center gap-2 mb-6">
              <Skeleton height={20} width={20} />
              <Skeleton width={120} />
            </div>
            
            <div className="space-y-4">
              <div>
                <div className="flex justify-between items-center mb-2">
                  <Skeleton width={80} />
                  <Skeleton circle height={8} width={8} />
                </div>
                <Skeleton width={60} />
              </div>
              
              <div>
                <div className="flex justify-between items-center mb-2">
                  <Skeleton width={100} />
                  <Skeleton height={16} width={16} />
                </div>
              </div>
              
              <div>
                <Skeleton width={50} className="mb-2" />
                <Skeleton width={80} />
              </div>
            </div>
          </div>
        </div>

        {/* Right Column - Security Score */}
        <div className="lg:col-span-1">
          <div className="bg-white rounded-lg border p-4 mb-4">
            <div className="flex items-center gap-2 mb-6">
              <Skeleton height={16} width={16} />
              <Skeleton width={100} />
            </div>
            
            <div className="text-center mb-6">
              <div className="flex justify-center mb-4">
                <Skeleton circle height={96} width={96} />
              </div>
              <div className="flex justify-center mb-2">
                <Skeleton width={60} />
              </div>
              <div className="flex justify-center">
                <Skeleton width={80} />
              </div>
            </div>
            
            <div className="space-y-3">
              <div className="flex justify-between items-center">
                <Skeleton width={100} />
                <Skeleton width={40} />
              </div>
              <div className="flex justify-between items-center">
                <Skeleton width={80} />
                <Skeleton width={30} />
              </div>
              <div className="flex justify-between items-center">
                <Skeleton width={60} />
                <Skeleton width={30} />
              </div>
            </div>
          </div>
        </div>
      </div>

      {/* Security Event Log */}
      <div className="bg-white rounded-lg border p-4 mt-6">
        <div className="flex items-center gap-2 mb-6">
          <Skeleton height={20} width={20} />
          <Skeleton width={140} />
        </div>
        
        <div className="flex gap-4 mb-6">
          <Skeleton height={32} width={100} />
          <div className="flex-1">
            <Skeleton height={32} />
          </div>
        </div>
        
        {/* Event Items */}
        <div className="space-y-4">
          <div className="flex items-start gap-4 p-4 border rounded">
            <Skeleton circle height={24} width={24} />
            <div className="flex-1">
              <div className="flex items-center gap-4 mb-2">
                <Skeleton width={100} />
                <Skeleton height={20} width={60} />
              </div>
              <div className="flex gap-4 mb-2">
                <Skeleton width={60} />
                <Skeleton width={50} />
              </div>
              <Skeleton width={200} />
            </div>
            <div className="text-right">
              <Skeleton width={60} className="mb-1" />
              <Skeleton width={50} />
            </div>
          </div>
          
          <div className="flex items-start gap-4 p-4 border rounded">
            <Skeleton circle height={24} width={24} />
            <div className="flex-1">
              <div className="flex items-center gap-4 mb-2">
                <Skeleton width={90} />
                <Skeleton height={20} width={60} />
              </div>
              <Skeleton width={60} />
            </div>
            <div className="text-right">
              <Skeleton width={60} className="mb-1" />
              <Skeleton width={50} />
            </div>
          </div>
          
          <div className="flex items-start gap-4 p-4 border rounded">
            <Skeleton circle height={24} width={24} />
            <div className="flex-1">
              <div className="flex items-center gap-4 mb-2">
                <Skeleton width={90} />
                <Skeleton height={20} width={60} />
              </div>
              <Skeleton width={60} />
            </div>
            <div className="text-right">
              <Skeleton width={60} className="mb-1" />
              <Skeleton width={50} />
            </div>
          </div>
        </div>
      </div>
    </div>
  );
};

// Circular skeleton for the security score
const CircularSkeleton = ({ size = 120 }) => (
  <div className="flex flex-col items-center">
    <div 
      className="bg-gray-200 animate-pulse rounded-full border-4 border-gray-100"
      style={{ width: size, height: size }}
    />
    <Skeleton className="mt-2" width="80px" height="16px" />
    <Skeleton className="mt-1" width="100px" height="14px" />
  </div>
);

export const SecurityDetailSkeleton = () => {
  return (
    <div className="space-y-6">
      {/* Hero card — IP + risk badge */}
      <div className="bg-white border border-gray-200 rounded-xl overflow-hidden shadow-sm">
        <div className="flex items-start justify-between gap-4 p-5 flex-wrap">
          <div className="flex items-start gap-4 min-w-0">
            <Skeleton className="!rounded-lg" width="48px" height="48px" />
            <div className="min-w-0 space-y-2">
              <div className="flex items-center gap-2 flex-wrap">
                <Skeleton width="170px" height="22px" />
                <Skeleton width="42px" height="16px" className="!rounded-full" />
              </div>
              <div className="flex items-center gap-3 flex-wrap">
                <Skeleton className="!rounded" width="20px" height="14px" />
                <Skeleton width="120px" height="14px" />
              </div>
            </div>
          </div>
          <Skeleton width="160px" height="32px" className="!rounded-lg" />
        </div>
      </div>

      {/* Stat tile row */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        {[...Array(4)].map((_, i) => (
          <div key={i} className="bg-white border border-gray-200 rounded-lg p-4 flex items-start gap-3">
            <Skeleton className="!rounded-lg" width="40px" height="40px" />
            <div className="flex-1 space-y-2">
              <Skeleton width="100px" height="10px" />
              <Skeleton width="80px" height="18px" />
              <Skeleton width="130px" height="12px" />
            </div>
          </div>
        ))}
      </div>

      {/* Browser + last attempt row */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
        {[...Array(2)].map((_, i) => (
          <div key={i} className="bg-white border border-gray-200 rounded-lg p-4 flex items-start gap-3">
            <Skeleton className="!rounded-lg" width="40px" height="40px" />
            <div className="flex-1 space-y-2">
              <Skeleton width="120px" height="10px" />
              <Skeleton width="140px" height="16px" />
              <Skeleton width="220px" height="12px" />
            </div>
          </div>
        ))}
      </div>

      {/* Attempt timeline */}
      <div className="bg-white border border-gray-200 rounded-lg p-4">
        <div className="flex items-center justify-between mb-3 flex-wrap gap-2">
          <div className="flex items-center gap-2">
            <Skeleton className="!rounded" width="18px" height="18px" />
            <Skeleton width="140px" height="14px" />
          </div>
          <Skeleton width="80px" height="20px" className="!rounded-full" />
        </div>
        <div className="flex gap-2">
          {[...Array(4)].map((_, i) => (
            <div key={i} className="flex-shrink-0 min-w-[120px] border border-gray-200 rounded-md p-2 bg-gray-50 space-y-1">
              <Skeleton width="20px" height="10px" />
              <Skeleton width="70px" height="16px" />
              <Skeleton width="80px" height="12px" />
            </div>
          ))}
        </div>
      </div>

      {/* Security Event Log header */}
      <div>
        <div className="flex items-center justify-between mb-3 flex-wrap gap-2">
          <div className="flex items-center gap-2">
            <Skeleton className="!rounded" width="20px" height="20px" />
            <Skeleton width="180px" height="18px" />
          </div>
          <Skeleton width="80px" height="24px" className="!rounded-full" />
        </div>

        {/* Event Log table */}
        <div className="w-full overflow-x-auto rounded-lg border border-gray-300">
          {/* Header row */}
          <div className="bg-gray-100 px-3 py-2 flex items-center gap-4">
            {[...Array(5)].map((_, i) => (
              <Skeleton key={i} width={i === 0 ? '120px' : '90px'} height="12px" />
            ))}
          </div>
          {/* Data rows */}
          {[...Array(4)].map((_, i) => (
            <div key={i} className="px-3 py-3 border-t border-gray-200 flex items-center gap-4">
              {/* Event cell */}
              <div className="flex items-center gap-2.5 flex-1 min-w-0">
                <Skeleton className="!rounded-md" width="32px" height="32px" />
                <div className="space-y-1.5">
                  <Skeleton width="140px" height="14px" />
                  <Skeleton width="60px" height="12px" className="!rounded" />
                </div>
              </div>
              {/* Date & Time */}
              <div className="space-y-1">
                <Skeleton width="70px" height="14px" />
                <Skeleton width="80px" height="12px" />
              </div>
              {/* User */}
              <Skeleton width="80px" height="14px" />
              {/* Attempts */}
              <Skeleton width="60px" height="14px" />
              {/* Extra Details button */}
              <Skeleton width="100px" height="24px" className="!rounded-md" />
            </div>
          ))}
        </div>
      </div>
    </div>
  );
};

export const SecurityDetail = () => {
  return <SecurityDetailSkeleton />;
};

export const AjaxInterceptorSkeleton = () => {
    return (
        <div className="space-y-4 animate-pulse">
            {/* Skeleton for Header Controls */}
            <div className="bg-white p-4 rounded-lg shadow-sm border">
                <div className="flex items-center justify-between mb-4">
                    <div className="h-6 w-48 bg-gray-200 rounded"></div>
                    <div className="flex items-center gap-2">
                        <div className="h-6 w-20 bg-gray-200 rounded-full"></div>
                        <div className="h-6 w-14 bg-gray-200 rounded-full"></div>
                    </div>
                </div>
                <div className="flex gap-2">
                    <div className="h-10 w-40 bg-gray-200 rounded"></div>
                    <div className="h-10 w-40 bg-gray-200 rounded"></div>
                </div>
            </div>

            {/* Skeleton for Intercepted Calls Section */}
            <div className="bg-white rounded-lg shadow-sm border">
                <div className="p-4 border-b bg-gray-50 rounded-t-lg">
                    <div className="flex items-center justify-between">
                        <div className="flex items-center gap-2">
                            <div className="h-6 w-36 bg-gray-200 rounded"></div>
                            <div className="h-6 w-8 bg-gray-200 rounded-full"></div>
                        </div>
                    </div>
                </div>

                <div className="max-h-96 overflow-y-auto divide-y divide-gray-100">
                    {Array.from({ length: 3 }).map((_, index) => (
                        <div key={index} className="p-4 space-y-3">
                            <div className="flex items-center gap-2">
                                <div className="h-5 w-14 bg-gray-200 rounded"></div>
                                <div className="h-5 w-14 bg-gray-200 rounded"></div>
                                <div className="h-4 w-24 bg-gray-200 rounded"></div>
                            </div>

                            <div className="flex items-center gap-4 text-sm text-gray-600 mb-2 mt-2">
                                <div className="h-4 w-32 bg-gray-200 rounded"></div>
                                <div className="h-4 w-48 bg-gray-200 rounded"></div>
                            </div>

                            <div className="mt-3 bg-gray-50 rounded-md p-3">
                                <div className="h-4 w-24 bg-gray-300 mb-2 rounded"></div>
                                <div className="h-20 bg-white p-2 rounded border"></div>
                            </div>
                        </div>
                    ))}
                </div>
            </div>
        </div>
    );
};


const LoaderSkeleton = ({ count = 3 }) => {
  return (
    <div>
      <div className="mb-4 border-b border-gray-200 animate-pulse">
        <ul className="flex flex-wrap -mb-px text-sm font-medium text-center" role="tablist">
          {Array.from({ length: count }).map((_, index) => (
            <li key={index} className="me-4 pb-2" role="presentation">
              <Skeleton width={94} height={54} />
            </li>
          ))}
        </ul>
      </div>
      <div className="space-y-4 ">
        <div className="overflow-x-auto">
          <table className="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead className="bg-[#F3F4F6]">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  <Skeleton width={100} />
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  <Skeleton width={80} />
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  <Skeleton width={80} />
                </th>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                  <Skeleton width={80} />
                </th>
              </tr>
            </thead>
            <tbody className="bg-white divide-y divide-gray-200 dark:divide-gray-700">
              {[1, 2, 3, 4, 5, 6, 7].map((index) => (
                <tr
                  key={index}
                  className={`${index % 2 === 0 ? "bg-gray-50" : "bg-white"} }`}
                >
                  <td className="px-6 py-4 whitespace-nowrap text-md font-medium text-gray-900 dark:text-black">
                    <Skeleton width={120} />
                  </td>
                  <td
                    className={`px-6 py-4 whitespace-nowrap text-sm text-green-600 dark:text-green-400`}
                  >
                    <Skeleton width={60} />
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                    <Skeleton width={60} />
                  </td>
                  <td className="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                    <Skeleton width={60} />
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
};

export default LoaderSkeleton;
