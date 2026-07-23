import React from 'react';
import TableContent from './TableContent/TableContent';
import { TableContentSkeleton } from "../../../Components/Skeleton/LoaderSkeleton";

const NoDataFound = () => {
    return (
        <div className="flex flex-col items-center justify-center py-16 px-4 text-center bg-gray-50 rounded-lg shadow-sm border border-gray-100">
            <h3 className="text-2xl font-semibold text-gray-700 mb-2">No Data Found</h3>
            <p className="text-gray-500 max-w-md mb-6">
                There are no database tables available at the moment.
            </p>
        </div>
    );
};

const DatabaseTables = ({ checkTableStatus, steps = {}, licenseConnected = false, proPluginActive = false, currentPlan = null, loading = false }) => {
    const hasSteps = steps && Object.keys(steps).length > 0;

    return (
        <div>
            <div className="mt-2">
                {loading ? (
                    <TableContentSkeleton />
                ) : (
                    <>
                        {checkTableStatus && (
                            <div className="mb-4 p-3 bg-green-50 text-green-800 rounded-md border border-green-200">
                                <p className="font-medium">The options you enabled for cleanup is already optimized</p>
                            </div>
                        )}
                        {hasSteps ? (
                            <TableContent steps={steps} licenseConnected={licenseConnected} proPluginActive={proPluginActive} currentPlan={currentPlan} />
                        ) : (
                            <NoDataFound />
                        )}
                    </>
                )}
            </div>
        </div>
    );
};

export default DatabaseTables;
