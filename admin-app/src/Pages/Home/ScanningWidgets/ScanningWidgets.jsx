import { useRef } from 'react';
import { AlertTriangle, CloudUpload, FileCheck, Lock } from 'lucide-react'
const FeatureDetails = ({ scanningFeatures }) => {
  const lordIconRef = useRef();
  const handleCardMouseEnter = () => {
    if (lordIconRef.current) {
      lordIconRef.current.play();
    }
  };

  const handleCardMouseLeave = () => {
    if (lordIconRef.current) {
      lordIconRef.current.stop();
    }
  };
  const getFeatureIcon = (featureName) => {
    const name = featureName?.toLowerCase();

    if (name?.includes('backup')) {
      return <CloudUpload size={24} className="text-[#007980]" />;
    } else if (name?.includes('integrity watch')) {
      return <FileCheck size={24} className="text-[#007980]" />;
    } else {
      return <AlertTriangle className="text-amber-600" size={24} />;
    }
  };
  
  const isFeatureDisabled = (feature) => {
    // Check if the feature has a message about being not enabled
    if (feature.details.Message && (
      feature.details.Message.includes("not enabled") || 
      feature.details.Message.includes("is not enabled")
    )) {
      return true;
    }
    return false;
  };

  return (
    <div className="w-full">
      <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
        {scanningFeatures.map((feature, index) => {
          const hasDetails = Object.keys(feature.details || {}).length > 0;
          const isDisabled = isFeatureDisabled(feature);
          
          return (
            <div key={index} className={`${isDisabled ? " bg-gradient-to-br from-gray-50 to-gray-100": 'bg-white'} rounded-xl shadow-lg border border-gray-300 overflow-hidden transition-all duration-300 hover:shadow-xl flex flex-col bg-white`} 
            onMouseEnter={handleCardMouseEnter}
            onMouseLeave={handleCardMouseLeave}
            >
              <div className="relative">
                <div className={`absolute top-0 right-0 py-1 px-3 text-xs font-medium text-white rounded-bl-lg ${ isDisabled ? "bg-gray-500" : hasDetails ? "bg-[#007980]" : "bg-gray-500" }`}>
                  {isDisabled ? "Inactive" : hasDetails ? "Active" : "No Data"}
                </div>
              </div>

              <div className="p-6 flex flex-col h-full">
                {/* Title area with icon */}
                <div className="flex items-center mb-4">
                  <div className={`w-10 h-10 rounded-full flex items-center justify-center mr-3 ${ isDisabled ?  "bg-gray-100" :  "bg-[#07C07E1A]" }`}>
                    {isDisabled ? (
                      <Lock size={28} className="text-gray-400" />
                    ) : (
                      getFeatureIcon(feature.name)
                    )}
                  </div>
                  <h2 className="text-lg font-semibold text-black">
                    {feature.name}
                  </h2>
                </div>

                {/* Feature message for disabled features */}
                {isDisabled && (
                  <div className="flex items-center p-4 bg-gray-50 rounded-lg mb-4">
                    <p className="text-black italic flex items-center">
                      <AlertTriangle size={20} className="text-red-500" />
                      {feature.message || "This feature is not enabled"}
                    </p>
                  </div>
                )}

                {/* Feature details */}
                {!isDisabled && hasDetails ? (
                  <div className="space-y-3 flex-grow">
                    {Object.entries(feature.details).map(([key, value], idx) => (
                      <div 
                        key={idx} 
                        className={`flex items-start justify-between border-b border-gray-100 pb-3 hover:bg-gray-50 px-3 py-2 rounded transition-colors ${
                          idx % 2 === 0 ? "bg-gray-50/50" : ""
                        }`}
                      >
                        <span className="text-black capitalize">{key.replace(/_/g, ' ')}</span>
                        <span className="text-black text-right break-words max-w-[60%]">
                          {value ? value.toString() : <span className="text-black italic">N/A</span>}
                        </span>
                      </div>
                    ))}
                  </div>
                ) : !isDisabled ? (
                  <div className="flex items-center justify-center p-6 bg-gray-50 rounded-lg flex-grow">
                    <p className="text-black italic flex items-center">
                       <AlertTriangle size={20} className="text-red-500" />
                      No details available
                    </p>
                  </div>
                ) : null}
              </div>
              
              {/* Bottom accent bar - adds subtle color indication without overwhelming the card */}
              <div className={ isDisabled ? "h-2 w-full bg-gradient-to-r from-gray-600 to-gray-300" : "h-2 w-full bg-gradient-to-r from-[#007980] to-[#85cbcf]"}></div>
            </div>
          );
        })}
      </div>
    </div>
  );
};

export default FeatureDetails;