import React from 'react'

const UpdateButton = ({
    updateAvailable,
    updateStatus,
    rollbackStatus,
    handleUpdateClick,
    handleRollbackClick,    
}) => {
    return (
        <div className="mt-[20px]">
            {updateAvailable ? (
                <button
                    className={`px-5 py-2 rounded-md text-white ${updateStatus === 'completed' ? 'bg-gray-300' : 'bg-[#007980] hover:bg-opacity-80'}`}
                    onClick={handleUpdateClick}
                    disabled={updateStatus === 'updating...' || updateStatus === 'completed'}
                >
                    {updateStatus === 'completed' ? 'Up to Date' : updateStatus === 'updating...' ? 'Updating...' : 'Update'}
                </button>
            ) : (
                <button className="px-5 py-2 rounded-md text-white bg-gray-300" disabled>
                    Up to Date
                </button>
            )}
            {(updateStatus === 'completed' || !updateAvailable) && (
                <button
                    className="px-5 ml-[10px] py-2 rounded-md text-white bg-[#007980] hover:bg-opacity-80"
                    onClick={handleRollbackClick}
                    disabled={rollbackStatus === 'Rolling back...' || rollbackStatus === 'rollback completed'}
                >
                    {rollbackStatus === 'rollback completed' ? 'Completed' : rollbackStatus === 'Rolling back...' ? 'Rolling back...' : 'Rollback'}
                </button>
            )}            
        </div>
    )
}

export default UpdateButton