import React, { useState } from 'react'
import UsersTab from '../../UserManagement/UsersTab/UsersTab';
import PasswordLessLogin from '../../UserManagement/PasswordLessLogin/PasswordLessLogin';

const UserManagementWidget = () => {    
    const [activeTab, setActiveTab] = useState("Users");        
const [loading, setLoading] = useState(true);
    const getTabsForFilter = () => {
        return ["Users", "Passwordless Login"];
    };

    const renderTabContent = () => {
        return (
            <div className="bg-white rounded-lg shadow-sm mt-4">
                <div className="text-gray-600">
                    {activeTab === "Users" && <UsersTab loading={loading} setLoading={setLoading} widget={true} />}
                    {activeTab === "Passwordless Login" && <PasswordLessLogin loading={loading} setLoading={setLoading} widget={true} limit={5} />}                   
                </div>
            </div>
        );
    };

    return (
        <div className="bg-white rounded-xl shadow-lg border border-gray-300 overflow-hidden transition-all duration-300 hover:shadow-xl flex flex-col">
            <div className="relative">
                <div className="absolute top-0 right-0 py-1 px-3 text-xs font-medium text-white rounded-bl-lg bg-[#007980]">
                    Active
                </div>
            </div>
            <div className="p-4">                
                <div className="border-b border-gray-200">
                    <nav className="-mb-px flex space-x-8">
                        {getTabsForFilter().map((tab) => (
                            <button 
                                key={tab} 
                                onClick={() => setActiveTab(tab)} 
                                className={`py-2 px-1 border-b-2 font-medium text-sm transition-colors duration-200 ${
                                    activeTab === tab 
                                        ? 'border-[#007980] text-[#007980]' 
                                        : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                                }`}
                            >
                                {tab}
                            </button>
                        ))}
                    </nav>
                </div>
                {renderTabContent()}
            </div>
            <div className="h-2 w-full bg-gradient-to-r from-[#007980] to-[#85cbcf]"></div>
        </div>
    )
}

export default UserManagementWidget