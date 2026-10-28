<script setup lang="ts">
import { Form, Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import HouseholdInviteController from '@/actions/App/Http/Controllers/HouseholdInviteController';
import HouseholdMemberController from '@/actions/App/Http/Controllers/HouseholdMemberController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { getInitials } from '@/composables/useInitials';
import { show } from '@/routes/household';

type Member = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
    is_owner: boolean;
};

type PendingInvite = {
    id: number;
    email: string;
    expires_at: string;
    link: string;
    inviter_name: string;
};

const props = defineProps<{
    household: { id: number; name: string; owner_id: number };
    members: Member[];
    // Null for anyone but the owner: members never receive invite tokens.
    pendingInvites: PendingInvite[] | null;
    canInvite: boolean;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Household',
                href: show(),
            },
        ],
    },
});

const page = usePage();
const isOwner = computed(
    () => page.props.auth.user?.id === props.household.owner_id,
);

const copyLink = async (invite: PendingInvite) => {
    try {
        await navigator.clipboard.writeText(invite.link);
        toast.success(`Link for ${invite.email} copied.`);
    } catch {
        toast.error("Couldn't copy the link.");
    }
};

const formatDate = (iso: string) =>
    new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' });
</script>

<template>
    <Head title="Household" />

    <div class="flex max-w-2xl flex-col gap-8 p-4">
        <Heading :title="household.name" description="Who can see the car" />

        <section class="space-y-3">
            <Heading variant="small" title="Members" />
            <ul class="divide-y rounded-lg border">
                <li
                    v-for="member in members"
                    :key="member.id"
                    class="flex items-center gap-3 p-3"
                    data-test="member"
                >
                    <Avatar class="size-8 overflow-hidden rounded-full">
                        <AvatarImage
                            v-if="member.avatar"
                            :src="member.avatar"
                            :alt="member.name"
                        />
                        <AvatarFallback>{{
                            getInitials(member.name)
                        }}</AvatarFallback>
                    </Avatar>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ member.name }}
                        </p>
                        <p class="truncate text-xs text-muted-foreground">
                            {{ member.email }}
                        </p>
                    </div>
                    <Badge v-if="member.is_owner" variant="secondary"
                        >Owner</Badge
                    >
                    <Form
                        v-else-if="canInvite"
                        v-bind="
                            HouseholdMemberController.destroy.form({
                                household: household.id,
                                user: member.id,
                            })
                        "
                        v-slot="{ processing }"
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="processing"
                            data-test="remove-member"
                            >Remove</Button
                        >
                    </Form>
                </li>
            </ul>
        </section>

        <section v-if="canInvite" class="space-y-3">
            <Heading
                variant="small"
                title="Invite someone"
                description="They sign in with the Google account for this address"
            />
            <Form
                v-bind="HouseholdInviteController.store.form(household.id)"
                :reset-on-success="true"
                class="flex flex-col gap-2 sm:flex-row sm:items-start"
                v-slot="{ errors, processing }"
            >
                <div class="grid flex-1 gap-2">
                    <Label for="email" class="sr-only">Email address</Label>
                    <Input
                        id="email"
                        name="email"
                        type="email"
                        required
                        autocomplete="off"
                        placeholder="name@example.com"
                    />
                    <InputError :message="errors.email" />
                </div>
                <Button :disabled="processing" data-test="send-invite"
                    >Send invite</Button
                >
            </Form>
        </section>

        <section v-if="pendingInvites" class="space-y-3">
            <Heading variant="small" title="Pending invites" />
            <p
                v-if="pendingInvites.length === 0"
                class="text-sm text-muted-foreground"
            >
                No pending invites.
            </p>
            <ul v-else class="divide-y rounded-lg border">
                <li
                    v-for="invite in pendingInvites"
                    :key="invite.id"
                    class="flex flex-wrap items-center gap-2 p-3"
                    data-test="pending-invite"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">
                            {{ invite.email }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            Expires {{ formatDate(invite.expires_at) }}
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="copyLink(invite)"
                        >Copy link</Button
                    >
                    <Form
                        v-bind="
                            HouseholdInviteController.destroy.form({
                                household: household.id,
                                invite: invite.id,
                            })
                        "
                        v-slot="{ processing }"
                    >
                        <Button
                            variant="ghost"
                            size="sm"
                            :disabled="processing"
                            data-test="cancel-invite"
                            >Cancel invite</Button
                        >
                    </Form>
                </li>
            </ul>
        </section>

        <section v-if="!isOwner">
            <Dialog>
                <DialogTrigger :as-child="true">
                    <Button variant="outline" data-test="leave-household"
                        >Leave household</Button
                    >
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Leave {{ household.name }}?</DialogTitle>
                        <DialogDescription>
                            You will no longer see its parked car. The owner can
                            invite you again.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <DialogClose :as-child="true">
                            <Button variant="outline">Stay</Button>
                        </DialogClose>
                        <Form
                            v-bind="
                                HouseholdMemberController.leave.form(
                                    household.id,
                                )
                            "
                            v-slot="{ processing }"
                        >
                            <Button
                                variant="destructive"
                                :disabled="processing"
                                data-test="confirm-leave"
                                >Leave</Button
                            >
                        </Form>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </section>
    </div>
</template>
