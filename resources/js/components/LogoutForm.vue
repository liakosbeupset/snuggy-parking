<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { clearUserCaches, forgetUser } from '@/composables/useDeviceData';
import { logout } from '@/routes';

const page = usePage();

const csrfToken =
    document
        .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.getAttribute('content') ?? '';

// Logging out is "forget me here": this user's caches and last spot, unsaved included (FM9).
// form.submit() does not fire `submit` again, and the logout goes ahead even if clearing fails.
const forgetThenSubmit = async (event: Event) => {
    const form = event.currentTarget as HTMLFormElement;
    const userId = page.props.auth.user?.id;

    try {
        if (userId !== undefined) {
            forgetUser(userId, { keepUnsaved: false });
        }

        await clearUserCaches();
    } finally {
        form.submit();
    }
};
</script>

<template>
    <!-- A native POST, not an Inertia visit: the whole document is replaced, then /login loads.
         The slot supplies the `type="submit"` button. -->
    <form
        method="post"
        :action="logout().url"
        @submit.prevent="forgetThenSubmit"
    >
        <input type="hidden" name="_token" :value="csrfToken" />
        <slot />
    </form>
</template>
