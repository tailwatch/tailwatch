import { Lock, X, Edit, Link, Unlock } from 'lucide-react';
import IconButton from '../../../Components/Buttons/IconButton';
import { Tooltip } from '../../../Components/ToolTip/Tooltip';
import { toast } from 'react-toastify';
import { CheckboxField } from '../../../Components/Fields/CheckboxField';

export const PasswordLessLoginData = ({ tempUser, onRenew, onEdit, loading, handleSelectItem, selectedItems, widget }) => {
  const handleCopyLink = () => {
    if (tempUser.login_url) {
      navigator.clipboard.writeText(tempUser.login_url)
        .then(() => {
          toast.success("Login link copied to clipboard!");
        })
        .catch((err) => {
          console.error("Failed to copy link: ", err);
          toast.error("Failed to copy login link.");
        });
    } else {
      toast.error("No login URL available.");
    }
  };
  return (
    <>
      {!widget && (<td className="!p-3">
        <div className="!flex !items-center">
          <CheckboxField 
            checked={selectedItems.includes(tempUser.user_id)} 
            onChange={() => handleSelectItem(tempUser.user_id)}
          />
        </div>
      </td>
      )}
      <td className="px-3 py-4 whitespace-nowrap text-sm text-black w-1/6">
        {tempUser.username}
      </td>
      <td className="px-3 py-4 whitespace-nowrap text-sm text-black w-1/6">
        {tempUser.role}
      </td>
      <td className="px-3 py-4 whitespace-nowrap text-sm text-black w-1/6">
        {tempUser.last_login || 'Never logged in'}
      </td>
      <td className="px-3 py-4 whitespace-nowrap text-sm text-black w-1/6">
        {tempUser.login_count}
      </td>
      <td className="px-3 py-4 whitespace-nowrap text-sm text-black w-1/6">
        {tempUser.is_expired ? 'Expired' : tempUser.expiry_time}
      </td>
      <td className="px-3 py-4 whitespace-nowrap text-sm text-black w-1/6">
        <div className="flex gap-2 items-center">
          <Tooltip message={tempUser.is_expired ? "Enable" : "Disable"} position='top' >
            <IconButton icon={tempUser.is_expired ? Lock : Unlock} onClick={() => onRenew(tempUser)} bgColor="bg-gray-200" hoverBgColor="hover:bg-gray-100" textColor={tempUser.is_expired ? "text-red-600 hover:text-red-700" : "text-green-600 hover:text-green-700"} roundedFull={true} className="p-2  transition !rounded-[5px] duration-200 hover:shadow-sm transition  duration-200 hover:shadow-sm" />
          </Tooltip>
          <Tooltip message="Edit" position='top' >
            <IconButton icon={Edit} onClick={() => onEdit(tempUser)} bgColor="bg-gray-200" hoverBgColor="hover:bg-gray-100" textColor="text-gray-600 hover:text-green-500" roundedFull={true} className="p-2  transition !rounded-[5px] duration-200 hover:shadow-sm transition  duration-200 hover:shadow-sm" />
          </Tooltip>
          {!tempUser.is_expired && (
            <Tooltip message="Copy Link" position='top' >
              <IconButton icon={Link} onClick={handleCopyLink} bgColor="bg-gray-200" hoverBgColor="hover:bg-gray-100" textColor="text-gray-600 hover:text-purple-500" roundedFull={true} className="p-2  transition !rounded-[5px] duration-200 hover:shadow-sm transition  duration-200 hover:shadow-sm" />
            </Tooltip>
          )}          
        </div>
      </td>
    </>
  );
};