import axios from "axios";
/* global wptw_ajax */

export const getAllPostTypes = async ({ setPostTypes, setLoading }) => {
      setLoading(true);
      try {
        const formData = new FormData();
        formData.append('action', 'wptw_global_ajax_handler');
        formData.append('action_type', 'wptw_get_all_post_types');
        formData.append('nonce', wptw_ajax.nonce);
        
        const response = await axios.post(wptw_ajax.ajax_url, formData, {
          headers: { 'Content-Type': 'multipart/form-data' },
        });                
        
        if (response.data.data.code === 200) {
          setPostTypes(response.data.data.data);
        } else {
          console.error('Failed to load Post Types', response.data.data.message);
        }
      } catch (error) {
        console.error('Error fetching Post Types:', error);
      } finally {
        setLoading(false);
      }
    };

export const getPostsByPostType = async (postType, page = 1, limit = 10) => {
  try {
    const formData = new FormData();
    formData.append('action', 'wptw_global_ajax_handler');
    formData.append('action_type', 'wptw_get_posts_by_post_type');
    formData.append('data', JSON.stringify({ page, limit, post_type: postType }));
    formData.append('nonce', wptw_ajax.nonce);

    const response = await axios.post(wptw_ajax.ajax_url, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
    });    

    const resData = response.data.data;
    if (resData.code === 200) {
      // Extract posts and pagination from the new structure
      return {
        posts: resData.posts || [],
        post_type: resData.post_type,
        pagination: resData.pagination || { total: 0, page: 1, limit: 10, total_pages: 1 },
        message: resData.message
      };
    } else {
      console.error('Failed to fetch posts by post type', resData.message);
      return { posts: [], post_type: postType, pagination: { total: 0, page: 1, limit: 10, total_pages: 1 }, message: resData.message };
    }
  } catch (error) {
    console.error('Error fetching posts by post type:', error);
    return { posts: [], post_type: postType, pagination: { total: 0, page: 1, limit: 10, total_pages: 1 }, message: 'Error fetching posts' };
  }
};
