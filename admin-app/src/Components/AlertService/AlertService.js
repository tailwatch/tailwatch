import Swal from 'sweetalert2'
import './AlertStyles.css';

const escapeHtml = (str) => {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
};

const baseConfig = {
  confirmButtonColor: '#d33',  // Red for confirm (Delete, Discard)
  cancelButtonColor: '#3085d6',  // Blue for cancel (Keep Editing)
  toast: false,
  position: 'center',
}

const success = (message, title = 'Success') => {
    Swal.fire({
      ...baseConfig,
      icon: 'success',
      title,
      text: message,
    })
}

const error = (message, title = 'Error') => {
    Swal.fire({
      ...baseConfig,
      icon: 'error',
      title,
      text: message,
    })
}

const warning = (message, title = 'Warning', confirmButtonText = 'Got it') => {
    return Swal.fire({
      ...baseConfig,
      title: title,
      html: `
        <div class="warning-modal-body">
          <div class="warning-modal-icon" aria-hidden="true">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
              <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path>
              <line x1="12" y1="9" x2="12" y2="13"></line>
              <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
          </div>
          <div class="warning-modal-message">${escapeHtml(message)}</div>
        </div>
      `,
      customClass: {
        popup: 'warning-popup',
        title: 'warning-title',
        htmlContainer: 'warning-html-container',
        confirmButton: 'warning-confirm-button',
        closeButton: 'warning-close-button',
        container: 'custom-container',
      },
      buttonsStyling: false,
      showCloseButton: true,
      showCancelButton: false,
      confirmButtonText: confirmButtonText,
      focusConfirm: true,
      allowOutsideClick: false,
    })
}

const info = (message, title = 'Information') => {
    Swal.fire({
      ...baseConfig,
      icon: 'info',
      title,
      text: message,
    })
}
const verify = (
  message,
  title = 'Delete folder?',
  confirmButtonText = 'Yes',
  cancelButtonText = 'Cancel',
) => {
  return Swal.fire({
    ...baseConfig,
    title: title,
    html: `<div class="alert-message">${escapeHtml(message)}</div>`,
    customClass: {
      popup: 'custom-popup',
      title: 'custom-title',
      confirmButton: 'custom-confirm-button',
      cancelButton: 'custom-cancel-button',
      container: 'custom-container',
      closeButton: 'custom-close-button'
    },
    buttonsStyling: false,
    showCloseButton: true,
    showCancelButton: true,
    confirmButtonText: confirmButtonText,
    cancelButtonText: cancelButtonText,
    reverseButtons: true,
  }).then((result) => result.isConfirmed);
};

const confirm = (
    message,
    title = 'Delete folder?',
    confirmButtonText = 'Delete',
    cancelButtonText = 'Cancel',
  ) => {
    return Swal.fire({
      ...baseConfig,
      title: title,
      html: `<div class="alert-message">${escapeHtml(message)}</div>`,
      customClass: {
        popup: 'custom-popup',
        title: 'custom-title',
        confirmButton: 'custom-confirm-button',
        cancelButton: 'custom-cancel-button',
        container: 'custom-container',
        closeButton: 'custom-close-button'
      },
      buttonsStyling: false,
      showCloseButton: true,
      showCancelButton: true,
      confirmButtonText: confirmButtonText,
      cancelButtonText: cancelButtonText,
      reverseButtons: true,
    }).then((result) => result.isConfirmed);
  };

  const optimizationPopup = (
    message,
    title = 'Enable Database Optimization?',
    confirmButtonText = 'Backup with Database Optimization',
    cancelButtonText = 'Skip'
  ) => {
    return Swal.fire({
      ...baseConfig, // Assuming baseConfig is defined elsewhere in your code
      title: title,
      html: `<div class="alert-message">${escapeHtml(message)}</div>`,
      customClass: {
        popup: 'optimize-popup',
        title: 'optimize-title',        
        cancelButton: 'optimize-cancel-button',
        confirmButton: 'optimize-confirm-button',
        container: 'custom-container',
      },
      buttonsStyling: false, 
      showCloseButton: false,
      showCancelButton: true,
      confirmButtonText: confirmButtonText,
      cancelButtonText: cancelButtonText,
      reverseButtons: true, 
      allowOutsideClick: false,
      allowEscapeKey: false, 
    }).then((result) => result.isConfirmed);
  };

const toast = (message, icon = 'success') => {
    Swal.fire({
      ...baseConfig,
      icon,
      title: message,
      toast: true,
      position: 'top-end',
      showConfirmButton: false,
      timer: 3000,
      timerProgressBar: true,
    })
}
const InfoData = (
    message,
    title = 'Reset?',
    confirmButtonText = 'Yess',
    cancelButtonText = 'Cancel',
  ) => {
    return Swal.fire({
      ...baseConfig,
      title: title,
      html: `<div class="alert-message">${escapeHtml(message)}</div>`,
      customClass: {
        popup: 'custom-popup',
        title: 'custom-title',
        confirmButton: 'custom-confirm-button',
        cancelButton: 'custom-cancel-button',
        container: 'custom-container',
        closeButton: 'custom-close-button'
      },
      buttonsStyling: false,
      showCloseButton: true,
      showCancelButton: true,
      confirmButtonText: confirmButtonText,
      cancelButtonText: cancelButtonText,
      reverseButtons: true,
    }).then((result) => result.isConfirmed);
  };

const manageLoginPopup = (
  message,
  title = 'Manage User Status',
  confirmButtonText = 'OK',
  cancelButtonText = 'Cancel',
  showExpiry = true,
  expiryOptions = [
    { value: "hour", label: "1 Hour" },
    { value: "3_hours", label: "3 Hours" },
    { value: "day", label: "Day" },
    { value: "3_days", label: "3 Days" },
    { value: "week", label: "Week" },
    { value: "month", label: "Month" },
  ]
) => {
  const expiryHtml = showExpiry
    ? `
      <div class="mt-4">
        <label for="expiry_time" class="block text-sm font-medium text-gray-700">Set Expiry Time</label>
        <select id="expiry_time" class="w-full border rounded-lg p-2 mt-1 bg-white" name="expiry_time">
          ${expiryOptions.map(option => `<option value="${option.value}">${option.label}</option>`).join('')}
        </select>
      </div>
    `
    : '';

  const resetLoginHtml = showExpiry
    ? `
      <div class="mt-4 flex items-center gap-2">
        <input
          type="checkbox"
          id="reset_login_count"
          name="reset_login_count"
          checked
          class="w-4 h-4 cursor-pointer accent-[#007980]"
        />
        <label for="reset_login_count" class="text-sm text-gray-700 cursor-pointer">
          Do you want to reset the login count?
        </label>
      </div>
    `
    : '';

  // Escape the whole message (keeps a user-controlled username safe), then allow
  // only the intended <strong> tags back, so the username renders bold without
  // opening an XSS hole. escapeHtml turns <strong> into &lt;strong&gt;.
  const messageHtml = escapeHtml(message)
    .replace(/&lt;strong&gt;/g, '<strong>')
    .replace(/&lt;\/strong&gt;/g, '</strong>');

  return Swal.fire({
    ...baseConfig,
    title: title,
    html: `
      <div class="alert-message">${messageHtml}</div>
      ${expiryHtml}
      ${resetLoginHtml}
    `,
    customClass: {
      popup: 'manageLogin-popup',
      title: 'optimize-title',
      cancelButton: 'optimize-cancel-button',
      confirmButton: 'optimize-confirm-button',
      container: 'custom-container',
    },
    buttonsStyling: false,
    showCloseButton: false,
    showCancelButton: true,
    confirmButtonText: confirmButtonText,
    cancelButtonText: cancelButtonText,
    reverseButtons: true,
    allowOutsideClick: false,
    allowEscapeKey: false,
    preConfirm: () => {
      if (showExpiry) {
        const resetLoginCount = document.getElementById('reset_login_count').checked;
        const expiryTime = document.getElementById('expiry_time').value;
        return { expiry_time: expiryTime, reset_login_count: resetLoginCount };
      }
      return { reset_login_count: false };
    },
  }).then((result) => {
    if (result.isConfirmed) {
      return result.value;  // { expiry_time?, reset_login_count }
    }
    return false;
  });
};

const deactivate = (
  message,
  title = 'Deactivate Plugin?',
  confirmButtonText = 'Deactivate',
  cancelButtonText = 'Cancel',
) => {
  return Swal.fire({
    ...baseConfig,
    title: title,
    html: `<div class="alert-message">${escapeHtml(message)}</div>`,
    customClass: {
      popup: 'custom-popup',
      title: 'custom-title',
      confirmButton: 'custom-confirm-button',
      cancelButton: 'custom-cancel-button',
      container: 'custom-container',
      closeButton: 'custom-close-button'
    },
    buttonsStyling: false,
    showCloseButton: true,
    showCancelButton: true,
    confirmButtonText: confirmButtonText,
    cancelButtonText: cancelButtonText,
    reverseButtons: true,
  }).then((result) => result.isConfirmed);
};

const showLoading = (title = 'Processing...', text = 'Please wait') => {
  Swal.fire({
    title,    
    text,
    customClass:{
      popup: 'custom-popup',
      title: 'custom-title',
      confirmButton: 'custom-confirm-button',
      cancelButton: 'custom-cancel-button',
      container: 'custom-container',
      closeButton: 'custom-close-button'
    },
    allowOutsideClick: false,
    allowEscapeKey: false,
    showConfirmButton: false,
    didOpen: () => {
      Swal.showLoading();
    }
  });
};

const closeLoading = () => {
  Swal.close();
};

export const alertService = {
    success,
    error,
    warning,
    info,
    confirm,
    optimizationPopup,
    toast,
    manageLoginPopup,
    verify,
    InfoData,
    deactivate,
    showLoading,
    closeLoading
};
