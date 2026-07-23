import React, { useState, useEffect } from 'react';
import { useLocation } from 'react-router-dom';
import { getIpDataDetails } from '../BruteForceService/BruteForceService';
import CurrentActivityData from './CurrentActivityData/CurrentActivityData';
import Header from '../../../Components/Header/Header';
import HistoryData from './HistoryData/HistoryData';
import Pagination from '../../../Components/Pagination/Pagination';
import { SecurityDetailSkeleton } from '../../../Components/Skeleton/LoaderSkeleton';
import IconButton from '../../../Components/Buttons/IconButton';
import {RefreshCcw}  from 'lucide-react'
const IpDetails = () => {
  const location = useLocation()
  const [pagination, setPagination] = useState({ page: 0, limit: 10, total_pages: 0, total_items: 0 });
  const [loading, setLoading] = useState(true);
  const [ipData, setIpData] = useState([]);
  const [currentActivity, setCurrentActivity] = useState(null);
  const [refreshing,setRefreshing]=useState(false);
  const getIpfromUrl = () => {
    const searchParams = new URLSearchParams(location.search)
    const encodedIp = searchParams.get('ip')

    if (encodedIp) {
      const decodedIp = decodeURIComponent(encodedIp)
      return decodedIp
    } else {
      return null
    }
  }

  const getIpDetailsData = async (page = pagination.page, limit = pagination.limit) => {
    const ip = getIpfromUrl()
    if (ip) {
      await getIpDataDetails({
        ip, setLoading, setIpData, setCurrentActivity, page: page + 1, limit,
        setPagination: (paginationData) => {
          setPagination({
            page: paginationData.page - 1,
            limit: paginationData.limit,
            total_pages: paginationData.total_pages,
            total_items: paginationData.total_items || 0
          });
        }
      });
    }
  }

  useEffect(() => {
    getIpDetailsData();
  }, [])
  const handlePageChange = (newPage) => {
    setPagination(prev => ({ ...prev, page: newPage }));
    getIpDetailsData(newPage, pagination.limit);
  };

  const handlePageSizeChange = (newPageSize) => {
    setPagination(prev => ({ ...prev, limit: newPageSize, page: 0 }));
    getIpDetailsData(0, newPageSize);
  };

   const handleRefresh = async () => {
        setRefreshing(true);        
        setPagination(prev => ({ ...prev, page: 0 }));        
        await getIpDetailsData(0, pagination.limit);
        setRefreshing(false);
    };

  return (
    <div>
      <Header title="Security Status" showBackIcon={true} />
        <div className = 'px-4 pt-4'><IconButton onClick={handleRefresh} icon={RefreshCcw} bgColor={`bg-gradient-to-br from-lime-50 via-green-100 to-teal-200 bg-opacity-10`} textColor="text-green-700" roundedFull={true}  className="!p-2 !rounded-[5px] transition duration-200 hover:shadow-sm"  /></div>
      <div className='p-4'>
        {loading ? (
          <SecurityDetailSkeleton />
        ) : (
          <>
            <CurrentActivityData currentActivity={currentActivity} />
            <HistoryData ipData={ipData} />
            <Pagination currentPage={pagination.page} totalPages={pagination.total_pages} onPageChange={handlePageChange} hasData={ipData.length > 0} pageSizeOptions={[5, 10, 20, 30, 50, 100]} totalItems={pagination.total_items} showPageSizeFilter={true} pageSize={pagination.limit} onPageSizeChange={handlePageSizeChange} />
          </>
        )}
      </div>
    </div>
  );
};

export default IpDetails;