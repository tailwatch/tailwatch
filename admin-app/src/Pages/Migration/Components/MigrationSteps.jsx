import React from "react";
import { FaCheck } from "react-icons/fa";
import { cn } from "../../../Components/Utils/cn";

/**
 * Where you are in a move, and what is still to come.
 *
 * The later steps are usually not this screen's — a transfer runs on the two sites and the
 * restore is watched on the Restore screen — but they are shown anyway, because the thing
 * being consented to is all of them. Someone who thinks choosing a backup is the end has
 * not been told what they are agreeing to.
 *
 * On a phone the row becomes one line — "Step 2 of 4 · Choose backup" over a rule that
 * fills as far as it has got. Four circles squeezed into 360px say less than that sentence
 * does, and take more room saying it.
 *
 * `steps` rather than a fixed list: the three routes through this module are different
 * lengths, and a shared indicator that always claimed four would be lying on two of them.
 */
const MigrationSteps = ({ steps = [], now = 0 }) => {
  if (!steps.length) return null;

  const pct = Math.round((now / Math.max(1, steps.length - 1)) * 100);

  return (
    <div>
      {/* Phone: one sentence and a rule. */}
      <div className="sm:hidden">
        <p className="text-[11px] font-semibold uppercase tracking-wider text-gray-500">
          Step {now + 1} of {steps.length} ·{" "}
          <span className="text-brand-700">{steps[now] || ""}</span>
        </p>
        <div className="mt-1.5 h-1 overflow-hidden rounded-full bg-gray-100">
          <div
            className="h-full rounded-full bg-brand-600 transition-all"
            style={{ width: `${pct}%` }}
          />
        </div>
      </div>

      {/* Desktop: the steps, with the line between them carrying the progress. */}
      <ol className="hidden items-center sm:flex">
        {steps.map((label, index) => {
          const done = index < now;
          const current = index === now;

          return (
            <li
              key={label}
              className={cn("flex items-center", index < steps.length - 1 && "flex-1")}
            >
              <div className="flex items-center gap-2">
                <span
                  aria-hidden="true"
                  className={cn(
                    "flex h-6 w-6 shrink-0 items-center justify-center rounded-full border text-[11px] font-semibold transition-colors",
                    done && "border-brand-600 bg-brand-600 text-white",
                    current && "border-brand-600 bg-white text-brand-700 ring-4 ring-brand-600/10",
                    !done && !current && "border-gray-200 bg-white text-gray-400"
                  )}
                >
                  {done ? <FaCheck className="h-2.5 w-2.5" aria-hidden="true" /> : index + 1}
                </span>
                <span
                  aria-current={current ? "step" : undefined}
                  className={cn(
                    "whitespace-nowrap text-[12.5px] transition-colors",
                    current
                      ? "font-semibold text-gray-900"
                      : done
                      ? "font-medium text-gray-600"
                      : "text-gray-400"
                  )}
                >
                  {label}
                </span>
              </div>

              {index < steps.length - 1 && (
                <span
                  aria-hidden="true"
                  className={cn(
                    "mx-3 h-px flex-1 rounded-full",
                    done ? "bg-brand-600/40" : "bg-gray-200"
                  )}
                />
              )}
            </li>
          );
        })}
      </ol>
    </div>
  );
};

export default MigrationSteps;
