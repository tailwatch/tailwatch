import React from "react";
import { Check, ChevronRight } from "lucide-react";
import { cn } from "../../../Components/Utils/cn";

/**
 * One thing that can be done, or one thing that can be picked.
 *
 * A migration is a short sequence of single choices — which direction, which backup — and
 * each of them replaces a whole website. They are given room to say what they mean: what
 * it is, what it holds, and, where it matters, which of the two sites ends up changed.
 * A select box would read its options once and then hide them, which is the wrong control
 * for a decision nobody makes twice.
 *
 * `trailing`: 'arrow' for a step that goes somewhere, 'radio' for one of several choices.
 * `chip`: the consequence, in three or four words. It is the thing people get wrong.
 */
const ChoiceCard = ({
  icon: Icon,
  title,
  subtitle,
  note,
  chip,
  chipTone = "neutral",
  selected = false,
  disabled = false,
  trailing = "arrow",
  onClick,
}) => (
  <button
    type="button"
    onClick={onClick}
    disabled={disabled}
    aria-pressed={trailing === "radio" ? selected : undefined}
    className={cn(
      "group relative w-full overflow-hidden rounded-2xl border bg-white p-4 text-left transition-all",
      "focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-600/30",
      disabled
        ? "cursor-not-allowed border-gray-200 bg-gray-50/60 opacity-70"
        : selected
        ? "border-brand-600 shadow-[0_1px_2px_rgba(0,0,0,0.04)] ring-1 ring-brand-600/20"
        : "border-gray-200 hover:-translate-y-px hover:border-brand-600/40 hover:shadow-md"
    )}
  >
    {/* A hairline down the leading edge: enough to mark the chosen row in a list of them
        without tinting the whole card and making the text harder to read. */}
    {selected && !disabled && (
      <span aria-hidden="true" className="absolute inset-y-0 left-0 w-1 bg-brand-600" />
    )}

    <div className="flex items-start gap-3.5">
      {Icon && (
        <span
          className={cn(
            "mt-0.5 flex h-10 w-10 shrink-0 items-center justify-center rounded-xl border transition-colors",
            disabled
              ? "border-gray-200 bg-white text-gray-400"
              : selected
              ? "border-brand-600/30 bg-brand-600 text-white"
              : "border-brand-100 bg-brand-50 text-brand-600 group-hover:border-brand-600/30"
          )}
        >
          <Icon className="h-[18px] w-[18px]" aria-hidden="true" />
        </span>
      )}

      <span className="min-w-0 flex-1">
        <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
          <span className="text-[14.5px] font-semibold leading-tight text-gray-900">
            {title}
          </span>
          {chip && (
            <span
              className={cn(
                "rounded-full border px-2 py-0.5 text-[10.5px] font-semibold uppercase tracking-wide",
                chipTone === "warning"
                  ? "border-amber-200 bg-amber-50 text-amber-700"
                  : chipTone === "good"
                  ? "border-emerald-200 bg-emerald-50 text-emerald-700"
                  : "border-gray-200 bg-gray-50 text-gray-500"
              )}
            >
              {chip}
            </span>
          )}
        </span>
        {subtitle && (
          <span className="mt-1 block text-[13px] leading-relaxed text-gray-500">
            {subtitle}
          </span>
        )}
        {note && <span className="mt-1.5 block text-xs text-amber-700">{note}</span>}
      </span>

      <span className="mt-1.5 shrink-0">
        {trailing === "radio" ? (
          <span
            className={cn(
              "flex h-[18px] w-[18px] items-center justify-center rounded-full border-2 transition-colors",
              selected
                ? "border-brand-600 bg-brand-600 text-white"
                : "border-gray-300 group-hover:border-brand-600/50"
            )}
            aria-hidden="true"
          >
            {selected && <Check className="h-3 w-3" strokeWidth={3} aria-hidden="true" />}
          </span>
        ) : (
          <ChevronRight
            className={cn(
              "h-4 w-4 transition-transform",
              disabled
                ? "text-gray-300"
                : "text-gray-400 group-hover:translate-x-0.5 group-hover:text-brand-600"
            )}
            aria-hidden="true"
          />
        )}
      </span>
    </div>
  </button>
);

export default ChoiceCard;
