import React from "react";
import GenericForm from "./GenericForm/GenericForm";

const CanvasContent = ({ featureData }) => {

  //**** comment the code that cause a re-render of the canvas component on every submit of the form

  // const [refreshKey, setRefreshKey] = useState(0);

  const handleFormSubmit = () => {
    // setRefreshKey((prevKey) => prevKey + 1);
  };

  // useEffect(() => {
  //   handleFormSubmit();
  // }, [featureData]);

  return (
    <div>
      <GenericForm
      // key={refreshKey}
      featureData={featureData} 
      // fetchFeaturesData={fetchFeaturesData} 
      onFormSubmit={handleFormSubmit} />
    </div>
  );
};

export default CanvasContent;