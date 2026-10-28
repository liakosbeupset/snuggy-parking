<script setup lang="ts">
import L from 'leaflet';
import markerIcon2x from 'leaflet/dist/images/marker-icon-2x.png';
import markerIcon from 'leaflet/dist/images/marker-icon.png';
import markerShadow from 'leaflet/dist/images/marker-shadow.png';
import 'leaflet/dist/leaflet.css';
import { onBeforeUnmount, onMounted, useTemplateRef } from 'vue';

const props = defineProps<{
    lat: string | number;
    lng: string | number;
    accuracy: string | number;
}>();

// Leaflet guesses its marker image URLs from the CSS path, which Vite rewrites.
L.Icon.Default.mergeOptions({
    iconUrl: markerIcon,
    iconRetinaUrl: markerIcon2x,
    shadowUrl: markerShadow,
});

const container = useTemplateRef<HTMLDivElement>('container');
let map: L.Map | null = null;

onMounted(() => {
    if (!container.value) {
        return;
    }

    // Decimal strings from the server; Leaflet needs numbers.
    const at: L.LatLngTuple = [Number(props.lat), Number(props.lng)];

    map = L.map(container.value).setView(at, 18);

    // Without tiles (FM12) the map is grey, but the marker and circle still render.
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        crossOrigin: true,
        attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map);

    L.marker(at).addTo(map);
    L.circle(at, { radius: Number(props.accuracy) }).addTo(map);
});

onBeforeUnmount(() => {
    map?.remove();
    map = null;
});
</script>

<template>
    <div
        ref="container"
        class="z-0 h-72 w-full overflow-hidden rounded-lg border bg-muted"
        data-test="spot-map"
    />
</template>
