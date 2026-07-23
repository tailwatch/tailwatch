import React, { useState, useEffect } from 'react';

const ToggleSwitch = ({ checked, onChange, disabled, id, skipLoading = false }) => {
  const [isLoading, setIsLoading] = useState(false);
  const [internalState, setInternalState] = useState({
    checked: checked,
    disabled: disabled
  });

  useEffect(() => {
    // When skipLoading is on, never run the artificial 600ms loading transition.
    // Just keep internalState in lockstep with the props — that's enough for the
    // visual style to update, and it prevents the skeleton from getting stuck when
    // the parent does optimistic + revert (e.g. LockCard).
    if (skipLoading) {
      setInternalState({ checked, disabled });
      if (isLoading) setIsLoading(false);
      return;
    }

    if (internalState.checked !== checked) {
      setIsLoading(true);
      const timer = setTimeout(() => {
        setInternalState({ checked, disabled });
        setIsLoading(false);
      }, 600);
      return () => clearTimeout(timer);
    }

    // Defensive: if `checked` already matches internal state (e.g. an optimistic flip
    // was reverted before the 600ms timer fired), make sure isLoading can't stay stuck
    // and still sync `disabled` if it changed.
    if (isLoading) setIsLoading(false);
    if (internalState.disabled !== disabled) {
      setInternalState(prev => ({ ...prev, disabled }));
    }
  }, [checked, disabled, skipLoading]);

  const Skeleton = ({ width, height, borderRadius }) => (
    <div className="animate-pulse bg-gray-300" style={{ width: `${width}px`, height: `${height}px`, borderRadius: `${borderRadius}px` }}></div>
  );

  if (isLoading) {
    return <Skeleton width={40} height={25} borderRadius={999} />;
  }

  const handleChange = (e) => {
    if (internalState.disabled || !onChange) return;
    if (!skipLoading) setIsLoading(true);
    onChange(e);
  };

  return (
    <label className={`inline-flex items-center ${internalState.disabled ? "cursor-not-allowed" : "cursor-pointer"}`} data-switch-id={id}>
      <input type="checkbox" className="sr-only" checked={internalState.checked} onChange={handleChange} disabled={internalState.disabled} />
      <div className="relative">
        <div
          className={`w-10 h-4 rounded-full shadow-inner transition ${disabled ? "bg-gray-300" : checked ? "bg-[#007980]" : "bg-gray-200"}`}
        ></div>
        <div
          className={`dot absolute w-6 h-6 border border-gray-300 rounded-full shadow transition transform ${internalState.disabled ? "bg-gray-400" : "bg-white"
            } ${internalState.checked ? "translate-x-full -left-1" : "-left-1"}`}
          style={{ top: "-0.25rem", transition: "transform 0.2s ease" }}
        ></div>
      </div>
    </label>
  );
};

export default ToggleSwitch;
