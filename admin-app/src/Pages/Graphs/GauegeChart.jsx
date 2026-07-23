import ReactSpeedometer from "react-d3-speedometer";
import { RefreshCcw, ShieldCheck } from 'lucide-react';
import IconButton from '../../Components/Buttons/IconButton';
import { useRef } from 'react';

const GaugeChart = ({ isValue, onRefetch }) => {
  const lordIconRef = useRef();

  const getStatusInfo = (value) => {
    if (value >= 80) {
      return {
        label: "Very Good",
        bgClass: "bg-gradient-to-br from-cyan-50 via-teal-100 to-emerald-200",
        borderClass: "border-emerald-300",
        textColor: "text-teal-700",
        badgeClass: "bg-gradient-to-r from-teal-500 to-emerald-500",
        scoreRing: "border-teal-400",
        scoreText: "text-teal-700",
      };
    } else if (value >= 60) {
      return {
        label: "Good",
        bgClass: "bg-gradient-to-br from-lime-50 via-green-100 to-teal-200",
        borderClass: "border-green-300",
        textColor: "text-green-700",
        badgeClass: "bg-gradient-to-r from-green-500 to-teal-500",
        scoreRing: "border-green-400",
        scoreText: "text-green-700",
      };
    } else if (value >= 40) {
      return {
        label: "Ok",
        bgClass: "bg-gradient-to-br from-yellow-100 via-amber-100 to-yellow-300",
        borderClass: "border-amber-300",
        textColor: "text-amber-700",
        badgeClass: "bg-gradient-to-r from-yellow-500 to-orange-500",
        scoreRing: "border-amber-400",
        scoreText: "text-amber-700",
      };
    } else if (value >= 20) {
      return {
        label: "Bad",
        bgClass: "bg-gradient-to-br from-orange-100 via-red-100 to-orange-300",
        borderClass: "border-orange-300",
        textColor: "text-orange-700",
        badgeClass: "bg-gradient-to-r from-orange-500 to-red-500",
        scoreRing: "border-orange-400",
        scoreText: "text-orange-700",
      };
    } else {
      return {
        label: "Very Bad",
        bgClass: "bg-gradient-to-br from-red-100 via-rose-100 to-red-300",
        borderClass: "border-rose-300",
        textColor: "text-rose-700",
        badgeClass: "bg-gradient-to-r from-red-500 to-pink-600",
        scoreRing: "border-rose-400",
        scoreText: "text-rose-700",
      };
    }
  };

  const handleCardMouseEnter = () => {
    if (lordIconRef.current) lordIconRef.current.play();
  };

  const handleCardMouseLeave = () => {
    if (lordIconRef.current) lordIconRef.current.stop();
  };

  const statusInfo = getStatusInfo(isValue);

  return (
    <div
      className={`h-full rounded-xl shadow-lg ${statusInfo.bgClass} ${statusInfo.borderClass} border overflow-hidden transition-all duration-300 hover:shadow-xl px-4 py-6 w-[100%] flex flex-col gap-6 relative`}
      onMouseEnter={handleCardMouseEnter}
      onMouseLeave={handleCardMouseLeave}
    >
      {/* Header — always visible */}
      <div className="flex items-start justify-between">
        <div className="flex items-center gap-3">
          <div className={`w-10 h-10 rounded-full flex items-center justify-center bg-[#07C07E1A]`}>
            <ShieldCheck size={24} className="text-[#007980]" />
          </div>
          <div>
            <h2 className="text-lg font-semibold text-black">Protection Status</h2>
            <p className="text-sm text-black">
              This chart gives an overview of the protection status of your website.
            </p>
          </div>
        </div>

        <div className="flex items-center">
          <div className={`py-1 px-3 text-xs font-medium text-white rounded-lg whitespace-nowrap ${statusInfo.badgeClass} mr-2`}>
            {statusInfo.label}
          </div>
          <IconButton onClick={onRefetch} icon={RefreshCcw} bgColor={`${statusInfo.textColor} bg-opacity-10`} hoverBgColor={`hover:${statusInfo.textColor} hover:bg-opacity-20`} textColor={statusInfo.textColor} roundedFull={true} className="!p-2 !rounded-[5px] transition duration-200 hover:shadow-sm" />
        </div>
      </div>

      {/* Mobile score card — shown below 524px */}
      <div className="flex flex-col items-center justify-center gap-3 [@media(min-width:524px)]:hidden">
        <div className={`w-28 h-28 rounded-full border-4 ${statusInfo.scoreRing} flex flex-col items-center justify-center bg-white bg-opacity-60 shadow-md`}>
          <span className={`text-3xl font-bold ${statusInfo.scoreText}`}>{isValue}</span>
          <span className="text-xs text-gray-500 font-medium">/ 100</span>
        </div>
        <div className={`py-1 px-4 text-sm font-semibold text-white rounded-full ${statusInfo.badgeClass}`}>
          {statusInfo.label}
        </div>
        <p className="text-xs text-gray-600 text-center">Website Health Score</p>
      </div>

      {/* Gauge chart — shown at 524px and above */}
      <div className="hidden [@media(min-width:524px)]:flex justify-center pt-2">
        <ReactSpeedometer
          width={500}
          needleHeightRatio={0.6}
          value={isValue}
          maxValue={100}
          currentValueText="Website Health"
          customSegmentLabels={[
            { text: 'Very Bad', position: 'INSIDE', color: '#000' },
            { text: 'Bad', position: 'INSIDE', color: '#000' },
            { text: 'Ok', position: 'INSIDE', color: '#000' },
            { text: 'Good', position: 'INSIDE', color: '#000' },
            { text: 'Very Good', position: 'INSIDE', color: '#000' },
          ]}
          ringWidth={50}
          needleTransitionDuration={2000}
          needleTransition="easeOut"
          needleColor="#000"
          textColor="#000"
        />
      </div>
    </div>
  );
};

export default GaugeChart;
