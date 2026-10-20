// Markup-safe rendering for stored text.
//
// Log lines, email bodies and plugin descriptions can carry text a visitor or a third
// party wrote. DOMPurify's defaults already drop scripts, but still keep <style>, forms,
// inputs, remote images and class names, which is enough to restyle the admin page (the
// app's own utility classes included), draw a fake form or reveal the admin's address to
// another server. Only readable markup is kept.

import DOMPurify from 'dompurify';

const OPTIONS = {
  FORBID_TAGS: [
    'style', 'form', 'input', 'button', 'textarea', 'select', 'option', 'img', 'iframe', 'link', 'meta',
    'svg', 'math', 'video', 'audio', 'source', 'picture', 'object', 'embed',
  ],
  FORBID_ATTR: ['style', 'class', 'id', 'poster', 'background', 'srcset'],
};

/**
 * Sanitized markup, safe to pass to dangerouslySetInnerHTML.
 * @param {string} html - stored text that may contain markup
 * @returns {string}
 */
export const safeHtml = (html) => DOMPurify.sanitize(typeof html === 'string' ? html : '', OPTIONS);

/**
 * The text of markup that came from elsewhere, such as a server error page: every tag is
 * removed, along with what a script or style element held, and the rest stays escaped, so
 * nothing in it reads as a tag however the original tags were nested or split.
 * @param {string} html - markup from elsewhere
 * @returns {string}
 */
export const plainText = (html) =>
  DOMPurify.sanitize(typeof html === 'string' ? html : '', { ALLOWED_TAGS: [], ALLOWED_ATTR: [] });
