import React from "react";
import { ArrowLeft } from "lucide-react";
import MigrationSteps from "./MigrationSteps";

/**
 * The top of every step of a move: the way back, where you are, and what is being asked.
 *
 * One component because these four lines have to sit in the same place on every screen of
 * the sequence. A wizard whose heading jumps around between steps reads as several
 * unrelated pages that happen to follow each other.
 */
const ScreenHeader = ({ eyebrow, title, lede, steps, step, onBack, disabled = false }) => (
  <div className="space-y-4">
    {onBack && (
      <button
        type="button"
        onClick={onBack}
        disabled={disabled}
        className="inline-flex items-center gap-1.5 rounded-lg border border-gray-200 bg-white px-2.5 py-1.5 text-[12.5px] font-medium text-gray-600 transition-colors hover:border-gray-300 hover:text-gray-900 disabled:cursor-not-allowed disabled:opacity-60"
      >
        <ArrowLeft className="h-3.5 w-3.5" aria-hidden="true" />
        Back
      </button>
    )}

    {typeof step === "number" && <MigrationSteps steps={steps} now={step} />}

    <div>
      {eyebrow && (
        <p className="text-[11px] font-bold uppercase tracking-wider text-brand-700">
          {eyebrow}
        </p>
      )}
      <h2 className="mt-1 text-fluid-xl font-semibold tracking-tight text-gray-900">
        {title}
      </h2>
      {lede && (
        <p className="mt-2 max-w-2xl text-fluid-sm leading-relaxed text-gray-500">{lede}</p>
      )}
    </div>
  </div>
);

export default ScreenHeader;
