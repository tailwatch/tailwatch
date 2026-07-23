import { useEffect, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import PopUpModal from '../Modal/PopUpModal'
import { getSystemSettings } from '../../Pages/Settings/SystemSettings/SystemSettingService/SystemSettingService'
import { Spinner } from '../Spinner/Spinner'
import ActionButton from '../Buttons/ActionButton'
import { Settings } from 'lucide-react'

const SystemInfo = ({ section, handleCloseSystemInfoModal, loading }) => {
    const [systemData, setSystemData] = useState(null)
    const [isLoading, setIsLoading] = useState(true)
    const [error, setError] = useState(null)
    const navigate = useNavigate()

    const handleSystemInfo = async () => {
        try {
            setIsLoading(true)
            const response = await getSystemSettings({ section })

            if (response?.data?.data?.code === 200) {
                setSystemData(response.data.data.data)
            } else {
                setError('Failed to fetch system information')
            }
        } catch (err) {
            setError('Error fetching system information: ' + err.message)
        } finally {
            setIsLoading(false)
        }
    }

    const handleGoToSystemSettings = () => {
        handleCloseSystemInfoModal()
        navigate('/dashboard/settings/system')
    }

    useEffect(() => {
        handleSystemInfo();
    }, [section])

    const renderSystemInfoItem = (label, value) => (
        <div className="flex justify-between items-center py-2 border-b border-gray-200">
            <span className="font-medium text-gray-700">{label}:</span>
            <span className="text-gray-900">{value}</span>
        </div>
    )

    const renderContent = () => {
        if (isLoading) {
            return (
                <div className="flex justify-center items-center h-64">
                    <Spinner />
                </div>
            )
        }

        if (error) {
            return (
                <div className="text-red-500 text-center p-4">
                    {error}
                </div>
            )
        }

        if (!systemData) {
            return (
                <div className="text-gray-500 text-center p-4">
                    No system information available
                </div>
            )
        }

        return (
            <div className="space-y-6">
                {/* Metadata Section */}
                {systemData.metadata && (
                    <div className="bg-gray-50 p-4 rounded-lg">
                        <div className="flex justify-between items-center">
                            <h3 className="text-lg font-semibold mb-3 text-gray-800">Metadata</h3>
                            <ActionButton
                                defaultText="Go to System Settings"
                                onClick={handleGoToSystemSettings}
                                className="!bg-[#007980] hover:!bg-[#006570] !text-white px-4 py-2 rounded-lg flex items-center gap-2 transition-colors duration-200"
                                icon={Settings}
                            />
                        </div>

                        <div className="space-y-2">
                            {renderSystemInfoItem('Generated At', systemData.metadata.generated_at)}
                            {renderSystemInfoItem('Timezone', systemData.metadata.timezone)}
                        </div>
                    </div>
                )}

                {/* PHP Configuration Section */}
                {systemData.php_configuration && (
                    <div className="bg-gray-50 p-4 rounded-lg">
                        <h3 className="text-lg font-semibold mb-3 text-gray-800">PHP Configuration</h3>
                        <div className="space-y-2">
                            {renderSystemInfoItem('PHP Version', systemData.php_configuration.php_version)}
                            {renderSystemInfoItem('PHP SAPI', systemData.php_configuration.php_sapi)}
                            {renderSystemInfoItem('Memory Limit', systemData.php_configuration.memory_limit)}
                            {renderSystemInfoItem('Max Execution Time', systemData.php_configuration.max_execution_time)}
                            {renderSystemInfoItem('Max Input Time', systemData.php_configuration.max_input_time)}
                            {renderSystemInfoItem('Max Input Vars', systemData.php_configuration.max_input_vars)}
                            {renderSystemInfoItem('Max File Uploads', systemData.php_configuration.max_file_uploads)}
                            {renderSystemInfoItem('Post Max Size', systemData.php_configuration.post_max_size)}
                            {renderSystemInfoItem('Upload Max Filesize', systemData.php_configuration.upload_max_filesize)}
                            {renderSystemInfoItem('Allow URL Fopen', systemData.php_configuration.allow_url_fopen)}
                            {renderSystemInfoItem('Allow URL Include', systemData.php_configuration.allow_url_include)}
                            {renderSystemInfoItem('Display Errors', systemData.php_configuration.display_errors)}
                            {renderSystemInfoItem('Log Errors', systemData.php_configuration.log_errors)}
                            {renderSystemInfoItem('Error Reporting', systemData.php_configuration.error_reporting)}
                            {renderSystemInfoItem('Session Save Path', systemData.php_configuration.session_save_path)}
                            {renderSystemInfoItem('Temp Directory', systemData.php_configuration.temp_directory)}
                            {renderSystemInfoItem('Loaded Extensions Count', systemData.php_configuration.loaded_extensions_count)}
                            {renderSystemInfoItem('Disabled Functions Count', systemData.php_configuration.disabled_functions_count)}
                        </div>
                    </div>
                )}
            </div>
        )
    }

    return (
        <PopUpModal title="System Information" showExpandIcon={true} isLoading={loading} onClose={handleCloseSystemInfoModal} width="w-[50%]" cancelButtonText="Close" height="max-h-[90vh]" >
            <div>
                {renderContent()}

            </div>
        </PopUpModal>
    )
}

export default SystemInfo