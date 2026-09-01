import { useState } from "react";
import { Smartphone, CheckCircle2 } from "lucide-react";
import { useNavigate } from "react-router-dom";

const MobileConnectCTA = ({ devices, planName, mobileFeatures, loading }) => {
  const [hovered, setHovered] = useState(false);
  const navigate = useNavigate();

  if (loading) return null;

  // Device connected — show minimal connected state
  if (devices.length > 0) {
    return (
      <div className="px-4 mb-3">
        <div
          onClick={() => navigate("/dashboard/settings/license")}
          className="flex items-center gap-2 px-3 py-2 rounded-xl cursor-pointer"
          style={{
            background: "rgba(20,184,166,0.08)",
            border: "1px solid rgba(20,184,166,0.2)",
          }}
        >
          <CheckCircle2 size={14} className="text-teal-400 flex-shrink-0" />
          <span className="text-teal-300 text-[11px] font-medium">Mobile App Connected</span>
          <div className="ml-auto w-1.5 h-1.5 rounded-full bg-teal-400 animate-pulse" />
        </div>
      </div>
    );
  }

  return (
    <div className="px-4 mb-3">
      <div
        onMouseEnter={() => setHovered(true)}
        onMouseLeave={() => setHovered(false)}
        onClick={() => navigate("/dashboard/settings/license")}
        className="rounded-xl cursor-pointer overflow-hidden transition-all duration-300"
        style={{
          background: hovered ? "rgba(251,146,60,0.08)" : "rgba(251,146,60,0.05)",
          border: `1px solid ${hovered ? "rgba(251,146,60,0.45)" : "rgba(251,146,60,0.25)"}`,
        }}
      >
        {/* Collapsed row — always visible */}
        <div className="flex items-center gap-2.5 px-3 py-2.5">
          <div
            className="flex items-center justify-center h-7 w-7 rounded-full flex-shrink-0"
            style={{ background: "rgba(251,146,60,0.12)", border: "1px solid rgba(251,146,60,0.3)" }}
          >
            <Smartphone size={13} className="text-orange-300" />
          </div>
          <div className="flex flex-col">
            <span className="text-orange-200 text-[11px] font-semibold leading-tight">Connect Mobile App</span>
            <div className="flex items-center gap-1 mt-0.5">
              <div className="w-1.5 h-1.5 rounded-full bg-orange-400 animate-pulse" />
              <span className="text-orange-400/70 text-[9px]">Mobile App Not Connected</span>
            </div>
          </div>
        </div>

        {/* Expanded content on hover */}
        <div
          className="transition-all duration-300 ease-in-out overflow-hidden"
          style={{ maxHeight: hovered ? "200px" : "0px", opacity: hovered ? 1 : 0 }}
        >
          <div className="px-3 pb-3 space-y-2">
            {/* Info message */}
            <p className="text-orange-200/60 text-[10px] leading-relaxed">
              Get real-time push notifications and manage site security directly from your mobile app.
            </p>

            {/* Feature list */}
            <div className="space-y-1">
              {mobileFeatures.map(({ icon: Icon, text }) => (
                <div key={text} className="flex items-center gap-1.5">
                  <Icon size={11} className="text-orange-300 flex-shrink-0" />
                  <span className="text-orange-200/60 text-[10px]">{text}</span>
                </div>
              ))}
            </div>           
          </div>
        </div>
      </div>
    </div>
  );
};

export default MobileConnectCTA;
