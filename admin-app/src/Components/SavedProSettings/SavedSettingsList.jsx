import React from "react";

// One saved or given-back Tailwatch Pro setting per line: "Feature — Setting: Choice"; as
// chips, "Feature · Setting: Choice", for a compact card.
const SavedSettingsList = ({ entries = [], className = "", variant = "list" }) =>
  variant === "chips" ? (
    <ul className={`flex flex-wrap gap-1.5 ${className}`}>
      {entries.map((entry, index) => (
        <li
          key={`${entry.feature}-${entry.setting}-${index}`}
          className="rounded-full border border-brand-100 bg-brand-50 px-2.5 py-0.5 !text-xs leading-5 text-brand-800"
        >
          <span className="font-semibold text-brand-900">{entry.feature}</span>
          {entry.setting ? ` · ${entry.setting}: ` : " · "}
          <span>{entry.choice}</span>
        </li>
      ))}
    </ul>
  ) : (
  <ul className={`space-y-1 ${className}`}>
    {entries.map((entry, index) => (
      <li key={`${entry.feature}-${entry.setting}-${index}`} className="!text-sm text-gray-700 leading-snug">
        <span className="font-medium text-gray-800">{entry.feature}</span>
        {entry.setting ? ` — ${entry.setting}: ` : " — "}
        <span>{entry.choice}</span>
      </li>
    ))}
  </ul>
  );

export default SavedSettingsList;
