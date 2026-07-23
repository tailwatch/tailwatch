import React from 'react';
import { Crown, Sparkles } from 'lucide-react';
import { PiMicrosoftOutlookLogoFill } from "react-icons/pi";
import { SiAmazonwebservices, SiSendgrid, SiMailgun, SiPhp, SiGmail, SiMinutemailer } from "react-icons/si";
import { useRadioHandlers } from '../../../../Components/Hooks/useRadioHandlers/useRadioHandlers';
import { useFeaturesData } from '../../../../Components/Context/FeaturesDataContext';
import { resolveIsLocked } from '../../../../Components/Utils/HelperFunctions/LockHelper';

const ComingSoonBadge = ({ floating = false }) =>
  floating ? (
    <span className="absolute -top-3 -right-3 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-[#f0fbfb] border border-[#85cbcf]/60 text-[#007980] text-xs font-semibold shadow-sm z-20">
      <Sparkles className="w-3 h-3 text-[#007980]" />
      <span>Coming Soon</span>
    </span>
  ) : (
    <span className="inline-flex items-center gap-1.5 ml-2 px-2.5 py-0.5 rounded-full bg-[#f0fbfb] border border-[#85cbcf]/60 text-[#007980] text-xs font-semibold shadow-sm">
      <Sparkles className="w-3.5 h-3.5 text-[#007980]" />
      <span>Coming Soon</span>
    </span>
  );

const RadioComponent = ({ id, label, description, values, selectedValue, disabled, handleRadioChange, renderSubOptions, display }) => {
  const { proPluginActive } = useFeaturesData();
  const { orderedKeys, tabRefs, containerRef, animating, hasIcons, handleCardClick } = useRadioHandlers(selectedValue, values, display);

  const iconMap = {
    'phpmailer': <SiPhp size={28} />,
    'custom': <SiMinutemailer size={28} />,
    'sendgrid': <SiSendgrid size={28} />,
    'mailgun': <SiMailgun size={28} />,
    'amazon-ses': <SiAmazonwebservices size={28} />,
    'office360': <PiMicrosoftOutlookLogoFill size={28} />,
    'gmail': <SiGmail size={28} />
  };

  return (
    <div className="!p-5 !rounded-lg !border !border-gray-300 !shadow-md !transition-shadow !duration-300">
      <div className="!mb-4">
        <label className="form-check-label !text-black !text-lg !font-semibold !mb-2 !block">{label}</label>
        <p className="!text-black !text-sm">{description}</p>
      </div>
      {/* if there are icons then show this layout  */}
      {hasIcons ? (
        <div className="flex flex-row gap-4 relative overflow-x-auto sm:overflow-hidden pt-4" ref={containerRef}>
          {orderedKeys.map((key) => {
            const isComingSoon = values[key]?.comming_soon === true;
            const optionLocked = isComingSoon || resolveIsLocked(values[key], proPluginActive);
            return (
              <div key={key} ref={el => tabRefs.current[key] = el} onClick={() => handleCardClick(values[key].value, optionLocked, handleRadioChange)}
                className={`relative transition-all duration-200 ${optionLocked ? 'cursor-not-allowed' : 'cursor-pointer'} ${animating ? 'opacity-90' : ''}`}
                style={{ willChange: 'transform', zIndex: selectedValue === values[key].value ? 2 : 1 }} >
                {/* Top-right plan / coming-soon badge — outside card so it's not clipped */}
                {isComingSoon ? (
                  <ComingSoonBadge floating />
                ) : optionLocked && (
                  <span className="absolute -top-3 -right-3 inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-orange-50 border border-orange-200 text-orange-700 text-xs font-semibold shadow-sm z-20">
                    <Crown className="w-3 h-3 text-orange-500" />
                    <span>{values[key]?.plan_required || 'Pro'}</span>
                  </span>
                )}
                <div className={`flex flex-col items-center justify-center p-4 rounded-lg border transition-all duration-300 ${optionLocked ? 'bg-gray-100 border-gray-200 opacity-70' : selectedValue === values[key].value ? 'bg-gradient-to-br from-blue-50 to-[#85cbcf] border-[#85cbcf] shadow-md' : 'border-gray-300 bg-white hover:border-[#85cbcf]'} w-30 h-30 relative`}>
                  <div className="text-center">
                    <div className={`flex justify-center mb-3 ${optionLocked ? 'text-gray-400' : selectedValue === values[key].value ? 'text-[#007980]' : 'text-gray-700'}`}>
                      {iconMap[values[key].icon] || <div className="w-6 h-6" />}
                    </div>
                    <div className={`text-sm ${optionLocked ? 'text-gray-400' : selectedValue === values[key].value ? 'text-[#007980] font-medium' : 'text-black'}`}>
                      {values[key].label || (values[key].value.charAt(0).toUpperCase() + values[key].value.slice(1))}
                    </div>
                  </div>
                </div>
                <input type="radio" id={`${id}-${key}`} name={id} disabled={optionLocked} value={values[key].value} checked={selectedValue === values[key].value} onChange={() => { }} className="sr-only" />
              </div>
            );
          })}
        </div>
      ) : (
        // if display is tabview then show this layout
        display === 'tab_view' ? (
          <>
            <div className="!flex !flex-row !flex-wrap !gap-4 !relative" ref={containerRef}>
              {orderedKeys.map((key) => {
                const isComingSoon = values[key]?.comming_soon === true;
                const optionLocked = isComingSoon || resolveIsLocked(values[key], proPluginActive);
                return (
                  <div className="!relative" key={key} ref={el => tabRefs.current[key] = el} style={{ willChange: 'transform', zIndex: selectedValue === values[key].value ? 2 : 1 }}>
                    <div className="!flex-shrink-0">
                      <div className={`!inline-flex !items-center !border !border-gray-300 !rounded-xl !p-3 !transition-all !duration-200 hover:!border-[#85cbcf] ${selectedValue === values[key].value ? '!bg-gradient-to-br !from-blue-50 !to-[#85cbcf] !border !border-[#85cbcf] !shadow-sm' : '!border-gray-200 !bg-white'} ${optionLocked || animating ? '!opacity-90 !cursor-not-allowed' : '!cursor-pointer'}`}
                        onClick={() => !optionLocked && !animating && document.getElementById(`${id}-${key}`).click()}>
                        <div className="!relative !flex !items-center">
                          <input type="radio" id={`${id}-${key}`} name={id} disabled={optionLocked || animating} value={values[key].value} checked={selectedValue === values[key].value} onChange={handleRadioChange} style={{ position: 'absolute', opacity: 0, width: 0, height: 0, pointerEvents: 'none' }} />
                          {/* Custom radio circle — inline styles immune to WordPress CSS */}
                          <div style={{ width: 20, height: 20, borderRadius: '50%', border: `2px solid ${selectedValue === values[key].value ? '#85cbcf' : '#d1d5db'}`, backgroundColor: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0, boxSizing: 'border-box', transition: 'border-color 0.2s' }}>
                            {selectedValue === values[key].value && (
                              <div style={{ width: 10, height: 10, borderRadius: '50%', backgroundColor: '#85cbcf' }} />
                            )}
                          </div>
                          <label htmlFor={`${id}-${key}`} className={`!pl-3 !font-medium !text-sm !text-black ${optionLocked || animating ? '!cursor-not-allowed' : '!cursor-pointer'}`}>
                            {values[key].label || values[key].value}
                          </label>
                          {isComingSoon ? (
                            <ComingSoonBadge />
                          ) : optionLocked && (
                            <span className="inline-flex items-center gap-1.5 ml-2 px-2.5 py-0.5 rounded-full bg-orange-50 border border-orange-200 text-orange-700 text-xs font-semibold shadow-sm">
                              <Crown className="w-3.5 h-3.5 text-orange-500" />
                              <span>{values[key]?.plan_required ||'Pro'}</span>
                            </span>
                          )}
                        </div>
                      </div>
                    </div>
                  </div>
                );
              })}
            </div>
          </>
        ) : (
          // if no display or icon in there then show the default layout
          <div className="!space-y-3">
            {Object.keys(values).map((key, index) => {
              const isComingSoon = values[key]?.comming_soon === true;
              const optionLocked = isComingSoon || resolveIsLocked(values[key], proPluginActive);
              return (
                <div className="!relative" key={index}>
                  <div className="!flex !flex-wrap !gap-2">
                    <div className={`!inline-flex !items-center !border !border-gray-300 !rounded-xl !p-3 !transition-all !duration-200 hover:!border-[#85cbcf] ${selectedValue === values[key].value ? '!bg-gradient-to-br !from-blue-50 !to-[#85cbcf] !border !border-[#85cbcf] !shadow-sm' : '!border-gray-200 !bg-white'} ${optionLocked ? '!opacity-90 !cursor-not-allowed' : '!cursor-pointer'}`}
                      onClick={() => !optionLocked && document.getElementById(`${id}-${index}`).click()}>
                      <div className="!relative !flex !items-center">
                        <input type="radio" id={`${id}-${index}`} name={id} disabled={optionLocked} value={values[key].value} checked={selectedValue === values[key].value} onChange={handleRadioChange} style={{ position: 'absolute', opacity: 0, width: 0, height: 0, pointerEvents: 'none' }} />
                        {/* Custom radio circle — inline styles immune to WordPress CSS */}
                        <div style={{ width: 20, height: 20, borderRadius: '50%', border: `2px solid ${selectedValue === values[key].value ? '#85cbcf' : '#d1d5db'}`, backgroundColor: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0, boxSizing: 'border-box', transition: 'border-color 0.2s' }}>
                          {selectedValue === values[key].value && (
                            <div style={{ width: 10, height: 10, borderRadius: '50%', backgroundColor: '#85cbcf' }} />
                          )}
                        </div>
                        <label htmlFor={`${id}-${index}`} className={`!pl-3 !font-medium !text-sm !text-black ${optionLocked ? '!cursor-not-allowed' : '!cursor-pointer'}`}>
                          {values[key].label || values[key].value}
                        </label>
                        {isComingSoon ? (
                          <ComingSoonBadge />
                        ) : optionLocked && (
                          <span className="inline-flex items-center gap-1.5 ml-2 px-2.5 py-0.5 rounded-full bg-orange-50 border border-orange-200 text-orange-700 text-xs font-semibold shadow-sm">
                            <Crown className="w-3.5 h-3.5 text-orange-500" />
                            <span>{values[key]?.plan_required ||'Pro'}</span>
                          </span>
                        )}
                      </div>
                    </div>
                  </div>
                </div>
              );
            })}
          </div>
        )
      )}
      {/* Sub-options display (works for both icon and non-icon views) */}
      {(!hasIcons && display === 'tab_view') || hasIcons ? (
        <div>
          {orderedKeys.map((key) => (
            selectedValue === values[key].value && values[key].sub_options && (
              <div className="!ml-6" key={`sub-${key}`}>
                {renderSubOptions(values[key].sub_options, values[key].display)}
              </div>
            )
          ))}
        </div>
      ) : (
        Object.keys(values).map((key, index) => (
          selectedValue === values[key].value && values[key].sub_options && (
            <div className="!ml-6" key={`sub-${index}`}>
              {renderSubOptions(values[key].sub_options, values[key].display)}
            </div>
          )
        ))
      )}
    </div>
  );
};

export default RadioComponent;