import React from "react";

/**
 * The bottom of a step: what this step costs, and the one press that leaves it.
 *
 * Given its own tinted bar rather than floating under the content, so that on a screen
 * whose list is long it still reads as the end of the step and not as another row of it.
 * The note on the left is where the reassurance goes — nothing has happened yet, sizes are
 * what will travel — because that is what people look for before pressing the button on
 * the right.
 */
const ActionBar = ({ note, children }) => (
  <div className="flex flex-col gap-3 rounded-2xl border border-gray-200 bg-gray-50/70 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
    {note ? <p className="text-xs leading-relaxed text-gray-500">{note}</p> : <span />}
    <div className="flex shrink-0 flex-wrap items-center gap-2.5">{children}</div>
  </div>
);

export default ActionBar;
