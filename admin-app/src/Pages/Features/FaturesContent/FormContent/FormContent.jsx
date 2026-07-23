import React, { useState } from "react";
import TabView from "../TabView/TabView";
import FieldList from "../FieldList/FieldList";

const FormContent = ({ data, handleInputChange, handleNestedSubmit }) => {
  const [activeTab, setActiveTab] = useState(0);

  // Render tabs if display_as_tabs is true, otherwise render fields normally
  if (data.display_as_tabs && data.tab_config && data.tab_config.length > 0) {
    return (
      <TabView tabConfig={data.tab_config} activeTab={activeTab} setActiveTab={setActiveTab} data={data} handleInputChange={handleInputChange} handleNestedSubmit={handleNestedSubmit} />
    );
  } else {
    // render list view if display as tabs is not true
    return (
      <div className="mb-5">
        <FieldList fields={data.options || data.files || {}} handleInputChange={handleInputChange} handleNestedSubmit={handleNestedSubmit} />
      </div>
    );
  }
};

export default FormContent;