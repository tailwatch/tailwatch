import { configureStore } from '@reduxjs/toolkit';
import scanReducer from './ScanSlice';

const store = configureStore({
  reducer: {
    scan: scanReducer,
  },
});

export default store;
