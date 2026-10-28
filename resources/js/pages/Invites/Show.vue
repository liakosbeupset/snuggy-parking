<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import InviteAcceptanceController from '@/actions/App/Http/Controllers/InviteAcceptanceController';
import Heading from '@/components/Heading.vue';
import LogoutForm from '@/components/LogoutForm.vue';
import { Button } from '@/components/ui/button';

// `household` and `inviter` are null unless the invite was sent to this account (I1).
defineProps<{
    token: string;
    state: 'pending' | 'accepted' | 'expired';
    matches: boolean;
    household: { name: string } | null;
    inviter: { name: string } | null;
}>();
</script>

<template>
    <Head title="Invite" />

    <div class="flex max-w-xl flex-col gap-6 p-4">
        <template v-if="!matches">
            <Heading
                title="This invite was sent to a different email address"
                description="Sign in with the Google account the invite was sent to."
            />
            <LogoutForm>
                <Button type="submit" data-test="switch-account"
                    >Sign in with a different Google account</Button
                >
            </LogoutForm>
        </template>

        <template v-else-if="state === 'pending'">
            <Heading
                :title="`${inviter?.name} invited you to ${household?.name}`"
                description="Join to see where the household car is parked."
            />
            <Form
                v-bind="InviteAcceptanceController.accept.form(token)"
                v-slot="{ processing }"
            >
                <Button :disabled="processing" data-test="accept-invite"
                    >Accept invite</Button
                >
            </Form>
        </template>

        <Heading
            v-else-if="state === 'accepted'"
            title="This invite has already been used"
            description="Ask the household owner for a new invite if you still need one."
        />

        <Heading
            v-else
            title="This invite has expired"
            description="Ask the household owner to send you a new one."
        />
    </div>
</template>
