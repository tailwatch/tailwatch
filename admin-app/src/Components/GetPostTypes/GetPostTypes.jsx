import React, { useEffect, useState, useRef } from 'react'
import { getPostsByPostType, getAllPostTypes } from './PostTypesServices';
import { ChevronDown, Loader2, Search } from 'lucide-react';
import { safeUrl } from '../Utils/HelperFunctions/urlSafety';

// A custom redirect target is valid only when it is an http(s) absolute URL or a
// site-relative path. Anything else (javascript:, data:, vbscript:, …) is rejected so a
// dangerous scheme can't be stored as a redirect destination.
const isValidRedirectTarget = (value) => {
  if (!value) return true; // empty handled by the required/submit flow, not here
  return safeUrl(value, '') !== '';
};

export const GetPostTypes = ({ onSelectionChange, initialPostId = '', initialPostType = '', initialCustomUrl = '' }) => {
  const [postTypes, setPostTypes] = useState([]);
  const [loading, setLoading] = useState(true);
  const [selectedPostType, setSelectedPostType] = useState(initialPostType);
  const [customValue, setCustomValue] = useState(initialCustomUrl);
  const [posts, setPosts] = useState([]);
  const [postsLoading, setPostsLoading] = useState(false);
  const [selectedPost, setSelectedPost] = useState(initialPostId);
  const [page, setPage] = useState(1);
  const [hasMorePosts, setHasMorePosts] = useState(true);
  const [isPostTypeOpen, setIsPostTypeOpen] = useState(false);
  const [isPostOpen, setIsPostOpen] = useState(false);
  const [searchTerm, setSearchTerm] = useState('');
  const [initialLoadComplete, setInitialLoadComplete] = useState(false);
  const postTypeRef = useRef(null);
  const postSelectRef = useRef(null);

  useEffect(() => {
    function handleClickOutside(event) {
      if (postTypeRef.current && !postTypeRef.current.contains(event.target)) {
        setIsPostTypeOpen(false);
      }
      if (postSelectRef.current && !postSelectRef.current.contains(event.target)) {
        setIsPostOpen(false);
      }
    }
    document.addEventListener('mousedown', handleClickOutside);
    return () => {
      document.removeEventListener('mousedown', handleClickOutside);
    };
  }, []);

  useEffect(() => {
    // If initialPostId is '0', select custom post type
    if (initialPostId === '0' || initialPostId === 0) {
      setSelectedPostType('custom');
      setSelectedPost('0');
    }
  }, [initialPostId]);

  useEffect(() => {
    getAllPostTypes({ setPostTypes, setLoading });
  }, []);

  useEffect(() => {
    if (selectedPostType && selectedPostType !== 'custom') {
      loadPosts();
    } else {
      setPosts([]);
      setPage(1);
      setHasMorePosts(true);
    }
  }, [selectedPostType]);

  useEffect(() => {
    if (initialPostId && initialPostId !== '0' && initialPostId !== 0 && selectedPostType && posts.length > 0 && !initialLoadComplete) {
      // Check if the initial post ID exists in the current posts
      const postExists = posts.some(post => post.id === initialPostId);

      if (postExists) {
        setSelectedPost(initialPostId);
        setInitialLoadComplete(true);
      } else if (!postsLoading && hasMorePosts) {
        // If not found in current page, try loading more posts
        loadPosts(page + 1);
      }
    }
  }, [initialPostId, selectedPostType, posts, postsLoading, hasMorePosts, page, initialLoadComplete]);

  useEffect(() => {
    if (onSelectionChange) {
      let selectedPostData = null;
      if (selectedPost && selectedPost !== '0' && selectedPost !== 0) {
        selectedPostData = posts.find(post => post.id === selectedPost);
      }
      onSelectionChange({
        postType: selectedPostType,
        postId: selectedPost,
        customUrl: customValue,
        postData: selectedPostData,
        isCustom: selectedPost === '0' || selectedPost === 0
      });
    }
  }, [selectedPostType, selectedPost, customValue, posts, onSelectionChange]);

  const loadPosts = async (newPage = 1) => {
    if (!selectedPostType || selectedPostType === 'custom') return;

    setPostsLoading(true);
    try {
      const response = await getPostsByPostType(selectedPostType, newPage);
      const postsData = response.posts || [];

      if (newPage === 1) {
        setPosts(postsData);
      } else {
        setPosts(prevPosts => [...prevPosts, ...postsData]);
      }
      // Use pagination info from response
      setHasMorePosts(
        response.pagination && (response.pagination.page < response.pagination.total_pages)
      );
      setPage(newPage);
    } catch (error) {
      console.error('Error loading posts:', error);
    } finally {
      setPostsLoading(false);
    }
  };

  useEffect(() => {
    // Update state when props change
    if (initialPostType !== selectedPostType && !(initialPostId === '0' || initialPostId === 0)) {
      setSelectedPostType(initialPostType);
    }

    if (initialCustomUrl !== customValue) {
      setCustomValue(initialCustomUrl);
    }

    // Reset initial load flag when post type changes from props
    if (initialPostType !== selectedPostType) {
      setInitialLoadComplete(false);
    }
  }, [initialPostType, initialPostId, initialCustomUrl]);

  const handlePostTypeChange = (value) => {
    setSelectedPostType(value);
    // If changing to custom, set postId to 0
    if (value === 'custom') {
      setSelectedPost('0');
    } else {
      setSelectedPost('');
    }
    setPage(1);
    setHasMorePosts(true);
    setIsPostTypeOpen(false);
  };

  const handlePostChange = (value) => {
    setSelectedPost(value);
    setIsPostOpen(false);
  };

  const handleScroll = (e) => {
    const { scrollTop, scrollHeight, clientHeight } = e.target;
    if (scrollHeight - scrollTop <= clientHeight * 1.5) {
      if (!postsLoading && hasMorePosts) {
        loadPosts(page + 1);
      }
    }
  };

  const filteredPosts = posts.filter(post =>
    post.title.toLowerCase().includes(searchTerm.toLowerCase())
  );

  const getSelectedTypeLabel = () => {
    if (!selectedPostType) return '-- Select Post Type --';
    if (selectedPostType === 'custom') return 'Custom';

    const foundType = postTypes.find(type => type.post_type === selectedPostType);
    return foundType ? foundType.label : selectedPostType;
  };

  const getSelectedPostLabel = () => {
    if (!selectedPost) return '-- Select Post --';

    const foundPost = posts.find(post => post.id === selectedPost);
    return foundPost ? foundPost.title : selectedPost;
  };

  // Determine if we're in custom mode (either selectedPostType is 'custom' or postId is '0')
  const isCustomMode = selectedPostType === 'custom' || selectedPost === '0' || selectedPost === 0;

  return (
    <div className="w-full">
      <div className="space-y-2">
        <div className="relative" ref={postTypeRef}>
          <button id="postTypeSelect" type="button" onClick={() => setIsPostTypeOpen(!isPostTypeOpen)} className="w-full flex items-center justify-between px-3 py-2 bg-white border border-gray-300 rounded-lg shadow-sm hover:border-[#007980] focus:outline-none focus:ring-1 focus:ring-[#007980]" disabled={loading} >
            <span className="block truncate">
              {loading ? 'loading...' : getSelectedTypeLabel()}
            </span>
            <ChevronDown className={`w-5 h-5 text-gray-400 transition-transform duration-200 ${isPostTypeOpen ? 'rotate-180' : ''}`} />
          </button>

          {isPostTypeOpen && (
            <div className="absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg">
              <ul className="py-1 max-h-60 overflow-auto">
                <li className="px-3 py-2 cursor-pointer hover:bg-[#07C07E1A] text-gray-500" onClick={() => handlePostTypeChange('')} >
                  -- Select Post Type --
                </li>
                {postTypes.map((type) => (
                  <li key={type.post_type} onClick={() => handlePostTypeChange(type.post_type)} className={`px-3 py-2 cursor-pointer hover:bg-[#07C07E1A] ${selectedPostType === type.post_type ? 'bg-[#07C07E1A] text-[#007980]' : 'text-gray-800'}`} >
                    {type.label}
                  </li>
                ))}
                <li onClick={() => handlePostTypeChange('custom')} className={`px-3 py-2 cursor-pointer hover:bg-[#07C07E1A] ${selectedPostType === 'custom' ? 'bg-[#07C07E1A] text-[#007980]' : 'text-gray-800'}`} >
                  Custom
                </li>
              </ul>
            </div>
          )}
        </div>
      </div>
      {isCustomMode && (
        <div className="space-y-2 mt-2">
          <input type="text" id="customInput" value={customValue} onChange={(e) => setCustomValue(e.target.value)} className={`w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 ${isValidRedirectTarget(customValue) ? 'border-gray-300 focus:ring-[#85cbcf]' : 'border-red-400 focus:ring-red-300'}`} placeholder="Enter custom URL" />
          {!isValidRedirectTarget(customValue) && (
            <p className="text-xs text-red-500">Enter a valid http(s) URL or a site-relative path starting with “/”.</p>
          )}
        </div>
      )}

      {/* Post Selection - Only show if not in custom mode */}
      {selectedPostType && !isCustomMode && (
        <div className="space-y-2 mt-2">
          <div className="relative" ref={postSelectRef}>
            <button id="postSelect" type="button" onClick={() => setIsPostOpen(!isPostOpen)} className="w-full flex items-center justify-between px-3 py-2 bg-white border border-gray-300 rounded-lg shadow-sm hover:border-[#85cbcf] focus:outline-none focus:ring-2 focus:ring-[#85cbcf]" disabled={postsLoading && posts.length === 0} >
              <span className="block truncate">
                {postsLoading ? 'loading....' : getSelectedPostLabel()}
              </span>
              <ChevronDown className={`w-5 h-5 text-gray-400 transition-transform duration-200 ${isPostOpen ? 'rotate-180' : ''}`} />
            </button>
            {isPostOpen && (
              <div className="absolute z-10 w-full mt-1 bg-white border border-gray-300 rounded-lg shadow-lg">
                <ul className="py-1 max-h-60 overflow-auto" onScroll={handleScroll} >
                  <li className="px-3 py-2 cursor-pointer hover:bg-[#07C07E1A] text-gray-500" onClick={() => handlePostChange('')} >
                    -- Select Post --
                  </li>
                  {filteredPosts.map((post) => (
                    <li key={post.id} onClick={() => handlePostChange(post.id)} className={`px-3 py-2 cursor-pointer hover:bg-[#07C07E1A] ${selectedPost === post.id ? 'bg-[#07C07E1A] text-[#007980]' : 'text-gray-800'}`} >
                      {post.title}
                    </li>
                  ))}
                  {filteredPosts.length === 0 && !postsLoading && (
                    <li className="px-3 py-2 text-gray-500">No posts found</li>
                  )}
                  {postsLoading && page >= 1 && (
                    <li className="px-3 py-2 text-center text-gray-500">
                      <Loader2 className="w-4 h-4 mx-auto animate-spin" />
                      load more....
                    </li>
                  )}
                </ul>
              </div>
            )}
          </div>
        </div>
      )}
    </div>
  );
};