export const updateFieldLabel = (id, label) => ({
  type: 'UPDATE_LABEL',
  payload: { id, label },
});

// Action to update the description
export const updateFieldDescription = (id, description) => ({
  type: 'UPDATE_DESCRIPTION',
  payload: { id, description },
});

// Action to update the value
export const updateFieldValue = (id, value) => ({
  type: 'UPDATE_VALUE',
  payload: { id, value },
});

// Action to update the Placeholder
export const updatePlaceholderValue = (id, placeholder) => ({
  type: 'UPDATE_PLACEHOLDER',
  payload: { id, placeholder},
});

export const updateNameValue = (id, name) => ({
  type: 'UPDATE_NAME',
  payload: { id, name},
});

