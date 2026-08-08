import React, { useState, useEffect } from "react";
import { SlidersHorizontal, ChevronDown, Search } from "lucide-react";
import PopUpModal from "../Modal/PopUpModal";
import { CheckboxField } from "../Fields/CheckboxField";

/**
 * Dynamic log filters. A Filter button (with an active-count badge) sits in the
 * table control strip; clicking it opens a modal with one searchable
 * multi-select per facet returned by tailwatch_logs_filter_options
 * ({ column: { label, values[] } }). Each facet is a collapsible dropdown: a
 * search box appears once a facet has more than SEARCH_THRESHOLD values, and the
 * rendered list is capped at MAX_RENDER so the DOM stays light even at the
 * server-side DISTINCT ceiling (500). Selections collect in a local draft and
 * commit on Apply; a Reset beside the button clears applied filters.
 */
const SEARCH_THRESHOLD = 5; // show a per-facet search box past this many values (list starts scrolling ~6)
const MAX_RENDER = 100; // cap rendered rows; refine search to narrow further

const humanize = (v) =>
  String(v)
    .replace(/_alert$/, "")
    .replace(/_log$/, "")
    .replace(/_/g, " ")
    .replace(/\b\w/g, (c) => c.toUpperCase());

const countValues = (filters) =>
  Object.values(filters || {}).reduce(
    (n, v) => n + (Array.isArray(v) ? v.length : 0),
    0
  );

// Keep only facets that still hold at least one selected value.
const prune = (filters) => {
  const cleaned = {};
  Object.keys(filters || {}).forEach((col) => {
    if (Array.isArray(filters[col]) && filters[col].length > 0) {
      cleaned[col] = filters[col];
    }
  });
  return cleaned;
};

const LogFilters = ({ filterOptions = {}, selectedFilters = {}, onApply, onReset }) => {
  const facets = Object.keys(filterOptions).filter(
    (col) =>
      Array.isArray(filterOptions[col]?.values) &&
      filterOptions[col].values.length > 0
  );

  const [isOpen, setIsOpen] = useState(false);
  const [draft, setDraft] = useState(selectedFilters || {});
  const [openCols, setOpenCols] = useState({}); // which facet dropdowns are expanded
  const [search, setSearch] = useState({}); // per-facet search query

  // Seed the draft from applied filters and collapse everything each open.
  useEffect(() => {
    if (isOpen) {
      setDraft(selectedFilters || {});
      setOpenCols({});
      setSearch({});
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen]);

  if (facets.length === 0) return null;

  const labelFor = (col) => filterOptions[col]?.label || col;
  const displayVal = (col, val) => (col === "type" ? humanize(val) : String(val));
  const activeCount = countValues(selectedFilters);
  const draftCount = countValues(draft);

  const toggleDraft = (col, value) => {
    setDraft((prev) => {
      const cur = Array.isArray(prev[col]) ? prev[col] : [];
      const next = cur.includes(value)
        ? cur.filter((v) => v !== value)
        : [...cur, value];
      return { ...prev, [col]: next };
    });
  };

  const matches = (col, val, q) => {
    if (!q) return true;
    const needle = q.toLowerCase();
    return (
      String(val).toLowerCase().includes(needle) ||
      displayVal(col, val).toLowerCase().includes(needle)
    );
  };

  const handleApply = () => {
    onApply(prune(draft));
    setIsOpen(false);
  };

  return (
    <>
      <button
        type="button"
        onClick={() => setIsOpen(true)}
        className="!inline-flex !items-center !gap-2 !px-3 !py-1.5 sm:!py-2 !bg-white !border !border-gray-200 !rounded-lg !shadow-sm !text-xs sm:!text-sm !font-medium !text-gray-700 hover:!bg-gray-50 focus:!outline-none focus:!ring-1 focus:!ring-[#007980] focus:!border-[#007980]"
      >
        <SlidersHorizontal className="!w-4 !h-4 !text-[#007980]" />
        <span className="!whitespace-nowrap">Filter</span>
        {activeCount > 0 && (
          <span className="!inline-flex !items-center !justify-center !min-w-[18px] !h-[18px] !px-1 !text-[11px] !font-semibold !text-white !bg-[#007980] !rounded-full">
            {activeCount}
          </span>
        )}
      </button>

      {activeCount > 0 && (
        <button
          type="button"
          onClick={onReset}
          className="!text-xs !font-medium !text-gray-500 hover:!text-[#007980] !underline"
        >
          Reset
        </button>
      )}

      {isOpen && (
        <PopUpModal
          title="Filter Logs"
          onClose={() => setIsOpen(false)}
          onSave={handleApply}
          saveButtonText={draftCount > 0 ? `Apply (${draftCount})` : "Apply"}
          cancelButtonText="Cancel"
          leftButtonLabel="Clear all"
          onLeftButtonClick={() => setDraft({})}
          width="w-full max-w-lg"
        >
          <div>
            <p className="text-xs text-gray-500 leading-relaxed mb-3">
              These filters are generated dynamically from your log entries, so
              you only see values that actually appear in this log. Select any
              options below, then click Apply.
            </p>
            <div className="space-y-2">
            {facets.map((col) => {
              const values = filterOptions[col].values;
              const sel = Array.isArray(draft[col]) ? draft[col] : [];
              const expanded = !!openCols[col];
              const q = search[col] || "";
              const showSearch = values.length > SEARCH_THRESHOLD;
              const filtered = q ? values.filter((v) => matches(col, v, q)) : values;
              const shown = filtered.slice(0, MAX_RENDER);

              return (
                <div key={col} className="border border-gray-200 rounded-lg">
                  <button
                    type="button"
                    onClick={() =>
                      setOpenCols((p) => ({ ...p, [col]: !p[col] }))
                    }
                    className="w-full flex items-center justify-between px-3 py-2 text-left"
                  >
                    <span className="text-sm font-semibold text-gray-800">
                      {labelFor(col)}
                      {sel.length > 0 && (
                        <span className="ml-2 text-xs font-medium text-[#007980]">
                          ({sel.length} selected)
                        </span>
                      )}
                    </span>
                    <ChevronDown
                      className={`w-4 h-4 text-gray-400 transition-transform ${
                        expanded ? "rotate-180" : ""
                      }`}
                    />
                  </button>

                  {expanded && (
                    <div className="border-t border-gray-100 p-2">
                      {showSearch && (
                        <div className="relative mb-2">
                          <Search className="absolute left-2 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" />
                          <input
                            type="text"
                            value={q}
                            autoFocus
                            onChange={(e) =>
                              setSearch((p) => ({ ...p, [col]: e.target.value }))
                            }
                            placeholder={`Search ${labelFor(col).toLowerCase()}...`}
                            className="!w-full !pl-8 !pr-3 !py-1.5 !text-sm !bg-white !border !border-gray-200 !rounded-md focus:!ring-1 focus:!ring-[#007980] focus:!border-[#007980] !outline-none"
                          />
                        </div>
                      )}

                      <div className="max-h-44 overflow-y-auto custom-scroll-bar pr-1">
                        {shown.length === 0 ? (
                          <p className="text-xs text-gray-400 px-2 py-2">
                            No matches.
                          </p>
                        ) : (
                          shown.map((val) => (
                            <label
                              key={String(val)}
                              className="flex items-center gap-2 text-sm text-gray-700 cursor-pointer px-2 py-1 rounded hover:bg-gray-50"
                            >
                              <CheckboxField
                                checked={sel.includes(val)}
                                onChange={() => toggleDraft(col, val)}
                              />
                              <span className="truncate">
                                {displayVal(col, val)}
                              </span>
                            </label>
                          ))
                        )}
                      </div>

                      {filtered.length > MAX_RENDER && (
                        <p className="text-xs text-gray-400 px-2 pt-1">
                          Showing first {MAX_RENDER} of {filtered.length} — refine
                          your search to narrow.
                        </p>
                      )}
                    </div>
                  )}
                </div>
              );
            })}
            </div>
          </div>
        </PopUpModal>
      )}
    </>
  );
};

export default LogFilters;