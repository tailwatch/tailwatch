import React, { useEffect, useState } from "react";
import { useNavigate } from "react-router-dom";
import axios from "axios";
import { FaCheckCircle, FaSpinner, FaTimesCircle } from "react-icons/fa";
import { useVerifyVisitStatus } from '../../Components/Hooks/VerifyVisitStatus/VerifyVisitStatus';
import { verifyVisitStatus, checkPhpVersion, checkWpVersion, checkCronStatus, check_wptw_table, handleHttpRequest } from "./VisitServices/VisitServices";
import VisitModal from "./VisitModal/VisitModal";
import VisitSteps from "./VisitSteps/VisitSteps";
import { CronHealer } from "../../Components/CroneHealer/CronHealer";

/* global wptw_ajax */

const Visit = () => {
  const navigate = useNavigate();
  const [currentStep, setCurrentStep] = useState(0);
  const [progressValue, setProgressValue] = useState(0);
  const { visitStep, visitCompleted, setVisitStep, isVerifyLoading, visitData } = useVerifyVisitStatus(navigate);
  const [isVisitCompleted, setVisitCompleted] = useState(false);
  const [visibleMessages, setVisibleMessages] = useState([]);
  const [stepMessages, setStepMessages] = useState([
    { id: 1, label: "PHP Version", messages: [] },
    { id: 2, label: "WordPress Version", messages: [] },
    { id: 3, label: "Database Table", messages: [] },
    { id: 4, label: "Cron Status", messages: [] },
  ]);

  const [isModalOpen, setIsModalOpen] = useState(false);
  const [modalMessage, setModalMessage] = useState("");

  const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  const executeStep = async () => {
    if (visitStep === "check_php_version") {
      await delay(4000);
      const response = await checkPhpVersion();
      if (response.error) {
        setTimeout(() => { setIsModalOpen(true); }, 1000);
        setModalMessage(response.error);
        setStepMessages((prev) =>
          prev.map((step, index) =>
            index === 0 ? { ...step, error: true } : step
          )
        );
        return;
      }

      setStepMessages((prevState) => {
        const newMessages = [...prevState];
        newMessages[0].messages = [
          `Current PHP Version: ${response.current_php_version}`,
          `Required PHP Version: ${response.required_php_version}`,
          response.message,
        ];
        return newMessages;
      });
      await delay(8000);
      setCurrentStep(1);
      await verifyVisitStatus(setVisitStep, navigate);

    } else if (visitStep === "check_wp_version") {
      await delay(4000);
      const response = await checkWpVersion();
      if (response.error) {
        setTimeout(() => { setIsModalOpen(true); }, 1000);
        setModalMessage(response.error);
        setStepMessages((prev) =>
          prev.map((step, index) =>
            index === 1 ? { ...step, error: true } : step
          )
        );
        return;
      }
      setStepMessages((prevState) => {
        const newMessages = [...prevState];
        newMessages[1].messages = [
          `Required WordPress Version: ${response.required_wp_version}`,
          `Current WordPress Version: ${response.current_wp_version}`,
          response.message,
        ];
        return newMessages;
      });
      await delay(8000);
      setCurrentStep(2);
      await verifyVisitStatus(setVisitStep, navigate);

    } else if (visitStep === "wptw_table") {
      await delay(4000);
      const response = await check_wptw_table();
      if (response.error) {
        setTimeout(() => { setIsModalOpen(true); }, 1000);
        setModalMessage(response.error);
        setStepMessages((prev) =>
          prev.map((step, index) =>
            index === 2 ? { ...step, error: true } : step
          )
        );
        return;
      }
      setStepMessages((prevState) => {
        const newMessages = [...prevState];
        newMessages[2].messages = [response.message];
        return newMessages;
      });
      await delay(8000);
      setCurrentStep(3);
      await verifyVisitStatus(setVisitStep, navigate);

    }
    else if (visitStep === "check_cron_status") {
      await delay(4000);
      const response = await checkCronStatus();
      if (response.error) {
        setTimeout(() => { setIsModalOpen(true); }, 1000);
        setModalMessage(response.error);
        setStepMessages((prev) =>
          prev.map((step, index) =>
            index === 3 ? { ...step, error: true } : step
          )
        );
        return;
      }
      setStepMessages((prevState) => {
        const newMessages = [...prevState];
        newMessages[3].messages = [response.message];
        return newMessages;
      });
      await delay(4000);
      setCurrentStep(4);
      await verifyVisitStatus(setVisitStep, navigate);

    } else if (visitStep === "db_initialize") {
      const response = await check_wptw_table();
      if (response) {
        await delay(4000);
        await fetchLogs();
        setCurrentStep(5);
      }
    }
  };

  const fetchLogs = async () => {
    try {
      const formData = new FormData();
      formData.append("action", "wptw_global_ajax_handler");
      formData.append("action_type", "wptw_verify_initialize_completed");
      formData.append("nonce", wptw_ajax.nonce);

      const response = await axios.post(wptw_ajax.ajax_url, formData, {
        headers: { "Content-Type": "multipart/form-data" },
      });

      const { progress_percentage, initialize_completed, function_completed, completion_timestamp, current_timestamp } = response.data.data;

          if (function_completed && completion_timestamp && current_timestamp) {
        const timeDifference = current_timestamp - completion_timestamp;        

        if (timeDifference >= 12) {          
          await CronHealer();
        } else {          
        }
      }


      if (
        typeof progress_percentage === "number" &&
        typeof initialize_completed === "boolean"
      ) {
        if (initialize_completed) {
          setProgressValue(100);
          setTimeout(() => {
            setVisitCompleted(true);
            navigate("/visitfeatures");
          }, 1500);
        } else {
          setProgressValue(progress_percentage);
          setTimeout(fetchLogs, 4000);
        }
      } else {
        throw new Error("Invalid response structure.");
      }
    } catch (e) {
    }
  };

  useEffect(() => {
    const mapVisitStepToIndex = {
      check_php_version: 0,
      check_wp_version: 1,
      wptw_table: 2,
      check_cron_status: 3,
      db_initialize: 4,
    };
    const currentStepIndex = mapVisitStepToIndex[visitStep] || 0;

    setStepMessages((prevState) =>
      prevState.map((step, index) => {
        const stepKey = Object.keys(mapVisitStepToIndex).find(
          (key) => mapVisitStepToIndex[key] === index
        );
        const isCompleted = stepKey && visitData[stepKey] === true;
        return {
          ...step,
          completed: isCompleted,
          error: step.error,
        };
      })
    );

    setCurrentStep(currentStepIndex);
  }, [visitStep, visitData]);

  useEffect(() => {
    const performSteps = async () => {
      if (!visitCompleted) {
        await executeStep();
      } else {
        setCurrentStep(4);
      }
    };
    performSteps();
  }, [visitStep]);
  useEffect(() => {
    const step = stepMessages[currentStep];
    if (!step) return;
    setVisibleMessages([]);

    step.messages.forEach((message, i) => {
      setTimeout(() => {
        setVisibleMessages((prev) => [...prev, message]);
      }, i * 2000);
    });
  }, [currentStep, stepMessages]);

  useEffect(() => {
    if (visitCompleted) {
      navigate('/dashboard');
    }
  }, [visitCompleted, navigate]);

  return (
    <>
      <div className="min-h-screen bg-gradient-to-br from-slate-50 via-blue-50 to-teal-50 flex items-center justify-center p-4 relative overflow-hidden">
        {/* Animated Background Elements */}
        <div className="absolute inset-0 overflow-hidden pointer-events-none">
          <div className="absolute top-20 left-10 w-72 h-72 bg-teal-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob"></div>
          <div className="absolute top-40 right-10 w-72 h-72 bg-teal-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob animation-delay-2000"></div>
          <div className="absolute -bottom-8 left-1/2 w-72 h-72 bg-teal-600 rounded-full mix-blend-multiply filter blur-3xl opacity-20 animate-blob animation-delay-4000"></div>
        </div>

        <div className="w-full max-w-4xl relative z-10">
          {isVerifyLoading ? (
            <div className="flex flex-col items-center justify-center py-20">
              <div className="relative">
                <FaSpinner className="animate-spin text-teal-600 text-6xl" />
                <div className="absolute inset-0 blur-xl bg-teal-400 opacity-30 animate-pulse"></div>
              </div>
              <p className="mt-6 text-gray-600 text-lg">Preparing your installation...</p>
            </div>
          ) : (
            <>
              <VisitSteps visitStep={visitStep} />

              <div className="flex justify-center">
                <div className="bg-white bg-opacity-95 border border-teal-200 backdrop-blur-sm shadow-2xl rounded-3xl p-8 w-full border border-gray-100">
                  {visitStep === 'db_initialize' ? (
                    <div className="text-center py-8">
                      <div className="relative inline-block mb-8">
                        <FaSpinner className="animate-spin text-teal-600 text-3xl" />
                        <div className="absolute inset-0 blur-2xl bg-teal-400 opacity-40 animate-pulse"></div>
                      </div>
                      <h3 className="text-2xl font-bold mb-3 text-gray-800 bg-gradient-to-r from-teal-600 to-emerald-600 bg-clip-text text-transparent">
                        Initializing Database
                      </h3>
                      <p className="text-teal-700 text-xl font-semibold mb-8">
                        {progressValue}% Complete
                      </p>
                      <div className="relative h-3 bg-gray-100 rounded-full w-full max-w-md mx-auto overflow-hidden shadow-inner">
                        <div
                          className="absolute top-0 left-0 h-full bg-gradient-to-r from-teal-500 via-teal-600 to-emerald-500 rounded-full transition-all duration-300 shadow-lg"
                          style={{ width: `${progressValue}%` }}
                        >
                          <div className="absolute inset-0 bg-white opacity-30 animate-shimmer"></div>
                        </div>
                      </div>
                      <p className="mt-6 text-sm text-gray-500">This may take a few moments...</p>
                    </div>
                  ) : (
                    currentStep < 5 && (
                      <div className="space-y-4">
                        {stepMessages.map((step, index) => (
                          <div
                            key={step.id}
                            className={`flex flex-col p-6 rounded-2xl transition-all duration-500 transform ${step.error
                              ? 'border-2 border-red-300 bg-red-50 shadow-lg'
                              : step.completed || index < currentStep
                                ? 'border-2 border-teal-200 bg-gradient-to-r from-teal-50 to-emerald-50 shadow-lg'
                                : index === currentStep
                                  ? 'border-2 border-teal-300 bg-white shadow-2xl scale-105'
                                  : 'border-2 border-gray-200 bg-gray-50 shadow-sm opacity-60'
                              }`}
                          >
                            <div className="flex items-center justify-between">
                              <span className={`text-lg font-semibold ${step.error
                                ? 'text-red-800'
                                : step.completed || index < currentStep
                                  ? 'text-teal-800'
                                  : index === currentStep
                                    ? 'text-teal-700'
                                    : 'text-gray-500'
                                }`}>
                                {step.label}
                              </span>
                              {step.error ? (
                                <FaTimesCircle className="text-red-500 text-2xl" />
                              ) : step.completed || index < currentStep ? (
                                <FaCheckCircle className="text-teal-600 text-2xl animate-bounce-once" />
                              ) : index === currentStep ? (
                                <FaSpinner className="animate-spin text-teal-600 text-2xl" />
                              ) : (
                                <div className="w-6 h-6 rounded-full border-2 border-gray-300 bg-white"></div>
                              )}
                            </div>
                            {index === currentStep && visibleMessages?.length > 0 && (
                              <div className="mt-4 pt-4 border-t border-teal-200">
                                <ul className="space-y-2">
                                  {visibleMessages.map((message, i) => (
                                    <li key={i} className="text-sm text-teal-700 flex items-start animate-fade-in">
                                      <div className="w-1.5 h-1.5 bg-teal-500 rounded-full mt-1.5 mr-3 flex-shrink-0 animate-pulse"></div>
                                      <span className="font-medium">{message}</span>
                                    </li>
                                  ))}
                                </ul>
                              </div>
                            )}
                          </div>
                        ))}
                      </div>
                    )
                  )}
                </div>
              </div>
            </>
          )}
        </div>

        <style>{`
    @keyframes blob {
      0%, 100% { transform: translate(0, 0) scale(1); }
      33% { transform: translate(30px, -50px) scale(1.1); }
      66% { transform: translate(-20px, 20px) scale(0.9); }
    }
    .animate-blob {
      animation: blob 7s infinite;
    }
    .animation-delay-2000 {
      animation-delay: 2s;
    }
    .animation-delay-4000 {
      animation-delay: 4s;
    }
    @keyframes shimmer {
      0% { transform: translateX(-100%); }
      100% { transform: translateX(100%); }
    }
    .animate-shimmer {
      animation: shimmer 2s infinite;
    }
    @keyframes fade-in {
      from { opacity: 0; transform: translateY(-10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .animate-fade-in {
      animation: fade-in 0.5s ease-out;
    }
    @keyframes bounce-once {
      0%, 100% { transform: scale(1); }
      50% { transform: scale(1.2); }
    }
    .animate-bounce-once {
      animation: bounce-once 0.6s ease-out;
    }
  `}</style>
      </div>
      <VisitModal isOpen={isModalOpen} message={modalMessage} />

    </>
  );
};

const css = `
@keyframes slide-in {
  from {
    opacity: 0;
    transform: translateY(20px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.animate-slide-in {
  animation: slide-in 1.8s ease-in-out;
}

@keyframes ping-once {
  0% { transform: scale(0.8); opacity: 0.5; }
  50% { transform: scale(1.2); opacity: 1; }
  100% { transform: scale(1); opacity: 1; }
}

.animate-ping-once {
  animation: ping-once 0.5s ease-in-out;
}
`;

document.head.insertAdjacentHTML(
  "beforeend",
  `<style>${css}</style>`
);

export default Visit;
