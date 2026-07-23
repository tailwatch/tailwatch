import { useEffect, useState } from "react";
import Header from "../../Components/Header/Header.jsx";
import { ExternalLink, Rocket } from "lucide-react";

const ComingSoon = ({ title = "Coming Soon", description }) => {
  const [mounted, setMounted] = useState(false);

  useEffect(() => {
    const timer = setTimeout(() => setMounted(true), 50);
    return () => clearTimeout(timer);
  }, []);

  return (
    <div>
      <Header title={title} />
      <div className="relative min-h-[calc(100vh-80px)] overflow-hidden bg-gradient-to-br from-[#f0fbfb] via-white to-[#e6f7f7]">
        <div className="pointer-events-none absolute inset-0 overflow-hidden">
          <div className="absolute -top-24 -left-24 h-72 w-72 rounded-full bg-[#85cbcf] opacity-30 blur-3xl"></div>
          <div className="absolute top-1/3 -right-24 h-80 w-80 rounded-full bg-[#007980] opacity-20 blur-3xl"></div>
          <div className="absolute -bottom-24 left-1/3 h-72 w-72 rounded-full bg-[#ec5023] opacity-15 blur-3xl"></div>
        </div>

        <div className="relative z-10 flex flex-col items-center justify-center px-6 py-16 lg:py-24">
          <div className={`transform transition-all duration-700 ease-out ${mounted ? "translate-y-0 opacity-100" : "translate-y-6 opacity-0"}`}>
            <div className="mx-auto mb-8 flex h-24 w-24 items-center justify-center rounded-2xl bg-gradient-to-br from-[#007980] to-[#85cbcf] shadow-xl shadow-[#007980]/30">
              <Rocket className="h-12 w-12 text-white" strokeWidth={1.75} />
            </div>
          </div>

          <div className={`text-center transform transition-all duration-700 delay-100 ease-out ${mounted ? "translate-y-0 opacity-100" : "translate-y-6 opacity-0"}`}>
            <h1 className="text-2xl font-semibold tracking-tight text-gray-900 sm:text-3xl lg:text-4xl">
              <span className="text-[#007980]">Coming Soon</span>
            </h1>
            <p className="mx-auto mt-4 max-w-2xl text-sm leading-relaxed text-gray-600 sm:text-base">
              {description ||
                "This feature is currently in development and will be available in an upcoming update. Stay tuned for new capabilities designed to enhance your website security and management experience."}
            </p>
          </div>

          <div className={`mt-10 transform transition-all duration-700 delay-200 ease-out ${mounted ? "translate-y-0 opacity-100" : "translate-y-6 opacity-0"}`}>
            <a
              href="https://wptailwatch.com/roadmap?utm_source=wp-plugins&utm_medium=wp-dash&utm_campaign=free&utm_content=coming_soon_roadmap"
              target="_blank"
              rel="noopener noreferrer"
              className="inline-flex items-center gap-2 px-6 py-3 rounded-lg text-sm font-semibold !text-white hover:!text-white focus:!text-white !no-underline hover:!no-underline bg-gradient-to-r from-[#007980] to-[#0a9aa3] hover:from-[#006670] hover:to-[#088d97] shadow-md hover:shadow-lg transition-all duration-300"
            >
              <ExternalLink className="w-4 h-4" />
              View Roadmap
            </a>
          </div>
        </div>
      </div>
    </div>
  );
};

export default ComingSoon;
