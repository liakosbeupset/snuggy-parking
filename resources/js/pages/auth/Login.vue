<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { redirect } from '@/routes/auth/google';

defineOptions({
    layout: {
        title: 'Snuggy Parking',
        description: 'Remember where the household car is parked',
    },
});
</script>

<template>
    <Head title="Log in" />

    <!-- A full same-window navigation, never a popup: the OAuth round trip must stay in this window. -->
    <Button as-child class="w-full" data-test="google-login-button">
        <a :href="redirect().url">Continue with Google</a>
    </Button>
</template>
