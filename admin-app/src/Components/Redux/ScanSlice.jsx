import { createSlice } from '@reduxjs/toolkit';

const initialState = {
  malwareStarted: false,
  resetDefault: false,
  // The backup a restore is currently putting back, or "" when none is.
  //
  // A folder name rather than a boolean, because the screen this locks the operator to is
  // addressed by it — and a restore is the one process where being sent back to the wrong
  // URL means watching someone else's job while your own site is being replaced.
  restoreRunning: "",
};

const scanSlice = createSlice({
  name: 'scan',
  initialState,
  reducers: {
    setMalwareStarted: (state, action) => {
      state.malwareStarted = action.payload;
    },
    setResetDefault: (state, action) => {
      state.resetDefault = action.payload;
    },
    setRestoreRunning: (state, action) => {
      state.restoreRunning = action.payload || "";
    },
  },
});

export const { setMalwareStarted, setResetDefault, setRestoreRunning } = scanSlice.actions;

export default scanSlice.reducer;
