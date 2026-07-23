import React, { useState, useEffect, useCallback, useRef } from 'react';
import { useForm } from 'react-hook-form';
import { useRedirectionFeature } from "../Features/UseFeatures";
import { getUserRoles, createRedirection, updateRedirectRule, getAllRedirectRules, handleDelete, } from '../../../Pages/Redirection/RedirectionServices/RedirectionServices';
import { alertService } from '../../AlertService/AlertService';

export const useRedirection = (setIsUpdating) => {
    const { enableRedirectionRule } = useRedirectionFeature();
    const [showModal, setShowModal] = useState(false);
    const [showAdvancedOptions, setShowAdvancedOptions] = useState(false);
    const [showInclusionRules, setShowInclusionRules] = useState(false);
    const [userRoles, setUserRoles] = useState({});
    const [isLoading, setLoading] = useState(true);
    const [selectedRoles, setSelectedRoles] = useState([]);
    const [editingRule, setEditingRule] = useState(null);
    const [redirectRules, setRedirectRules] = useState([]);
    const [isLoadingRules, setIsLoadingRules] = useState(true);
    const [selectedRules, setSelectedRules] = useState([]);
    const [allSelected, setAllSelected] = useState(false);
    const [sourceUrl, setSourceUrl] = useState(null);
    const [isDeleting, setIsDeleting] = useState(false);
    const [searchTerm, setSearchTerm] = useState('');
    const [isEditMode, setIsEditMode] = useState(false);
    const [selectedValues, setSelectedValues] = useState({ postType: '', postId: '', customUrl: '', postData: null });
    const [preselectedValues, setPreselectedValues] = useState({ postType: '', postId: '', customUrl: '' });
    const [featureEnable, setFeatureEnable] = useState(null);
    const [parentEnable, setParentEnable] = useState(null);
    const [pagination, setPagination] = useState({ page: 0, limit: 10, total_pages: 1, total_items: 0 });
    
    // Use refs to track previous values and prevent unnecessary effects
    const prevEditingRuleRef = useRef(null);
    const prevShowModalRef = useRef(false);
    const prevSourceUrlRef = useRef(null);
    const isInitializedRef = useRef(false);

    const defaultConditions = [
        { type: 'login_status', enabled: false, value: 'logged_in' },
        { type: 'user_role', enabled: false, operator: 'has', value: [] },
        { type: 'referrer', enabled: false, value: '' },
        { type: 'ip_addresses', enabled: false, value: [] }
    ];

    const { control, handleSubmit, reset, setValue, watch, formState: { errors } } = useForm({
        defaultValues: {
            sourceUrl: '', 
            customRedirectUrl: '', 
            selectedPost: '', 
            statusCode: '301', 
            matchType: 'exact', 
            ignoreTrailingSlashes: true, 
            ignoreCase: true, 
            ignoreParameters: true, 
            isPermalinkChange: false, 
            conditionLogic: 'AND',
            conditions: defaultConditions
        }
    });

    // Watch form values to use in the component
    const formValues = watch();

    const handleSelectionChange = useCallback((values) => {
        setSelectedValues(values);
    }, []);

    const fetchRedirectRules = useCallback(async (page = pagination.page, limit = pagination.limit) => {
        setIsLoadingRules(true);
        try {
            await getAllRedirectRules({
                setRedirectRules, 
                setFeatureEnable, 
                setParentEnable, 
                page: page + 1, 
                limit,
                setPagination: (paginationData) => {
                    setPagination({ 
                        page: paginationData.page - 1, 
                        limit: paginationData.limit, 
                        total_pages: paginationData.total_pages,
                        total_items: paginationData.total_items || 0  
                    });
                }
            });
        } catch (error) {
            console.error("Error fetching redirect rules:", error);
        } finally {
            setIsLoadingRules(false);
        }
    }, []); // Remove pagination dependency to prevent infinite loops

    const loadModalData = useCallback(async () => {
        setLoading(true);
        try {
            await getUserRoles({ setUserRoles, setLoading: () => { } });
        } catch (error) {
            console.error("Error loading modal data:", error);
        } finally {
            setLoading(false);
        }
    }, []);

    const resetForm = useCallback(() => {
        reset({ 
            sourceUrl: '', 
            customRedirectUrl: '', 
            selectedPost: '', 
            statusCode: '301', 
            matchType: 'exact', 
            ignoreTrailingSlashes: true, 
            ignoreCase: true, 
            ignoreParameters: true, 
            isPermalinkChange: false, 
            conditionLogic: 'AND', 
            conditions: [...defaultConditions] // Create new array to prevent reference issues
        });
        setSelectedRoles([]);
        setShowInclusionRules(false);
        setShowAdvancedOptions(false);
    }, [reset, defaultConditions]);

    // Separate effect for modal initialization - only runs when modal opens/closes
    useEffect(() => {
        if (showModal && !prevShowModalRef.current) {
            // Modal just opened
            loadModalData();
            isInitializedRef.current = false;
        } else if (!showModal && prevShowModalRef.current) {
            // Modal just closed - reset everything
            resetForm();
            setPreselectedValues({ postType: '', postId: '', customUrl: '' });
            setEditingRule(null);
            setSourceUrl(null);
            isInitializedRef.current = false;
        }
        
        prevShowModalRef.current = showModal;
    }, [showModal, loadModalData, resetForm]);

    // Separate effect for handling editing rule changes
    useEffect(() => {
        if (showModal && editingRule && editingRule !== prevEditingRuleRef.current && !isInitializedRef.current) {
            setShowAdvancedOptions(true);

            try {
                const parsedValue = JSON.parse(editingRule.value);

                // Extract path from source_url
                /* global wptw_ajax */
                let pathOnly = parsedValue.source_url;

                // If it's a full URL, extract the path after base_url
                if (typeof wptw_ajax !== 'undefined' && wptw_ajax.base_url) {
                    if (pathOnly.startsWith(wptw_ajax.base_url)) {
                        pathOnly = pathOnly.substring(wptw_ajax.base_url.length);
                    }
                }

                // Remove leading slash if present
                if (pathOnly.startsWith('/')) {
                    pathOnly = pathOnly.substring(1);
                }

                setValue('sourceUrl', pathOnly);
                setPreselectedValues({
                    postType: parsedValue.post_type,
                    postId: parsedValue.post_id,
                    customUrl: parsedValue.target_url
                });
                setValue('statusCode', parsedValue.status_code.toString());
                setValue('matchType', parsedValue.match_type);
                setValue('ignoreCase', parsedValue.case_sensitive);
                setValue('ignoreTrailingSlashes', parsedValue.ignore_trailing_slashes);
                setValue('ignoreParameters', parsedValue.ignore_parameters);
                setValue('isPermalinkChange', parsedValue.is_permalink_change);
                setValue('customRedirectUrl', parsedValue.target_url);

                setShowInclusionRules(!!parsedValue.apply_conditions);

                const parsedConditions = parsedValue.conditions ? JSON.parse(parsedValue.conditions) : [];
                if (parsedConditions.length > 0) {
                    setValue('conditionLogic', parsedValue.condition_logic || 'AND');

                    const updatedConditions = defaultConditions.map(defaultCondition => {
                        const matchingCondition = parsedConditions.find(c => c.type === defaultCondition.type);
                        if (matchingCondition) {
                            return { ...defaultCondition, ...matchingCondition, enabled: true };
                        }
                        return { ...defaultCondition };
                    });
                    setValue('conditions', updatedConditions);

                    const userRoleCondition = updatedConditions.find(c => c.type === 'user_role');
                    if (userRoleCondition && userRoleCondition.enabled) {
                        setSelectedRoles(userRoleCondition.value);
                    } else {
                        setSelectedRoles([]);
                    }
                } else {
                    setValue('conditions', [...defaultConditions]);
                    setSelectedRoles([]);
                }
                
                isInitializedRef.current = true;
            } catch (error) {
                console.error("Error parsing editing rule:", error);
                resetForm();
            }
        }
        
        prevEditingRuleRef.current = editingRule;
    }, [showModal, editingRule, setValue, defaultConditions, resetForm]);

    // Separate effect for handling source URL changes
    useEffect(() => {
        if (showModal && sourceUrl && sourceUrl !== prevSourceUrlRef.current && !editingRule && !isInitializedRef.current) {
            try {
                /* global wptw_ajax */
                let urlToSet = '';

                if (sourceUrl.value) {
                    const parsed = JSON.parse(sourceUrl.value);
                    if (parsed && parsed.url) {
                        urlToSet = parsed.url;
                    }
                } else if (sourceUrl.Url) {
                    urlToSet = sourceUrl.Url;
                }

                // Extract path from the URL
                if (urlToSet) {
                    // If it's a full URL, extract the path after base_url
                    if (typeof wptw_ajax !== 'undefined' && wptw_ajax.base_url) {
                        if (urlToSet.startsWith(wptw_ajax.base_url)) {
                            urlToSet = urlToSet.substring(wptw_ajax.base_url.length);
                        }
                    }

                    // Remove leading slash if present
                    if (urlToSet.startsWith('/')) {
                        urlToSet = urlToSet.substring(1);
                    }

                    setValue('sourceUrl', urlToSet);
                }
                isInitializedRef.current = true;
            } catch (error) {
                console.error("Error parsing source URL:", error);
            }
        }

        prevSourceUrlRef.current = sourceUrl;
    }, [showModal, sourceUrl, editingRule, setValue]);

    const handleRoleSelection = useCallback((role) => {
        const newConditions = [...formValues.conditions];
        const userRoleCondition = { ...newConditions[1] }; // Create new object
        
        if (userRoleCondition.value.includes(role)) {
            userRoleCondition.value = userRoleCondition.value.filter(r => r !== role);
        } else {
            userRoleCondition.value = [...userRoleCondition.value, role];
        }
        
        newConditions[1] = userRoleCondition;
        setValue('conditions', newConditions);
        setSelectedRoles(userRoleCondition.value);
    }, [formValues.conditions, setValue]);

    const handlePostChange = useCallback((e) => {
        const postUrl = e.target.value;
        setValue('selectedPost', postUrl);
    }, [setValue]);

    const buildPayload = useCallback((data) => {
        // Format source URL to always start with /
        let formattedSourceUrl = data.sourceUrl;
        if (formattedSourceUrl && !formattedSourceUrl.startsWith('/')) {
            formattedSourceUrl = '/' + formattedSourceUrl;
        }

        const payload = {
            source_url: formattedSourceUrl,
            target_url: selectedValues.isCustom ? selectedValues.customUrl : selectedValues.postId,
            ...(selectedValues.postType !== 'custom' && {
                post_type: selectedValues.postType,
            }),
            status_code: parseInt(data.statusCode),
            match_type: data.matchType,
            case_sensitive: data.ignoreCase,
            ignore_trailing_slashes: data.ignoreTrailingSlashes,
            ignore_parameters: data.ignoreParameters,
            is_permalink_change: data.isPermalinkChange,
            apply_conditions: showInclusionRules,
            enabled: true
        };

        if (showInclusionRules) {
            payload.condition_logic = data.conditionLogic;
            payload.conditions = data.conditions
                .filter(condition => condition.enabled)
                .map(condition => ({
                    enabled: condition.enabled,
                    type: condition.type,
                    ...condition
                }));
        }

        return payload;
    }, [selectedValues, showInclusionRules]);

    const onSubmit = useCallback((data) => {
        const payload = buildPayload(data);
        if (editingRule) {
            updateRedirectRule({ id: editingRule.id, payload, setShowModal, setLoading }, setIsUpdating, fetchRedirectRules);
        } else {
            createRedirection({ setShowModal, setLoading, payload, fetchRedirectRules });
        }
    }, [buildPayload, editingRule, setIsUpdating, fetchRedirectRules]);

    const showRedirectModal = useCallback(() => {
        resetForm();
        setIsEditMode(false);
        setEditingRule(null);
        setSourceUrl(null);
        setPreselectedValues({ postType: '', postId: '', customUrl: '' });
        setShowModal(true);
    }, [resetForm]);

    const handleSelectAll = useCallback(() => {
        if (allSelected) {
            setSelectedRules([]); 
        } else {
            setSelectedRules(redirectRules.map(rule => rule.id.toString()));            
        }
        setAllSelected(!allSelected);
    }, [allSelected, redirectRules]);

    const handleRuleSelect = useCallback((id) => {
        setSelectedRules(prev => prev.includes(id) ? prev.filter(ruleId => ruleId !== id) : [...prev, id]);
    }, []);

    const handleDeletAll = useCallback(async () => {
        const confirmed = await alertService.confirm("This can't be undone. it removes all data from database", "Are you sure to delete all this data?");
        if (!confirmed) {
            return;
        }
        setIsDeleting(true);
        try {
            const deleteData = { ids: [], is_delete: true };
            await handleDelete(deleteData, setIsDeleting, fetchRedirectRules);
            setSelectedRules([]);
        } catch (error) {
            console.error('Error deleting items:', error);
        } finally {
            setIsDeleting(false);
        }
    }, [fetchRedirectRules]);

    const handleBulkDelete = useCallback(async () => {
        const confirmed = await alertService.confirm("This can't be undone.", "Are you sure to delete ?");
        if (!confirmed) {
            return;
        }
        const deleteData = { ids: selectedRules, is_delete: false };
        await handleDelete(deleteData, setIsDeleting, fetchRedirectRules);
        setSelectedRules([]);
    }, [selectedRules, fetchRedirectRules]);

    const handleToggle = useCallback(async (id, enabled) => {
        const updateData = { id: id.toString(), enabled };
        await updateRedirectRule(updateData, setIsUpdating, fetchRedirectRules);
    }, [setIsUpdating, fetchRedirectRules]);

    const filteredRules = redirectRules.filter(rule => {
        try {
            const parsedValue = JSON.parse(rule.value);
            return (
                parsedValue.source_url.toLowerCase().includes(searchTerm.toLowerCase()) ||
                parsedValue.status_code.toString().toLowerCase().includes(searchTerm.toLowerCase())
            );
        } catch (error) {
            return false;
        }
    });

    const handleEdit = useCallback((rule) => {
        setEditingRule(rule);
        setSourceUrl(null); // Clear source URL when editing
        setShowModal(true);
    }, []);

    const handleGetSourceUrl = useCallback((url) => {
        setSourceUrl(url);
        setEditingRule(null); // Clear editing rule when setting source URL
        setShowModal(true);
    }, []);

    const handleCloseModal = useCallback(() => {
        setShowModal(false);
        // The cleanup will be handled by the modal effect
    }, []);

    const handlePageChange = useCallback((newPage) => {
        setPagination(prev => ({ ...prev, page: newPage }));
        fetchRedirectRules(newPage, pagination.limit);
        setSelectedRules([]);
    }, [fetchRedirectRules, pagination.limit]);

    const handlePageSizeChange = useCallback((newPageSize) => {
        setPagination(prev => ({ ...prev, limit: newPageSize, page: 0 }));
        fetchRedirectRules(0, newPageSize);        
        setSelectedRules([]);
    }, [fetchRedirectRules]);

    return { showAdvancedOptions, userRoles, isLoading, selectedRoles, control,  handlePostChange, handleSubmit, onSubmit, handleRoleSelection, loadModalData, showRedirectModal, setLoading, fetchRedirectRules, redirectRules, isEditMode, setIsEditMode, preselectedValues, isLoadingRules,  selectedRules,  searchTerm, handleSelectionChange, handleCloseModal, featureEnable, parentEnable, setFeatureEnable, handleSelectAll, allSelected, setParentEnable,  handlePageChange,  pagination,  setPagination,
        handlePageSizeChange, isDeleting, setSearchTerm, handleEdit, filteredRules, handleToggle, handleBulkDelete, handleRuleSelect, handleDeletAll,errors, showModal, setShowModal, formValues, setValue, setShowAdvancedOptions, showInclusionRules, setShowInclusionRules, setEditingRule, handleGetSourceUrl
    };
};