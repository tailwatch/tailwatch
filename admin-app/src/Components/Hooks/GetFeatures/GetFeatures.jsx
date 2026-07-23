import React,{useEffect} from 'react'
import { GetData } from '../../Api/Api';

const GetFeatures = () => {
    
  useEffect(() => {    
    const fetchData = async () => {
      try {
        const featuresData = await GetData();
      } catch (error) {
      }
    };

    fetchData();
  }, []);  
}

export default GetFeatures