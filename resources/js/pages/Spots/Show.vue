<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Navigation } from '@lucide/vue';
import { formatTimeAgo } from '@vueuse/core';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import SpotMap from '@/components/SpotMap.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { home } from '@/routes';
import { retrieve } from '@/routes/spots';
import type { Spot } from '@/types';

const props = defineProps<{
    spot: Spot;
    household: { id: number; name: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Home',
                href: home(),
            },
        ],
    },
});

const accuracy = computed(() => Math.round(Number(props.spot.accuracy)));
const parkedAt = computed(() =>
    new Date(props.spot.parked_at).toLocaleString(undefined, {
        dateStyle: 'medium',
        timeStyle: 'short',
    }),
);

const retrieving = ref(false);
const gotTheCar = () =>
    router.patch(
        retrieve(props.spot.id).url,
        {},
        {
            onStart: () => (retrieving.value = true),
            onFinish: () => (retrieving.value = false),
        },
    );
</script>

<template>
    <Head title="Parked car" />

    <div class="flex max-w-2xl flex-col gap-4 p-4">
        <Heading
            title="Parked car"
            :description="`${household.name} · ${parkedAt}`"
        />

        <SpotMap
            :key="spot.id"
            :lat="spot.lat"
            :lng="spot.lng"
            :accuracy="spot.accuracy"
        />

        <div class="flex flex-wrap items-center gap-2 text-sm">
            <span data-test="parked-by"
                >Parked by {{ spot.parked_by ?? 'a former member' }} ·
                {{ formatTimeAgo(new Date(spot.parked_at)) }}</span
            >
            <Badge variant="secondary">±{{ accuracy }} m</Badge>
            <Badge v-if="!spot.is_active" variant="outline">Collected</Badge>
        </div>

        <p v-if="spot.note" class="text-sm whitespace-pre-line">
            {{ spot.note }}
        </p>

        <img
            v-if="spot.photo_url"
            :src="spot.photo_url"
            alt="Photo of the parking spot"
            class="max-h-96 w-full rounded-lg border object-contain"
        />

        <div class="flex flex-col gap-2 sm:flex-row">
            <Button size="lg" :as-child="true">
                <a
                    :href="spot.directions_url"
                    target="_blank"
                    rel="noopener"
                    data-test="directions"
                >
                    <Navigation />
                    Get directions
                </a>
            </Button>
            <Button
                v-if="spot.is_active"
                size="lg"
                variant="outline"
                :disabled="retrieving"
                data-test="got-the-car"
                @click="gotTheCar"
                >Got the car</Button
            >
        </div>
    </div>
</template>
