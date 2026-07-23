import React, { useState, useEffect, useRef } from 'react';
import { BarChart, Bar, XAxis, YAxis, Tooltip, Cell, LabelList, ResponsiveContainer } from 'recharts';
import { RefreshCcw, Folder } from 'lucide-react';
import { Spinner } from '../../Components/Spinner/Spinner';
import IconButton from '../../Components/Buttons/IconButton';
import Skeleton from 'react-loading-skeleton';
import { otherGradient, bgprimary, iconprimary, primaryGreen } from '../../Components/Utils/Colors/Colors'

const convertToMB = (size) => {
  if (!size) return 0;

  const [value, unit] = size.split(' ');
  const numericValue = parseFloat(value);

  if (unit === 'GB') return numericValue * 1024;
  if (unit === 'MB') return numericValue;
  if (unit === 'KB') return numericValue / 1024;
  if (unit === 'B') return numericValue / (1024 * 1024);

  return 0;
};

const ColumnChart = ({ data, fetchData, isLoading }) => {
  const [chartReady, setChartReady] = useState(false);
  const [internalLoading, setInternalLoading] = useState(false);
  const effectiveLoading = isLoading || internalLoading;

  const lordIconRef = useRef();

  const handleCardMouseEnter = () => {
    if (lordIconRef.current) {
      lordIconRef.current.play();
    }
  };

  const handleCardMouseLeave = () => {
    if (lordIconRef.current) {
      lordIconRef.current.stop();
    }
  };

  useEffect(() => {
    if (data) {
      setChartReady(true);
      setInternalLoading(false);
    }
  }, [data]);

  const handleRefetch = async () => {
    setInternalLoading(true);
    try {
      await fetchData();
    } catch (err) {
      setInternalLoading(false);
    }
  };

  if (!data || effectiveLoading) {
    return (
      <div className="py-6">
        <Skeleton height={447} borderRadius={12} />
      </div>
    );
  }

  const chartCategories = [
    { label: 'Root', key: 'root' },
    { label: 'WP-Admin', key: 'wp-admin' },
    { label: 'WP-Includes', key: 'wp-includes' },
    { label: 'WP-Content', key: 'wp-content' },
    { label: 'Plugins', key: 'plugins' },
    { label: 'Themes', key: 'themes' },
    { label: 'Uploads', key: 'uploads' },
  ];

  const categoryColors = [
    '#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF', '#FF9F40', '#C9CBCF'
  ];

  try {
    const chartData = chartCategories.map(({ label, key }, index) => {
      const originalSize = data[key] || '0 B';
      const sizeInMB = convertToMB(originalSize);
      return {
        label,
        sizeInMB,
        originalSize,
        color: categoryColors[index],
      };
    });

    const isMobile = window.innerWidth < 640;

    return (
      <div
        className={`rounded-xl shadow-lg ${otherGradient} border border-[#85cbcf] overflow-hidden transition-all duration-300 hover:shadow-xl p-3 sm:p-4 md:p-6`}
        onMouseEnter={handleCardMouseEnter}
        onMouseLeave={handleCardMouseLeave}
      >
        <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-4 gap-3">
          <div className="flex items-center w-full sm:w-auto">
            <div className={`w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-[${primaryGreen}] flex items-center justify-center mr-2 sm:mr-3 flex-shrink-0`}>
              <Folder size={isMobile ? 20 : 24} className="text-[#007980]" />
            </div>
            <div className="flex-1 min-w-0">
              <h2 className="text-base sm:text-lg font-semibold text-black truncate">File Storage</h2>
              <p className="text-xs sm:text-sm text-black line-clamp-2 sm:line-clamp-1">
                Monitor website file directories and their storage.
              </p>
            </div>
          </div>

          <div className="flex items-center space-x-2 sm:space-x-3 w-full sm:w-auto justify-end">
            <div className={`py-1 px-2 sm:px-3 text-xs font-medium text-white rounded-lg ${bgprimary} whitespace-nowrap`}>
              Total: {data?.total_site_size || "N/A"}
            </div>
            <IconButton
              onClick={handleRefetch}
              icon={RefreshCcw}
              bgColor={`bg-[${primaryGreen}]`}
              hoverBgColor="hover:bg-[#07C07E1A]"
              textColor={iconprimary}
              roundedFull={true}
              className="!p-2 !rounded-[5px] transition duration-200 hover:shadow-sm flex-shrink-0"
            />
          </div>
        </div>

        <div className="bg-white rounded-lg shadow-sm p-2 sm:p-3 md:p-4 border border-blue-100">
          <div className="w-full h-64 sm:h-80 md:h-96">
            {chartReady ? (
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={chartData} margin={{ top: 20, right: 20, left: 10, bottom: isMobile ? 40 : 30 }}>
                  <XAxis
                    dataKey="label"
                    tick={{ fill: '#4B5563', fontSize: isMobile ? 9 : 10 }}
                    angle={isMobile ? -45 : 0}
                    textAnchor={isMobile ? 'end' : 'middle'}
                    interval={0}
                    height={isMobile ? 60 : 40}
                    label={isMobile ? null : { value: 'Folder Structure', position: 'insideBottom', offset: -10, style: { fill: '#374151', fontSize: 11 } }}
                  />
                  <YAxis
                    tick={{ fill: '#4B5563' }}
                    label={{ value: 'Size (MB)', angle: -90, position: 'insideLeft', style: { fill: '#374151' } }}
                  />
                  <Tooltip
                    cursor={{ fill: 'rgba(0,0,0,0.04)' }}
                    formatter={(value, name, item) => [item?.payload?.originalSize, 'Size']}
                    labelStyle={{ color: '#111' }}
                  />
                  <Bar dataKey="sizeInMB" radius={[4, 4, 0, 0]}>
                    {chartData.map((entry, index) => (
                      <Cell key={`cell-${index}`} fill={entry.color} />
                    ))}
                    <LabelList
                      dataKey="originalSize"
                      position="top"
                      style={{ fontSize: isMobile ? 9 : 11, fill: '#000', fontWeight: 700 }}
                    />
                  </Bar>
                </BarChart>
              </ResponsiveContainer>
            ) : (
              <div className="flex justify-center items-center h-full"><Spinner /></div>
            )}
          </div>
        </div>
      </div>
    );
  } catch (error) {
    return (
      <div className={`rounded-xl shadow-lg ${otherGradient} border border-[#85cbcf] overflow-hidden transition-all duration-300 hover:shadow-xl p-3 sm:p-4 md:p-6`}>
        <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between mb-4 gap-3">
          <div className="flex items-center w-full sm:w-auto">
            <div className="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-[#07C07E1A] flex items-center justify-center mr-2 sm:mr-3 flex-shrink-0">
              <Folder size={window.innerWidth < 640 ? 16 : 20} className="text-[#007980]" />
            </div>
            <div className="flex-1 min-w-0">
              <h2 className="text-base sm:text-lg font-semibold text-black truncate">File Storage</h2>
              <p className="text-xs sm:text-sm text-black line-clamp-2 sm:line-clamp-1">
                Monitor website file directories and their storage.
              </p>
            </div>
          </div>

          <div className="flex items-center space-x-2 sm:space-x-3 w-full sm:w-auto justify-end">
            <div className="py-1 px-2 sm:px-3 text-xs font-medium text-white rounded-lg bg-gradient-to-r from-red-500 to-pink-600 whitespace-nowrap">
              Error
            </div>
            <IconButton
              onClick={handleRefetch}
              icon={RefreshCcw}
              bgColor={`bg-[${primaryGreen}]`}
              hoverBgColor="hover:bg-[#07C07E1A]"
              textColor={iconprimary}
              roundedFull={true}
              className="!p-2 !rounded-[5px] transition duration-200 hover:shadow-sm flex-shrink-0"
            />
          </div>
        </div>

        <div className="bg-white rounded-lg shadow-sm p-2 sm:p-3 md:p-4 border border-red-100 flex items-center justify-center h-64 sm:h-80 md:h-96">
          <div className="text-red-600 font-medium flex flex-col items-center text-center px-4">
            <div className="mb-2 text-lg sm:text-xl">Error loading chart</div>
            <div className="text-xs sm:text-sm">Please try again later</div>
          </div>
        </div>
      </div>
    );
  }
};

export default ColumnChart;
