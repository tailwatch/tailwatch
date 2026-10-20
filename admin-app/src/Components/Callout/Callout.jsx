import React from "react";

/**
 * A quiet callout for a setting that is off, and the way to turn it on.
 *
 * Deliberately not an InfoBar, which is a page-level alarm: a setting that is switched off
 * is not a fault, and is usually a decision the operator made on purpose.
 *
 * A white card with the brand down its leading edge. The accent marks it as actionable
 * without tinting the whole box, which keeps the text as readable as the rest of the page,
 * and the action gets a real button rather than being wedged into a strip.
 *
 * Every text class is forced. This renders inside wp-admin, whose stylesheet has its own
 * opinions about `p` — margins and a font size of its own — and wins the cascade against a
 * plain utility class.
 */
const Callout = ({ icon: Icon, title, children, action }) => (
  <div className="relative overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
    <span aria-hidden="true" className="absolute inset-y-0 left-0 w-[3px] bg-brand-600" />

    <div className="flex flex-col gap-3 py-4 pl-5 pr-4 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex items-start gap-3">
        {Icon && (
          <span className="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg border border-brand-100 bg-brand-50 text-brand-600">
            <Icon className="h-[18px] w-[18px]" aria-hidden="true" />
          </span>
        )}

        <div className="min-w-0">
          <p className="!m-0 !text-sm !font-semibold !leading-snug !text-gray-900">{title}</p>
          {children && (
            <p className="!mb-0 !mt-1 !text-[13px] !leading-relaxed !text-gray-600">
              {children}
            </p>
          )}
        </div>
      </div>

      {/* Below the text on a phone, beside it from `sm` up — a button that has to share a
          line with three lines of explanation ends up squeezed on a narrow screen. */}
      {action && <div className="shrink-0 sm:pl-4">{action}</div>}
    </div>
  </div>
);

export default Callout;
