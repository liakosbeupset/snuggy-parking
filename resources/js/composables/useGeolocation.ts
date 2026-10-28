import { ref } from 'vue';
import type { Ref } from 'vue';

export type GeoResult = { lat: number; lng: number; accuracy: number };

// 1 = denied, 2 = unavailable, 3 = timeout (GeolocationPositionError), 0 = unsupported.
export type GeoError = { code: 0 | 1 | 2 | 3; message: string };

const messages: Record<GeoError['code'], string> = {
    0: "This browser can't share your location. Open the app over HTTPS in a current browser.",
    1: 'Location access is blocked. Allow location for this site in your browser or phone settings, then try again.',
    2: "Your location couldn't be determined. Move somewhere with a clearer view of the sky and try again.",
    3: 'Finding your location took too long. Try again.',
};

/**
 * One high-accuracy fix for "I parked here". Nothing is sent to the server on failure (FM10).
 */
export function useGeolocation(): {
    locating: Ref<boolean>;
    error: Ref<GeoError | null>;
    locate(): Promise<GeoResult | null>;
} {
    const locating = ref(false);
    const error = ref<GeoError | null>(null);

    const fail = (code: GeoError['code']) => {
        error.value = { code, message: messages[code] };

        return null;
    };

    const locate = (): Promise<GeoResult | null> => {
        error.value = null;

        if (!('geolocation' in navigator)) {
            return Promise.resolve(fail(0));
        }

        locating.value = true;

        return new Promise((resolve) => {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    locating.value = false;
                    resolve({
                        lat: position.coords.latitude,
                        lng: position.coords.longitude,
                        accuracy: position.coords.accuracy,
                    });
                },
                (positionError) => {
                    locating.value = false;
                    const code = positionError.code;
                    resolve(
                        fail(code === 1 || code === 2 || code === 3 ? code : 2),
                    );
                },
                { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
            );
        });
    };

    return { locating, error, locate };
}
