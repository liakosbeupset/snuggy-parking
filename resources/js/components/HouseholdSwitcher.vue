<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { update } from '@/routes/current-household';

const page = usePage();
const current = computed(() => page.props.currentHousehold);

const switchTo = (householdId: number) => {
    if (householdId !== current.value?.id) {
        router.put(update.url(), { household_id: householdId });
    }
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger :as-child="true">
            <Button
                variant="ghost"
                class="h-9 max-w-48 px-2"
                data-test="household-switcher"
            >
                <span class="truncate">{{ current?.name }}</span>
                <ChevronsUpDown class="ml-1 size-4 opacity-60" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuLabel>Households</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                v-for="household in page.props.households"
                :key="household.id"
                class="cursor-pointer"
                @select="switchTo(household.id)"
            >
                <span class="truncate">{{ household.name }}</span>
                <Check
                    v-if="household.id === current?.id"
                    class="ml-auto size-4"
                />
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
