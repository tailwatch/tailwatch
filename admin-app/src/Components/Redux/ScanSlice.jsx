import { createSlice } from '@reduxjs/toolkit';

const initialState = {
  malwareStarted: false,
  resetDefault: false,
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
  },
});

export const { setMalwareStarted, setResetDefault } = scanSlice.actions;

export default scanSlice.reducer;
