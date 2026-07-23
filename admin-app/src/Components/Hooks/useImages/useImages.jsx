
/* global wptw_ajax */

const getImagePath = (imageName) => {
    if (process.env.NODE_ENV === "development" && window.wptw_ajax?.images) {
        return `${wptw_ajax.images}${imageName}`;
    }
    return `${wptw_ajax.asset_url}${imageName}`;
};

// Export individual images
export const tailwatchLogo = getImagePath('tailwatchLogo.png');
export const dbImage1 = getImagePath('all_transients.svg');
export const dbImage2 = getImagePath('auto_draft.svg');
export const dbImage3 = getImagePath('expired_transient.svg');
export const dbImage4 = getImagePath('revision.svg');
export const dbImage5 = getImagePath('spam_comment.svg');
export const dbImage6 = getImagePath('trackback-pingback.svg');
export const dbImage7 = getImagePath('trash_comment.svg');
export const dbImage8 = getImagePath('trash_post.svg');
export const logo = getImagePath('foxbranding.png');
export const tailwatch = getImagePath('tailWatch.png');