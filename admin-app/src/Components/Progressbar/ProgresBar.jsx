import React from 'react';
import ProgressBar from "@ramonak/react-progress-bar";

const ProgresBar = ({ progress,bgColor }) => {
    
    return (
        <div>
            <ProgressBar completed={progress} bgColor={bgColor} />
        </div>
    );
};

export default ProgresBar;
