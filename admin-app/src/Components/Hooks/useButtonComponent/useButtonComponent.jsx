import { useFormData } from "../../Context/FormContext";
import { updateData } from "../../Api/Api";
import { handleConnectGoogle, handleDisconnectGoogle } from '../../GlobalFunctions/GlobalFunctions';
import { toast } from 'react-toastify';
import { useFeaturesData } from "../../Context/FeaturesDataContext";

export const useButtonComponent = ({ id }) => {

    const { formData, currentFeatureId, setLoading } = useFormData();
    const { fetchFeaturesData } = useFeaturesData();

    const handleClick = async (e) => {
        e.preventDefault();

        const optionsToSend = Object.entries(formData).map(([key,option]) => ({            
            id: key,
            selected: option.selected,
            value: option.value
        }));
        try {
            if (id === "google_connect") {
                const formDataObj = { id: currentFeatureId, options: optionsToSend };
                const updateResponse = await updateData(formDataObj);

                if (updateResponse.success) {
                    const authUrl = await handleConnectGoogle();
                    if (authUrl) {
                        const popup = window.open(authUrl, 'GoogleAuthPopup', 'width=900,height=600');

                        const checkPopup = setInterval(() => {

                            try {
                                if (!popup || popup.closed) {
                                    clearInterval(checkPopup);
                                    setLoading(true);
                                    // Fetch data and handle loading state
                                    fetchFeaturesData()
                                        .then(() => {
                                            setLoading(false);
                                            toast.error("Google is not connected!");
                                        })
                                        .catch(error => {
                                            setLoading(false);
                                        });

                                    return;
                                }

                                if (popup.location.href.includes("connect-google")) {
                                    clearInterval(checkPopup);

                                    setTimeout(() => {
                                        try {
                                            const content = popup.document.body.textContent;
                                            if (content.includes('"code":200') || content.includes("refresh_token")) {
                                                popup.close();
                                                setLoading(true);
                                                fetchFeaturesData().then(() => {
                                                    setLoading(false);
                                                    toast.success("Google connected successfully!");
                                                })
                                                    .catch(error => {
                                                        setLoading(false);
                                                    });
                                            }
                                        } catch (accessError) {
                                            popup.close();
                                            setLoading(true);
                                            fetchFeaturesData()
                                                .then(() => {
                                                    setLoading(false);
                                                    toast.success("Connection completed!");
                                                })
                                                .catch(error => {
                                                    setLoading(false);
                                                });
                                        }
                                    }, 1000);
                                }
                            } catch (error) {
                            }
                        }, 500);
                    }
                }
            } else if (id === 'google_disconnect') {
                const disconnectSuccess = await handleDisconnectGoogle();
                if (disconnectSuccess) {
                    setLoading(true);
                    fetchFeaturesData()
                        .then(() => {
                            setLoading(false);
                            toast.success("Google disconnected successfully!");
                        })
                        .catch(error => {
                            setLoading(false);
                        });
                }
            }
        } catch (error) {
        }
    };

    return {
        handleClick

    };
}