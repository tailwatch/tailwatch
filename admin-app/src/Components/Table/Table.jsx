import React from "react";
import { Inbox } from 'lucide-react';
import { tableGradient } from '../Utils/Colors/Colors';

const Table = ({ 
  columns, 
  data, 
  renderRow, 
  noDataMessage,
  expandedRow,
  renderExpandedRow,
  onRowClick 
}) => {
  return (
    <div className="w-full overflow-x-auto rounded-lg border border-gray-300">
      <table className="w-full table-auto min-w-[600px]">
        <thead>
          <tr className={`${tableGradient} border-b border-[#85cbcf] text-white text-xs sm:text-sm uppercase tracking-wider`}>
            {columns.map((column, index) => (
              <th 
                key={index} 
                scope="col" 
                className="p-2 sm:p-3 text-left font-semibold text-white whitespace-nowrap"
              >
                {column}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {data.length > 0 ? (
            data.map((rowData, index) => (
              <React.Fragment key={index}>
                <tr 
                  className={`border-b hover:bg-gray-50 transition-colors ${onRowClick ? 'cursor-pointer' : ''}`}
                  onClick={() => onRowClick && onRowClick(index)}
                >
                  {renderRow(rowData, index)}
                </tr>
                {/* Render expanded row if it exists and this row is expanded */}
                {expandedRow === index && renderExpandedRow && (
                  <tr className="bg-gray-100">
                    <td colSpan={columns.length} className="px-3 sm:px-4 md:px-6 py-3 sm:py-4">
                      <div className="overflow-x-auto">
                        {renderExpandedRow(rowData, index)}
                      </div>
                    </td>
                  </tr>
                )}
              </React.Fragment>
            ))
          ) : (
            <tr>
              <td colSpan={columns.length} className="border-b text-center text-black py-6 sm:py-8">
                <div className="flex flex-col items-center justify-center space-y-2">
                  <Inbox size={48} className="text-gray-400" />
                  <span className="text-gray-500 text-sm sm:text-base px-4">{noDataMessage}</span>
                </div>
              </td>
            </tr>
          )}
        </tbody>
      </table>
    </div>
  );
};

export default Table;