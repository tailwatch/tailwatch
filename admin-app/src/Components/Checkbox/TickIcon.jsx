import React from 'react';

// The tick in a ticked feature checkbox: a brush stroke drawn for the plugin, thin at both ends.
const TICK_PATH =
  'M0.9 12.2 C3.8 14.8 6.6 17.8 9.9 22.0 C13.0 14.6 17.8 7.4 23.6 1.6 L22.2 0.8 C16.8 5.4 12.8 10.6 9.8 15.8 C7.2 13.9 4.4 12.0 2.0 10.6 Z';

const TickIcon = ({ className = '' }) => (
  <svg viewBox="0 0 24 24" width="1em" height="1em" fill="currentColor" className={className} aria-hidden="true" focusable="false">
    <path d={TICK_PATH} />
  </svg>
);

export default TickIcon;
