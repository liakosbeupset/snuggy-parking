<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { History } from '@lucide/vue';
import { computed } from 'vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { lastResponse } from '@/composables/useLastSpot';

// A page older than two minutes came from the worker's cache (FM24). Set on the initial load
// and on `success` only, so Back (a history restore) never re-evaluates it.
const savedAt = computed(() => {
    if (!lastResponse.value?.stale) {
        return null;
    }

    const date = new Date(lastResponse.value.generatedAt);
    const today = date.toDateString() === new Date().toDateString();

    return date.toLocaleString(
        undefined,
        today
            ? { timeStyle: 'short' }
            : { dateStyle: 'medium', timeStyle: 'short' },
    );
});
</script>

<template>
    <Alert
        v-if="savedAt"
        class="rounded-none border-x-0"
        data-test="saved-copy"
    >
        <History />
        <AlertDescription class="flex flex-wrap items-center gap-2">
            <span
                >Showing a saved copy from {{ savedAt }} — may be outdated</span
            >
            <Button variant="outline" size="sm" @click="router.reload()"
                >Refresh</Button
            >
        </AlertDescription>
    </Alert>
</template>
