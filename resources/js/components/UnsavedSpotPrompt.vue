<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CircleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { forgetUser } from '@/composables/useDeviceData';
import { parkInFlight, readLastSpot } from '@/composables/useLastSpot';
import { store } from '@/routes/spots';

const props = defineProps<{
    userId: number;
    household: { id: number; name: string };
}>();

// A capture that never reached the server (FM26, FM27). Offered on its own household's Home,
// so "Save now" posts it to the household it was captured for. The photo is not kept.
const unsaved = computed(() => {
    const entry = readLastSpot(props.userId);

    return entry?.status === 'unsaved' &&
        entry.household_id === props.household.id
        ? entry
        : null;
});

const form = useForm({
    household_id: 0,
    lat: '',
    lng: '',
    accuracy: '',
    note: '',
});

const failure = ref<string | null>(null);

const savedAt = computed(() =>
    unsaved.value
        ? new Date(unsaved.value.saved_at).toLocaleString(undefined, {
              dateStyle: 'medium',
              timeStyle: 'short',
          })
        : '',
);

const saveNow = () => {
    const entry = unsaved.value;

    if (!entry) {
        return;
    }

    failure.value = null;
    form.household_id = props.household.id;
    form.lat = entry.lat;
    form.lng = entry.lng;
    form.accuracy = entry.accuracy;
    form.note = entry.note ?? '';

    // Success redirects to the fresh spot page, which replaces the unsaved entry.
    form.post(store().url, {
        onNetworkError: () => {
            failure.value = 'Not saved — check your connection and retry.';

            return false;
        },
        onHttpException: (response) => {
            failure.value =
                response.status === 419
                    ? 'Your session expired — reload the page and sign in.'
                    : 'Not saved — retry.';

            return false;
        },
        onError: () => {
            failure.value = 'Not saved — park again from here.';
        },
    });
};

const discard = () => forgetUser(props.userId, { keepUnsaved: false });
</script>

<template>
    <Alert v-if="unsaved && !parkInFlight" data-test="unsaved-spot">
        <CircleAlert />
        <AlertTitle>You have an unsaved spot from {{ savedAt }}</AlertTitle>
        <AlertDescription>
            <p v-if="unsaved.note">{{ unsaved.note }}</p>
            <p v-if="failure" class="text-destructive">{{ failure }}</p>
            <div class="mt-2 flex gap-2">
                <Button
                    size="sm"
                    :disabled="form.processing"
                    data-test="save-unsaved"
                    @click="saveNow"
                >
                    <Spinner v-if="form.processing" />
                    Save now
                </Button>
                <Button
                    size="sm"
                    variant="outline"
                    :disabled="form.processing"
                    @click="discard"
                    >Discard</Button
                >
            </div>
        </AlertDescription>
    </Alert>
</template>
