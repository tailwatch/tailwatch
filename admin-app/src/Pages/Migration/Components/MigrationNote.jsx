import React from "react";
import { cn } from "../../../Components/Utils/cn";

const TONES = {
  neutral: { box: "border-amber-200 bg-amber-50/70", icon: "text-amber-600" },
  warning: { box: "border-amber-200 bg-amber-50/70", icon: "text-amber-600" },
  good: { box: "border-emerald-200 bg-emerald-50/70", icon: "text-emerald-600" },
  bad: { box: "border-red-200 bg-red-50/70", icon: "text-red-600" },
  brand: { box: "border-brand-100 bg-brand-50/70", icon: "text-brand-600" },
};

/**
 * One quiet line that qualifies what is on the screen: what a move will do to the
 * receiving site, why something is in the way, what the plan includes.
 *
 * Deliberately not InfoBar. InfoBar is a page-level banner — it sits above the content,
 * owns a full-width strip, and its amber gradient reads as an alarm every time. These sit
 * inside the flow, several can be on screen at once, and most of them are not alarming:
 * "the other site named this backup" is a fact, not a warning.
 */
const MigrationNote = ({ icon: Icon, tone = "neutral", title, children, action }) => {
  const style = TONES[tone] || TONES.neutral;

  return (
    <div className={cn("flex items-start gap-3 rounded-xl border px-3.5 py-3", style.box)}>
      {Icon && <Icon className={cn("mt-0.5 h-4 w-4 shrink-0", style.icon)} aria-hidden="true" />}
      <div className="min-w-0 flex-1 text-[13px] leading-relaxed text-gray-600">
        {title && <span className="font-semibold text-gray-900">{title}</span>}
        {title && children ? " " : null}
        {children}
      </div>
      {action && <div className="shrink-0">{action}</div>}
    </div>
  );
};

export default MigrationNote;
