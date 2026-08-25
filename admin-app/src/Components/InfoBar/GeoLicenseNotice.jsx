import { useNavigate } from 'react-router-dom';
import InfoBar from './InfoBar';

// Shared MaxMind / GeoLite status notice for the geo-blocking and login-defender
// modules. Renders a warning when the GeoIP database is not present yet, and an
// info hint when it exists but the MaxMind license is no longer connected.
// `isGeoLicense` comes from the tailwatch_is_geo_lite_connected_or_exist action
// ({ code, is_connected, file_exists, message }).
const GeoLicenseNotice = ({ isGeoLicense }) => {
    const navigate = useNavigate();

    if (!isGeoLicense) {
        return null;
    }

    return (
        <>
            {isGeoLicense.file_exists === false && (
                <InfoBar
                    type="warning"
                    message={
                        <span>
                            You have not connected the MaxMind license key. Please integrate it first.{' '}
                            <a
                                href="/dashboard/settings/integration"
                                className="underline font-semibold hover:text-blue-700"
                                onClick={(e) => { e.preventDefault(); navigate('/dashboard/settings/integration'); }}
                            >
                                Go to Integration
                            </a>
                        </span>
                    }
                />
            )}
            {isGeoLicense.file_exists === true && isGeoLicense.is_connected === false && (
                <InfoBar type="info" message="Please update your Maxmind license key to get new updates." />
            )}
        </>
    );
};

export default GeoLicenseNotice;
