import Swal from 'sweetalert2'
import './AlertStyles.css';

const escapeHtml = (str) => {
  const div = document.createElement('div');
  div.textContent = str;
  // Quotes too: the result is also placed inside attribute values.
  return div.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
};

const baseConfig = {
  confirmButtonColor: '#d33',  // Red for confirm (Delete, Discard)
  cancelButtonColor: '#3085d6',  // Blue for cancel (Keep Editing)
  toast: false,
  position: 'center',
}

/**
 * The four one-line notices, as one modal.
 *
 * They share one shape: left-aligned title, the message beside a round icon, one button
 * under a hairline rule. Only the colour changes per tone — the top border, the icon and
 * its disc, and the button — because the shape is what makes them read as one family, and
 * the colour is what tells them apart.
 */
const NOTICE_ICONS = {
  warning:
    '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line>',
  error:
    '<circle cx="12" cy="12" r="10"></circle><line x1="15" y1="9" x2="9" y2="15"></line><line x1="9" y1="9" x2="15" y2="15"></line>',
  info:
    '<circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line>',
  success:
    '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline>',
};

const notice = (tone, message, title, confirmButtonText = 'Got it') =>
  Swal.fire({
    ...baseConfig,
    title,
    html: `
      <div class="notice-modal-body">
        <div class="notice-modal-icon" aria-hidden="true">
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            ${NOTICE_ICONS[tone] || NOTICE_ICONS.info}
          </svg>
        </div>
        <div class="notice-modal-message">${escapeHtml(message)}</div>
      </div>
    `,
    customClass: {
      popup: `notice-popup tone-${tone}`,
      title: 'notice-title',
      htmlContainer: 'notice-html-container',
      confirmButton: 'notice-confirm-button',
      closeButton: 'notice-close-button',
      container: 'custom-container',
    },
    buttonsStyling: false,
    showCloseButton: true,
    showCancelButton: false,
    confirmButtonText,
    focusConfirm: true,
    allowOutsideClick: false,
  });

// All four return the dialog's promise, so `await alertService.error(...)` waits for the
// operator to dismiss it.
const success = (message, title = 'Success', confirmButtonText = 'Got it') =>
  notice('success', message, title, confirmButtonText);

const error = (message, title = 'Error', confirmButtonText = 'Got it') =>
  notice('error', message, title, confirmButtonText);

const warning = (message, title = 'Warning', confirmButtonText = 'Got it') =>
  notice('warning', message, title, confirmButtonText);

const info = (message, title = 'Information', confirmButtonText = 'Got it') =>
  notice('info', message, title, confirmButtonText);
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
      ...baseConfig,
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

/** The marks beside the lines of confirmImpact(), drawn like the notice icons above. */
const IMPACT_ICONS = {
  backup:
    '<rect x="2" y="3" width="20" height="5" rx="1"></rect><path d="M4 8v11a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8"></path><line x1="10" y1="12" x2="14" y2="12"></line>',
  replace:
    '<path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"></path><polyline points="3 3 3 8 8 8"></polyline>',
  maintenance:
    '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"></path>',
  signin:
    '<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line>',
  shield: '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path>',
  transfer:
    '<polyline points="8 3 4 7 8 11"></polyline><line x1="4" y1="7" x2="20" y2="7"></line><polyline points="16 21 20 17 16 13"></polyline><line x1="20" y1="17" x2="4" y2="17"></line>',
  upload:
    '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line>',
  warning: NOTICE_ICONS.warning,
};

// Only the marks defined above: a name like "constructor" must not reach the markup.
const impactIcon = (name) =>
  `<svg class="tw-impact-icon" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${
    Object.prototype.hasOwnProperty.call(IMPACT_ICONS, name) ? IMPACT_ICONS[name] : IMPACT_ICONS.replace
  }</svg>`;

/**
 * A confirmation that lays out what an action will do before it is agreed to.
 *
 * What it acts on, an optional choice of how, one line per consequence and a warning, in
 * place of one paragraph: each fact is a line of its own, so the one that matters to the
 * reader is found without reading the others. A line may carry `when`, the choices it is
 * true for, and is shown only while one of them is picked. Every text is escaped.
 *
 * Answers { confirmed, choice }. The choice is not a second confirmation, it only decides
 * how the confirmed action runs, so a dismissed dialog answers the default rather than
 * whatever was last highlighted.
 *
 * @param {object} args
 * @param {string} args.title
 * @param {{primary: string, secondary?: string}} [args.subject] What the action acts on.
 * @param {string} [args.choiceLabel]
 * @param {Array<{value: string, label: string, note?: string}>} [args.choices]
 * @param {string} [args.defaultChoice]
 * @param {string} [args.pointsLabel]
 * @param {Array<{icon: string, text: string, when?: string[]}>} args.points
 * @param {{title: string, text?: string}} [args.warning]
 * @param {string} [args.confirmButtonText]
 * @param {string} [args.cancelButtonText]
 */
const confirmImpact = ({
  title,
  subject = null,
  choiceLabel = '',
  choices = [],
  defaultChoice = '',
  pointsLabel = 'What will happen',
  points = [],
  warning = null,
  confirmButtonText = 'Confirm',
  cancelButtonText = 'Cancel',
}) => {
  const fallback = defaultChoice || (choices[0] && choices[0].value) || '';
  const shownFor = (when, picked) => !when.length || when.includes(picked);

  const subjectHtml =
    subject && subject.primary
      ? `<div class="tw-impact-subject">${impactIcon('backup')}<div><strong>${escapeHtml(subject.primary)}</strong>${
          subject.secondary ? `<span>${escapeHtml(subject.secondary)}</span>` : ''
        }</div></div>`
      : '';

  // One row of segments and one line under it that follows the pick: the lines below already
  // say what each choice does, so a card per choice would only make the dialog taller.
  const segments = choices
    .map(
      (o) =>
        `<label class="tw-segment"><input type="radio" name="tw-impact-choice" value="${escapeHtml(o.value)}" data-note="${escapeHtml(
          o.note || ''
        )}"${o.value === fallback ? ' checked' : ''} /><span>${escapeHtml(o.label)}</span></label>`
    )
    .join('');
  const pickedNote = (choices.find((o) => o.value === fallback) || {}).note || '';
  const choicesHtml = choices.length
    ? `${choiceLabel ? `<div class="tw-impact-label" id="tw-impact-choice-label">${escapeHtml(choiceLabel)}</div>` : ''}<div class="tw-segments" role="radiogroup"${
        choiceLabel ? ' aria-labelledby="tw-impact-choice-label"' : ''
      }>${segments}</div>${
        choices.some((o) => o.note) ? `<p class="tw-segment-note" aria-live="polite">${escapeHtml(pickedNote)}</p>` : ''
      }`
    : '';

  const pointsHtml = points
    .map((p) => {
      const when = Array.isArray(p.when) ? p.when : [];
      return `<li data-when="${escapeHtml(when.join(' '))}"${shownFor(when, fallback) ? '' : ' hidden'}>${impactIcon(p.icon)}<span>${escapeHtml(
        p.text
      )}</span></li>`;
    })
    .join('');

  const warningHtml =
    warning && warning.title
      ? `<div class="tw-impact-warning" role="note">${impactIcon('warning')}<div><strong>${escapeHtml(warning.title)}</strong>${
          warning.text ? `<span>${escapeHtml(warning.text)}</span>` : ''
        }</div></div>`
      : '';

  return Swal.fire({
    ...baseConfig,
    title,
    html: `<div class="tw-impact">${subjectHtml}${choicesHtml}<div class="tw-impact-label">${escapeHtml(
      pointsLabel
    )}</div><ul class="tw-impact-points" aria-live="polite">${pointsHtml}</ul>${warningHtml}</div>`,
    customClass: {
      popup: 'custom-popup tw-impact-popup',
      title: 'custom-title',
      confirmButton: 'custom-confirm-button',
      cancelButton: 'custom-cancel-button',
      container: 'custom-container',
      closeButton: 'custom-close-button',
    },
    buttonsStyling: false,
    showCloseButton: true,
    showCancelButton: true,
    confirmButtonText,
    cancelButtonText,
    reverseButtons: true,
    didOpen: (popup) => {
      // Another way of doing it changes what will happen, so the lines follow the pick.
      const follow = () => {
        const picked = popup.querySelector('input[name="tw-impact-choice"]:checked');
        const value = picked ? picked.value : fallback;
        popup.querySelectorAll('.tw-impact-points li').forEach((li) => {
          const when = (li.getAttribute('data-when') || '').split(' ').filter(Boolean);
          li.hidden = !shownFor(when, value);
        });
        const note = popup.querySelector('.tw-segment-note');
        if (note && picked) {
          note.textContent = picked.getAttribute('data-note') || '';
        }
      };
      popup.querySelectorAll('input[name="tw-impact-choice"]').forEach((input) => input.addEventListener('change', follow));
    },
    preConfirm: () => {
      const popup = Swal.getPopup();
      const picked = popup && popup.querySelector('input[name="tw-impact-choice"]:checked');
      return picked ? picked.value : fallback;
    },
  }).then((result) => ({
    confirmed: result.isConfirmed,
    choice: result.isConfirmed ? result.value || fallback : fallback,
  }));
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
    confirmImpact,
    optimizationPopup,
    toast,
    manageLoginPopup,
    verify,
    InfoData,
    deactivate,
    showLoading,
    closeLoading
};
