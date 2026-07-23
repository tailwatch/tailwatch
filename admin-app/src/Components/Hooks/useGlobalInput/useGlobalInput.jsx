import { useState, useEffect } from 'react';
import { toast } from 'react-hot-toast';
import { useFormData } from '../../Context/FormContext';

// Client-side guards for the fallback <input type=file> path (defense-in-depth / UX only;
// the `accept` attribute is advisory and the server must re-validate regardless).
const ALLOWED_UPLOAD_TYPES = [
    'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/svg+xml',
    'video/mp4', 'video/webm',
    'audio/mpeg', 'audio/wav', 'audio/ogg',
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
const MAX_UPLOAD_BYTES = 10 * 1024 * 1024; // 10 MB

export const useGlobalInput = (id, values, onChange, type, display) => {
    const { formData } = useFormData();

    const getInitialSelectedValue = () => {
        for (const key in values) {
            if (values[key].selected) {
                return values[key];
            }
        }
        return values.option;
    };

    // On remount (tab switch back), restore from formData context if available
    const getRestoredValue = () => {
        const saved = formData[id];
        if (saved !== undefined && !saved.options) {
            return saved;
        }
        return getInitialSelectedValue() ?? { selected: false, value: "" };
    };

    const getRestoredMultipleCheckboxValues = () => {
        const saved = formData[id];
        if (saved?.options && Array.isArray(saved.options)) {
            const merged = { ...values };
            saved.options.forEach(opt => {
                if (merged[opt.option]) {
                    merged[opt.option] = { ...merged[opt.option], selected: opt.selected };
                }
            });
            return merged;
        }
        return values;
    };

    const initialValue = getRestoredValue();

    const [checked, setChecked] = useState(initialValue.selected);
    const [selectedValue, setSelectedValue] = useState(initialValue.value);
    const [multipleCheckboxValues, setMultipleCheckboxValues] = useState(getRestoredMultipleCheckboxValues);
    const [interactedOptions, setInteractedOptions] = useState(new Set());

    useEffect(() => {
        const saved = formData[id];
        if (saved !== undefined && !saved.options) {
            // Restore user's previous input from context (tab switch case)
            setChecked(saved.selected);
            setSelectedValue(saved.value);
        } else {
            const newValue = getInitialSelectedValue();
            setChecked(newValue.selected);
            setSelectedValue(newValue.value);
        }

        if (saved?.options && Array.isArray(saved.options)) {
            // Restore multiple checkbox state from context
            const merged = { ...values };
            saved.options.forEach(opt => {
                if (merged[opt.option]) {
                    merged[opt.option] = { ...merged[opt.option], selected: opt.selected };
                }
            });
            setMultipleCheckboxValues(merged);
        } else {
            setMultipleCheckboxValues(values);
        }
    }, [id, values]); // eslint-disable-line react-hooks/exhaustive-deps

    const handleCheckboxChange = (e) => {
        const newChecked = e.target.checked;
        setChecked(newChecked);
        const newValue = { selected: newChecked, value: selectedValue };
        onChange(id, newValue);
    };

    const handleInputChange = (e) => {
        const newValue = e.target.value;
        setSelectedValue(newValue);
        const newState = { selected: checked, value: newValue };
        onChange(id, newState);
    };

    const handleRadioChange = (e) => {
        const newValue = e.target.value;
        setSelectedValue(newValue);
        const newState = {
            selected: !!newValue,
            value: newValue,
        };
        onChange(id, newState);
    };

    const handleSelectChange = (e) => {
        const newValue = e.target.value;
        setSelectedValue(newValue);
        const newState = { selected: newValue, value: newValue };
        onChange(id, newState);
    };

    const handleFileChange = (e) => {
        let newValue = "";
        let fileData = null;

        if (e.target.files && e.target.files.length > 0) {
            const file = e.target.files[0];
            newValue = file.url;

            // If it's from WordPress media uploader, we have additional data
            if (file.url && file.id) {
                fileData = {
                    name: file.name,
                    url: file.url,
                    id: file.id,
                    type: file.type,
                    size: file.size
                };
            } else {
                // Regular file input fallback — enforce an explicit type/size allowlist
                // since the `accept` attribute alone is trivially bypassed.
                if (file.type && !ALLOWED_UPLOAD_TYPES.includes(file.type)) {
                    toast.error('Unsupported file type. Please choose an image, video, audio, PDF or Word document.');
                    return;
                }
                if (file.size > MAX_UPLOAD_BYTES) {
                    toast.error('File is too large. Maximum allowed size is 10 MB.');
                    return;
                }
                fileData = {
                    name: file.name, type: file.type, size: file.size, file: file
                };
            }
        }

        setSelectedValue(newValue);
        const newState = { selected: !!newValue, value: newValue };
        onChange(id, newState);
    };

    const handleMultipleCheckboxChange = (optionValue) => {
        const updatedValues = { ...multipleCheckboxValues };
        const key = Object.keys(updatedValues).find(
            k => updatedValues[k].value === optionValue
        );

        if (key) {
            updatedValues[key] = { ...updatedValues[key], selected: !updatedValues[key].selected };
            setMultipleCheckboxValues(updatedValues);
            const newInteractedOptions = new Set(interactedOptions);
            newInteractedOptions.add(key);
            setInteractedOptions(newInteractedOptions);

            const interactedOptionsList = Array.from(newInteractedOptions).map(interactedKey => ({
                option: interactedKey,
                label: updatedValues[interactedKey].label,
                selected: updatedValues[interactedKey].selected,
                value: updatedValues[interactedKey].value
            }));

            onChange(id, { id, options: interactedOptionsList });
        }
    };

    const shouldShowSubOptions = () => {
        // For checkbox, only show when checked
        if (type === "checkbox") {
            return checked;
        }
        // For toggleSwitch, always show if there are sub-options
        if (type === "toggleSwitch" || display === "flex") {
            return true;
        }
        return false;
    };

    return {
        checked,
        selectedValue,
        handleCheckboxChange,
        handleInputChange,
        handleRadioChange,
        handleSelectChange,
        handleFileChange,
        setChecked,
        shouldShowSubOptions,
        multipleCheckboxValues,
        handleMultipleCheckboxChange
    };
};
