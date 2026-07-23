// useRadioHandlers.js
import { useState, useEffect, useRef } from 'react';

export const useRadioHandlers = (selectedValue, values, display) => {
  // Check if values have icons to determine display mode
  const hasIcons = Object.values(values).some(value => value.icon);

  // State to keep track of the ordered keys (useful for tab view and icon view)
  const [orderedKeys, setOrderedKeys] = useState(Object.keys(values));
  // Refs to track element positions
  const tabRefs = useRef({});
  const containerRef = useRef(null);
  const [animating, setAnimating] = useState(false);
  // Store the previous selected value to detect changes
  const prevSelectedValueRef = useRef(selectedValue);
  
  // When component mounts or values change, initialize the ordered keys
  useEffect(() => {
    if ((display === 'tab_view' || hasIcons) && selectedValue) {
      updateOrderedKeysImmediately(selectedValue);
    }
  }, [values, hasIcons, display, selectedValue]);
  
  // When selected value changes from external updates
  useEffect(() => {
    if (selectedValue && (display === 'tab_view' || hasIcons)) {
      const selectedKey = Object.keys(values).find(
        key => values[key].value === selectedValue
      );
      
      // If the selected value has changed
      if (selectedValue !== prevSelectedValueRef.current) {
        prevSelectedValueRef.current = selectedValue;
        
        if (selectedKey) {
          // Check if reordering is needed
          if (orderedKeys[0] !== selectedKey) {
            if (document.hasFocus() && containerRef.current) {
              // If the document has focus, animate the transition
              animateTabTransition(selectedKey);
            } else {
              // If no focus (like after a form submission), update immediately
              updateOrderedKeysImmediately(selectedKey);
            }
          }
        }
      }
    }
  }, [selectedValue, values, display, hasIcons, orderedKeys]);
  
  // Helper function to immediately update ordered keys without animation
  const updateOrderedKeysImmediately = (selectedKey) => {
    const key = Object.keys(values).find(k => values[k].value === selectedValue) || selectedKey;
    
    if (key) { 
      const remainingKeys = Object.keys(values).filter(k => k !== key);
      setOrderedKeys([key, ...remainingKeys]);
    }
  };

  const animateTabTransition = (selectedKey) => {
    if (animating) return;
    setAnimating(true);
    
    // Save current positions before reordering
    const initialPositions = {};
    orderedKeys.forEach(key => {
      if (tabRefs.current[key]) {
        initialPositions[key] = tabRefs.current[key].offsetLeft;
      }
    });
    
    // Reorder keys immediately in DOM
    const remainingKeys = orderedKeys.filter(key => key !== selectedKey);
    const newOrderedKeys = [selectedKey, ...remainingKeys];
    setOrderedKeys(newOrderedKeys);
    
    // Force a layout calculation to get new positions
    setTimeout(() => {
      // Get new positions after reordering
      const newPositions = {};
      newOrderedKeys.forEach(key => {
        if (tabRefs.current[key]) {
          newPositions[key] = tabRefs.current[key].offsetLeft;
        }
      });
      
      // Apply transforms to make tabs appear at their original positions
      newOrderedKeys.forEach(key => {
        if (tabRefs.current[key] && initialPositions[key] !== undefined && newPositions[key] !== undefined) {
          const tabElement = tabRefs.current[key];
          const deltaX = initialPositions[key] - newPositions[key];
          
          // Set initial position using transform
          tabElement.style.transform = `translateX(${deltaX}px)`;
          tabElement.style.transition = 'none';
        }
      });
      
      // Force browser to acknowledge the transforms before animating
      setTimeout(() => {
        // Now animate to final positions (remove transforms)
        newOrderedKeys.forEach(key => {
          if (tabRefs.current[key]) {
            const tabElement = tabRefs.current[key];
            tabElement.style.transition = 'transform 400ms cubic-bezier(0.2, 0.8, 0.2, 1)';
            tabElement.style.transform = 'translateX(0)';
          }
        });
        
        // Animation cleanup
        setTimeout(() => {
          newOrderedKeys.forEach(key => {
            if (tabRefs.current[key]) {
              tabRefs.current[key].style.transition = '';
              tabRefs.current[key].style.transform = '';
            }
          });
          setAnimating(false);
        }, 300);
      }, 20);
    }, 0);
  };

  // Handle click on the entire card when icons are present
  const handleCardClick = (value, isLocked, handleRadioChange) => {
    if (!isLocked && !animating) {
      handleRadioChange({ target: { value } });
    }
  };

  return {
    orderedKeys,
    tabRefs,
    containerRef,
    animating,
    hasIcons,
    handleCardClick
  };
};