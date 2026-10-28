<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { CircleAlert, MapPin, Navigation } from '@lucide/vue';
import { formatTimeAgo } from '@vueuse/core';
import { computed, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import ParkSheet from '@/components/ParkSheet.vue';
import SpotMap from '@/components/SpotMap.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import UnsavedSpotPrompt from '@/components/UnsavedSpotPrompt.vue';
import { useGeolocation } from '@/composables/useGeolocation';
import type { GeoResult } from '@/composables/useGeolocation';
import {
    lastResponse,
    readLastSpot,
    STATUS_LABEL,
} from '@/composables/useLastSpot';
import { home } from '@/routes';
import { show } from '@/routes/spots';
import type { Spot } from '@/types';

const props = defineProps<{
    household: { id: number; name: string };
    activeSpot: Spot | null;
    recentSpots: Spot[];
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

const { locating, error, locate } = useGeolocation();
const position = ref<GeoResult | null>(null);
const sheetOpen = ref(false);

const parkHere = async () => {
    const fix = await locate();

    if (fix) {
        position.value = fix;
        sheetOpen.value = true;
    }
};

const page = usePage();
const user = computed(() => page.props.auth.user);

// A cache-served Home predates what this device saw since: lead with that (FM24).
const lastSeen = computed(() => {
    const response = lastResponse.value;
    const entry = user.value ? readLastSpot(user.value.id) : null;

    return response?.stale &&
        entry &&
        Date.parse(entry.saved_at) > Date.parse(response.generatedAt)
        ? entry
        : null;
});

const timeAgo = (iso: string) => formatTimeAgo(new Date(iso));

const excerpt = (note: string | null) =>
    note && note.length > 80 ? `${note.slice(0, 80)}…` : note;
</script>

<template>
    <Head title="Home" />

    <div class="flex max-w-2xl flex-col gap-6 p-4">
        <Heading
            title="Snuggy Parking"
            :description="`Where ${props.household.name} parked`"
        />

        <Card v-if="lastSeen" data-test="last-seen-spot">
            <CardHeader>
                <CardTitle>Last spot you saw</CardTitle>
                <CardDescription>
                    {{ STATUS_LABEL[lastSeen.status] }} ·
                    {{ timeAgo(lastSeen.parked_at) }}
                    <template v-if="lastSeen.parked_by">
                        · by {{ lastSeen.parked_by }}</template
                    >
                </CardDescription>
            </CardHeader>
            <CardContent class="space-y-3">
                <SpotMap
                    :lat="lastSeen.lat"
                    :lng="lastSeen.lng"
                    :accuracy="lastSeen.accuracy"
                />
                <p v-if="lastSeen.note" class="text-sm whitespace-pre-line">
                    {{ lastSeen.note }}
                </p>
            </CardContent>
            <CardFooter>
                <Button :as-child="true">
                    <a
                        :href="lastSeen.directions_url"
                        target="_blank"
                        rel="noopener"
                    >
                        <Navigation />
                        Get directions
                    </a>
                </Button>
            </CardFooter>
        </Card>

        <UnsavedSpotPrompt
            v-if="user"
            :user-id="user.id"
            :household="household"
        />

        <Button
            size="lg"
            class="h-16 text-lg"
            :disabled="locating"
            data-test="park-here"
            @click="parkHere"
        >
            <Spinner v-if="locating" />
            <MapPin v-else />
            {{ locating ? 'Finding you…' : 'I parked here' }}
        </Button>

        <Alert v-if="error" variant="destructive" data-test="location-error">
            <CircleAlert />
            <AlertTitle>No location</AlertTitle>
            <AlertDescription>
                <p>{{ error.message }}</p>
                <Button
                    variant="outline"
                    size="sm"
                    class="mt-2"
                    :disabled="locating"
                    @click="parkHere"
                    >Retry</Button
                >
            </AlertDescription>
        </Alert>

        <Card v-if="activeSpot" data-test="active-spot">
            <CardHeader>
                <CardTitle>The car is parked</CardTitle>
                <CardDescription>
                    {{ timeAgo(activeSpot.parked_at) }}
                    <template v-if="activeSpot.parked_by">
                        · by {{ activeSpot.parked_by }}</template
                    >
                </CardDescription>
            </CardHeader>
            <CardContent v-if="activeSpot.note">
                <p class="text-sm">{{ excerpt(activeSpot.note) }}</p>
            </CardContent>
            <CardFooter>
                <Button :as-child="true">
                    <Link :href="show(activeSpot.id)">View</Link>
                </Button>
            </CardFooter>
        </Card>
        <p v-else class="text-sm text-muted-foreground">
            No car parked right now.
        </p>

        <section v-if="recentSpots.length" class="space-y-3">
            <Heading variant="small" title="Recent spots" />
            <ul class="divide-y rounded-lg border">
                <li v-for="spot in recentSpots" :key="spot.id">
                    <Link
                        :href="show(spot.id)"
                        class="flex items-center gap-3 p-3 hover:bg-accent"
                        data-test="recent-spot"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium">
                                {{ spot.note || 'No note' }}
                            </p>
                            <p class="text-xs text-muted-foreground">
                                {{ timeAgo(spot.parked_at) }}
                                <template v-if="spot.parked_by">
                                    · {{ spot.parked_by }}</template
                                >
                            </p>
                        </div>
                        <Badge v-if="spot.is_active">Active</Badge>
                    </Link>
                </li>
            </ul>
        </section>

        <ParkSheet
            v-model:open="sheetOpen"
            :household-id="household.id"
            :household-name="household.name"
            :position="position"
        />
    </div>
</template>
