import React, { useState, useEffect } from 'react';
import { toast } from 'react-toastify';
import PopUpModal from '../../../Components/Modal/PopUpModal';

const UserRole = ({ show, handleClose, user, onSave, roles }) => {
  const [selectedRole, setSelectedRole] = useState(user.role);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (user) {
      setSelectedRole(user.role);
    }
  }, [user]);

  const handleRoleChange = (roleKey) => {
    setSelectedRole(roleKey);
  };

  const handleSave = async () => {
    setLoading(true);
    try {
      const success = await onSave(user.id, selectedRole);
      
      setLoading(false);
      if (success) {
        handleClose();        
      } 
    } catch (error) {
      setLoading(false);
      toast.error("An error occurred while changing the user role.");
    }
  };

  if (!show) return null;

  return (
    <PopUpModal title={`Change User Role for ${user.username}`} loading={loading} onSave={handleSave} onClose={handleClose} saveButtonText="Save" cancelButtonText="Cancel" >
      {roles.map((role) => (
        <div key={role.role_key} className={`!flex !items-center !justify-between !p-4 !mr-2 !border !rounded-lg !shadow-sm !cursor-pointer !transition !duration-200 ${selectedRole === role.role_key ? "!border-[#007980] !bg-gradient-to-br !from-blue-50 !to-[#85cbcf]" : "!border-gray-300 hover:!border-[#007980] hover:!bg-[#07C07E1A]"}`}
          onClick={() => handleRoleChange(role.role_key)}
        >
          <label htmlFor={role.role_key} className="!flex-1 !cursor-pointer">
            <span className="!font-semibold !text-black">{role.role_name}</span>
            <p className="!text-sm !text-black">{role.description}</p>
          </label>

          <input type="radio" id={role.role_key} name="user-role" value={role.role_key} checked={selectedRole === role.role_key} onChange={() => handleRoleChange(role.role_key)} className="!hidden" />
          <div className={`!w-5 !h-5 !flex !items-center !justify-center !border-2 !rounded-full !transition ${selectedRole === role.role_key ? "!border-[#007980] !bg-[#007980]" : "!border-gray-400 !bg-white"}`} >
            {selectedRole === role.role_key && (
              <span className="!w-2.5 !h-2.5 !bg-white !rounded-full"></span>
            )}
          </div>
        </div>
      ))}
    </PopUpModal>
  );
};

export default UserRole;
