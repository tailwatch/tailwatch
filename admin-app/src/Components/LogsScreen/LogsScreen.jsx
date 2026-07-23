import React, { useState, useEffect, useRef } from "react";
import { useVirtualizer } from "@tanstack/react-virtual";
import { FaCheck } from "react-icons/fa";
import DOMPurify from "dompurify";
import InfoBar from "../InfoBar/InfoBar";

const LogsScreen = ({
  logs,
  setLogs,
  isPaused,
  isLogsVisible,
  isCanceled,
  isCompleted,
  cancelMessage,
  successMessage,
}) => {
  const [isCopied, setIsCopied] = useState(false);
  const [isProgressing, setIsProgressing] = useState(false);
  const scrollContainerRef = useRef(null);
  const lastLogTimeRef = useRef(Date.now());

  const virtualizer = useVirtualizer({
    count: logs.length,
    getScrollElement: () => scrollContainerRef.current,
    estimateSize: () => 28,
    overscan: 5,
  });

  // ✅ Deduplicate only when new logs arrive
  useEffect(() => {
    if (logs.length === 0) return;
    const uniqueLogsSet = new Set(logs);
    if (uniqueLogsSet.size !== logs.length) {
      setLogs(Array.from(uniqueLogsSet));
    }
    lastLogTimeRef.current = Date.now();
    setIsProgressing(false);
  }, [logs.length]);

  // Reset stall state whenever the process is paused/canceled/completed so the
  // "Process is in progress" InfoBar can't linger from a stale 10s timeout, and
  // so the timer starts fresh on resume.
  useEffect(() => {
    if (isPaused || isCanceled || isCompleted) {
      setIsProgressing(false);
      lastLogTimeRef.current = Date.now();
    }
  }, [isPaused, isCanceled, isCompleted]);

  // ✅ Check if logs have stopped — but only while the process is actively running.
  useEffect(() => {
    if (isPaused || isCanceled || isCompleted) return;
    const interval = setInterval(() => {
      if (Date.now() - lastLogTimeRef.current > 10000) {
        setIsProgressing(true);
      }
    }, 2000);
    return () => clearInterval(interval);
  }, [isPaused, isCanceled, isCompleted]);

  // ✅ Auto-scroll to bottom when new logs arrive
  useEffect(() => {
    if (logs.length > 0) {
      requestAnimationFrame(() => {
        virtualizer.scrollToIndex(logs.length - 1, { align: "end" });
      });
    }
  }, [logs.length]);

  // ✅ Copy logs to clipboard
  const handleCopyLogs = () => {
    navigator.clipboard.writeText(logs.join("\n")).then(() => {
      setIsCopied(true);
      setTimeout(() => setIsCopied(false), 2000);
    });
  };

  return (
    <div className="mt-4 mb-2">
      {isLogsVisible && (
        <div>
          {!isCanceled && !isCompleted && !isPaused && isProgressing && (
            <InfoBar type="progress" message="Process is in progress. Don't panic" />
          )}

          <div className="relative">
            {/* Copy Button */}
            <button
              onClick={handleCopyLogs}
              className="absolute top-0 right-0 m-2 mr-[25px] text-gray-900 dark:text-gray-700 hover:bg-gray-100 bg-gray-800 dark:border-gray-200 dark:hover:bg-gray-300 rounded-lg py-2 px-2.5 inline-flex items-center justify-center bg-white border z-10"
            >
              {isCopied ? (
                <span className="inline-flex items-center">
                  <svg className="w-3 h-3 text-blue-700 dark:text-blue-500 mr-1.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 16 12">
                    <path stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M1 5.917 5.724 10.5 15 1.5" />
                  </svg>
                  <span className="text-xs font-semibold text-blue-700 dark:text-blue-500">Copied</span>
                </span>
              ) : (
                <span className="inline-flex items-center">
                  <svg className="w-3 h-3 mr-1.5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 18 20">
                    <path d="M16 1h-3.278A1.992 1.992 0 0 0 11 0H7a1.993 1.993 0 0 0-1.722 1H2a2 2 0 0 0-2 2v15a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2Zm-3 14H5a1 1 0 0 1 0-2h8a1 1 0 1 1 0 2Zm0-4H5a1 1 0 0 1 0-2h8a1 1 0 1 1 0 2Zm0-5H5a1 1 0 0 1 0-2h2V2h4v2h2a1 1 0 1 1 0 2Z" />
                  </svg>
                  <span className="text-xs font-semibold">Copy logs</span>
                </span>
              )}
            </button>

            {/* Virtual Scrolling Logs Container */}
            <div className="bg-[#161515] rounded-md">
              {logs.length > 0 ? (
                <div
                  ref={scrollContainerRef}
                  style={{ height: 500, overflowY: "auto" }}
                >
                  <ul
                    style={{
                      height: virtualizer.getTotalSize(),
                      width: "100%",
                      position: "relative",
                      margin: 0,
                      padding: 0,
                    }}
                  >
                    {virtualizer.getVirtualItems().map((virtualRow) => {
                      const logHtml = logs[virtualRow.index];
                      const previousLogHtml = virtualRow.index > 0 ? logs[virtualRow.index - 1] : null;

                      const hasWrapper =
                        logHtml.includes("style='padding:10px 0;'") ||
                        logHtml.includes('style="padding:10px 0;"');
                      const previousHadWrapper =
                        previousLogHtml &&
                        (previousLogHtml.includes("style='padding:10px 0;'") ||
                          previousLogHtml.includes('style="padding:10px 0;"'));

                      let padding;
                      if (hasWrapper) {
                        padding = "0 10px";
                      } else if (previousHadWrapper) {
                        padding = "40px 10px 10px 10px";
                      } else {
                        padding = "10px";
                      }

                      return (
                        <li
                          key={virtualRow.index}
                          ref={virtualizer.measureElement}
                          data-index={virtualRow.index}
                          className="list-none"
                          style={{
                            position: "absolute",
                            top: 0,
                            left: 0,
                            width: "100%",
                            transform: `translateY(${virtualRow.start}px)`,
                          }}
                        >
                          <div
                            className="text-sm text-[#e3e3e3] whitespace-pre-wrap break-words"
                            style={{
                              isolation: "isolate",
                              display: "block",
                              width: "100%",
                              padding: padding,
                              minHeight: "20px",
                              overflow: "hidden",
                              boxSizing: "border-box",
                            }}
                            dangerouslySetInnerHTML={{ __html: DOMPurify.sanitize(logHtml) }}
                          />
                        </li>
                      );
                    })}
                  </ul>
                </div>
              ) : (
                <div className="h-[500px] flex items-start justify-start px-4 pt-4">
                  <p className="text-sm text-[#e3e3e3]">Please wait. Fetching Logs....</p>
                </div>
              )}
            </div>
          </div>
        </div>
      )}

      {/* Cancel Message */}
      {isCanceled && (
        <div className="mt-[5px] flex items-center p-5 border border-white bg-[#d63638] rounded-md text-white font-medium text-sm">
          <FaCheck className="mr-2" />
          <p>{cancelMessage}</p>
        </div>
      )}

      {/* Success Message */}
      {isCompleted && !isCanceled && (
        <div className="mt-[5px] flex items-center p-5 border border-white bg-[#007980] rounded-md text-white font-medium text-sm">
          <FaCheck className="text-white mr-2" />
          <p>{successMessage}</p>
        </div>
      )}
    </div>
  );
};

export default LogsScreen;