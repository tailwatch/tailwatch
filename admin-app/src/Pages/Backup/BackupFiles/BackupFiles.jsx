import { useState, useEffect } from "react";
import { useNavigate, useLocation } from "react-router-dom";
import LoaderSkeleton from "../../../Components/Skeleton/LoaderSkeleton.jsx";
import BackupContent from "./BackupContent/BackupContent.jsx";
import { deleteBackupFolder, fetchBackupFiles } from '../../Backup/BackupServices/BackupServices.jsx';
import Pagination from "../../../Components/Pagination/Pagination.jsx";
/* global wptw_ajax */

const BackupFiles = ({ setErrorMessages,backupStatus}) => {    
  const [activeTab, setActiveTab] = useState("Files");
  const [backupFiles, setBackupFiles] = useState([]);
  const [currentPage, setCurrentPage] = useState(0);  
  const [pageSize, setPageSize] = useState(10);
  const [loading, setLoading] = useState(true);
  const [pagination, setPagination] = useState({ page: 1, limit: 10, total_pages: 0, total_items: 0 });
  const [deletingFileId, setDeletingFileId] = useState(null);
  const navigate = useNavigate();
  const location = useLocation();

  useEffect(() => {
    const currentPath = location.pathname.split('/').pop();
    if (currentPath === "files") {
      setActiveTab(currentPath.charAt(0).toUpperCase() + currentPath.slice(1));
    } else {
      navigate("/dashboard/backup/files");
    }
  }, [location.pathname, navigate]);

  useEffect(() => {    
      loadBackupFiles();    
  }, [activeTab, currentPage, pageSize]);

  const loadBackupFiles = async () => {
    setLoading(true);
    try {      
      const apiPage = currentPage + 1;
      const files = await fetchBackupFiles(setErrorMessages, setPagination, apiPage, pageSize);
      setBackupFiles(files?.data ?? []);
    } catch (error) {
      console.error('Error loading backup files:', error);
      setBackupFiles([]);
    } finally {
      setLoading(false);
    }
  };

   const handleDelete = async (deleteData) => {
    await deleteBackupFolder({
      deleteData,
      pagination,
      currentPage,
      pageSize,
      setDeletingFileId,
      setCurrentPage,
      loadBackupFiles,
    });
  };
 

  const handlePageChange = (newPage) => {
    setCurrentPage(newPage);
  };

  const handlePageSizeChange = (newPageSize) => {
    setPageSize(newPageSize);
    setCurrentPage(0); 
  };

  const renderTabContent = () => {
    return (
      <div>        
        <BackupContent activeTab={activeTab} backupStatus={backupStatus} currentPageData={backupFiles} handleDelete={handleDelete} deletingFileId={deletingFileId} />
      </div>
    );
  };

  return (
    <div>      
      {loading ? (
        <LoaderSkeleton count={0} />
      ) : (
        <>
          {renderTabContent()}
          {pagination.total_items > 0 && (
            <Pagination currentPage={currentPage} totalPages={pagination.total_pages} onPageChange={handlePageChange}hasData={backupFiles.length > 0} showPageSizeFilter={true} pageSize={pageSize} onPageSizeChange={handlePageSizeChange} totalItems={pagination.total_items} pageSizeOptions={[5, 10, 20, 50]} />
          )}
        </>
      )}      
    </div>
  );
};

export default BackupFiles;