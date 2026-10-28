<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { TriangleAlert } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import type { GeoResult } from '@/composables/useGeolocation';
import {
    fromCapture,
    parkInFlight,
    readLastSpot,
    removeLastSpot,
    writeLastSpot,
} from '@/composables/useLastSpot';
import { downscale } from '@/composables/usePhotoDownscale';
import { login } from '@/routes';
import { store } from '@/routes/spots';

const props = defineProps<{
    householdId: number;
    householdName: string;
    position: GeoResult | null;
}>();

const open = defineModel<boolean>('open', { required: true });

// useForm rather than <Form>: lat, lng and accuracy come from the Geolocation API, not inputs.
const form = useForm<{
    household_id: number;
    lat: number;
    lng: number;
    accuracy: number;
    note: string;
    photo: File | null;
}>({
    household_id: props.householdId,
    lat: 0,
    lng: 0,
    accuracy: 0,
    note: '',
    photo: null,
});

const preview = ref<string | null>(null);
const photoInput = ref<HTMLInputElement | null>(null);

const setPreview = (file: File | null) => {
    if (preview.value) {
        URL.revokeObjectURL(preview.value);
    }

    preview.value = file ? URL.createObjectURL(file) : null;
};

// A new fix starts a fresh park: the household is the one Home displayed (KD10).
watch(
    () => props.position,
    (position) => {
        if (!position) {
            return;
        }

        form.reset();
        form.clearErrors();
        form.household_id = props.householdId;
        status.value = null;
        form.lat = position.lat;
        form.lng = position.lng;
        form.accuracy = position.accuracy;
        setPreview(null);
    },
    { immediate: true },
);

// Where a POST that has not completed stands. The capture is already on the device by then.
const status = ref<
    'saving' | 'slow' | 'failed' | 'expired' | 'forbidden' | 'too-large' | null
>(null);
let slowTimer: ReturnType<typeof setTimeout> | undefined;
// A cancelled request finishes after its replacement starts: only the latest one settles state.
let attempt = 0;

onBeforeUnmount(() => {
    setPreview(null);
    clearTimeout(slowTimer);
});

const accuracy = computed(() => Math.round(form.accuracy));
const imprecise = computed(() => form.accuracy > 50);
const locationError = computed(
    () =>
        form.errors.lat ??
        form.errors.lng ??
        form.errors.accuracy ??
        form.errors.household_id,
);

const choosePhoto = async (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form.photo = file ? await downscale(file) : null;
    form.clearErrors('photo');
    setPreview(form.photo);
};

const removePhoto = () => {
    form.photo = null;
    setPreview(null);

    if (photoInput.value) {
        photoInput.value.value = '';
    }
};

const page = usePage();

const submit = () => {
    const current = ++attempt;
    const user = page.props.auth.user;

    // Kept before the request leaves: a weak signal or an expired session loses only the photo (FM26, FM27).
    if (user) {
        writeLastSpot(
            user.id,
            fromCapture(
                { lat: form.lat, lng: form.lng, accuracy: form.accuracy },
                form.note || null,
                { id: props.householdId, name: props.householdName },
                user.name,
            ),
        );
    }

    status.value = 'saving';
    parkInFlight.value = true;
    clearTimeout(slowTimer);
    slowTimer = setTimeout(() => {
        if (status.value === 'saving') {
            status.value = 'slow';
        }
    }, 20_000);

    form.post(store().url, {
        forceFormData: true,
        // Returning false suppresses Inertia's defaults (a no-op and an error dialog).
        onNetworkError: () => {
            status.value = 'failed';

            return false;
        },
        // CSRF runs before auth, so an expired session answers 419.
        onHttpException: (response) => {
            // 403: no longer a member here (FM18); 413: over the server's body limit. Retry cannot help either.
            status.value =
                response.status === 419
                    ? 'expired'
                    : response.status === 403
                      ? 'forbidden'
                      : response.status === 413
                        ? 'too-large'
                        : 'failed';

            return false;
        },
        // The server has the spot: the capture is never offered again, even when a fast phone
        // clock keeps the response from counting as fresh (which would replace it otherwise).
        onSuccess: () => {
            if (user && readLastSpot(user.id)?.status === 'unsaved') {
                removeLastSpot(user.id);
            }
        },
        // Validation: field errors, shown below.
        onError: () => {
            status.value = null;
        },
        onFinish: () => {
            if (current === attempt) {
                clearTimeout(slowTimer);
                parkInFlight.value = false;
            }
        },
    });
};

const retry = () => {
    form.cancel();
    submit();
};

// Stop waiting; the capture stays on the device and Home offers it again.
const stop = () => {
    attempt++;
    form.cancel();
    clearTimeout(slowTimer);
    parkInFlight.value = false;
    status.value = null;
    open.value = false;
};
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="bottom"
            class="max-h-[90dvh] overflow-y-auto rounded-t-xl"
        >
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <SheetHeader>
                    <SheetTitle>Park here</SheetTitle>
                    <SheetDescription>
                        For {{ householdName }} · accurate to ±{{ accuracy }}
                        m
                    </SheetDescription>
                </SheetHeader>

                <div class="flex flex-col gap-4 px-4">
                    <Alert v-if="imprecise" data-test="accuracy-warning">
                        <TriangleAlert />
                        <AlertDescription>
                            Your location is only accurate to ±{{ accuracy }} m.
                            You can still park, or move into the open and try
                            again.
                        </AlertDescription>
                    </Alert>
                    <InputError :message="locationError" />

                    <div class="grid gap-2">
                        <Label for="note">Note (optional)</Label>
                        <textarea
                            id="note"
                            v-model="form.note"
                            maxlength="500"
                            rows="3"
                            placeholder="Level 2, near the lifts"
                            class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                        />
                        <InputError :message="form.errors.note" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="photo">Photo (optional)</Label>
                        <input
                            id="photo"
                            ref="photoInput"
                            type="file"
                            accept="image/*"
                            capture="environment"
                            class="text-sm file:mr-3 file:rounded-md file:border file:border-input file:bg-transparent file:px-3 file:py-1 file:text-sm file:font-medium"
                            @change="choosePhoto"
                        />
                        <div v-if="preview" class="flex items-start gap-2">
                            <img
                                :src="preview"
                                alt="Photo preview"
                                class="max-h-40 rounded-md border object-cover"
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="removePhoto"
                                >Remove</Button
                            >
                        </div>
                        <InputError :message="form.errors.photo" />
                    </div>
                </div>

                <SheetFooter>
                    <div
                        v-if="status"
                        class="flex flex-wrap items-center gap-2 text-sm"
                        data-test="park-status"
                    >
                        <template v-if="status === 'saving'">
                            Saving…
                        </template>
                        <template v-else-if="status === 'slow'">
                            <span
                                >Still trying — your location is kept on this
                                device</span
                            >
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="retry"
                                >Retry</Button
                            >
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                @click="stop"
                                >Cancel</Button
                            >
                        </template>
                        <template v-else-if="status === 'expired'">
                            <span class="text-destructive"
                                >Your session expired —
                                <a :href="login().url" class="underline"
                                    >sign in to save this spot</a
                                ></span
                            >
                        </template>
                        <template v-else-if="status === 'forbidden'">
                            <span class="text-destructive"
                                >Not saved — you can no longer park in this
                                household</span
                            >
                        </template>
                        <template v-else-if="status === 'too-large'">
                            <span class="text-destructive"
                                >Not saved — the photo is too large. Remove it
                                and try again.</span
                            >
                        </template>
                        <template v-else>
                            <span class="text-destructive">Not saved —</span>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                @click="submit"
                                >Retry</Button
                            >
                        </template>
                    </div>
                    <Button
                        type="submit"
                        size="lg"
                        :disabled="form.processing"
                        data-test="save-spot"
                    >
                        <Spinner v-if="form.processing" />
                        Save spot
                    </Button>
                </SheetFooter>
            </form>
        </SheetContent>
    </Sheet>
</template>
