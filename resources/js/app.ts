import { createInertiaApp, router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import { initializeTheme } from '@/composables/useAppearance';
import { syncDeviceUser } from '@/composables/useDeviceData';
import { recordResponse } from '@/composables/useLastSpot';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import {
    registerServiceWorker,
    unregisterServiceWorkers,
} from '@/pwa/register';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

// Device data follows the signed-in user (I11); fresh responses update the last spot...
router.on('navigate', (event) => {
    const { page, type } = event.detail;
    void syncDeviceUser(
        page.props.auth.user?.id ?? null,
        page.component === 'auth/Login',
    );

    if (type === 'initial') {
        recordResponse(page);
    }
});
router.on('success', (event) => recordResponse(event.detail.page));

// Back after logout restores the old page from the back/forward cache, data and all (FM9):
// hide it and ask the server again, which sends a signed-out visitor to /login.
window.addEventListener('pageshow', (event) => {
    if (event.persisted) {
        document.documentElement.style.visibility = 'hidden';
        window.location.reload();
    }
});

// The service worker runs in production builds only; dev clears any left behind...
if (import.meta.env.PROD) {
    registerServiceWorker((apply) =>
        toast('Update available', {
            // Stays until tapped: the new version waits on it (FM13).
            duration: Infinity,
            action: { label: 'Reload', onClick: apply },
        }),
    );
} else {
    void unregisterServiceWorkers();
}
