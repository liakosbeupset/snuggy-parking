<?php

use App\Mail\HouseholdInviteMail;
use App\Models\HouseholdInvite;
use App\Models\User;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

test('the owner creates an invite, it is emailed, and its link is in the page props', function () {
    Mail::fake();
    $owner = User::factory()->create(['name' => 'Alice Owner']);
    $household = $owner->currentHousehold();

    $this->actingAs($owner)
        ->post(route('households.invites.store', $household), ['email' => 'Carol@Example.com'])
        ->assertRedirect(route('household.show'))
        ->assertInertiaFlash('toast.message', 'Invite sent to carol@example.com.');

    $invite = HouseholdInvite::sole();

    expect($invite->email)->toBe('carol@example.com')
        ->and($invite->household_id)->toBe($household->id)
        ->and($invite->invited_by)->toBe($owner->id)
        ->and(strlen($invite->token))->toBe(64)
        ->and($invite->expires_at->isFuture())->toBeTrue();

    Mail::assertSent(HouseholdInviteMail::class, fn (HouseholdInviteMail $mail) => $mail->hasTo('carol@example.com')
        && $mail->invite->is($invite));

    $this->actingAs($owner)
        ->get(route('household.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('pendingInvites.0.link', route('invites.show', $invite->token)));
});

test('the invite email names the inviter and household and links to the invite', function () {
    $owner = User::factory()->create(['name' => 'Alice Owner']);
    $invite = HouseholdInvite::factory()->create(['household_id' => $owner->currentHousehold()->id]);

    $mail = new HouseholdInviteMail($invite);

    $mail->assertHasSubject("Alice Owner invited you to Alice's household on Snuggy Parking");
    $mail->assertSeeInHtml(route('invites.show', $invite->token));
});

test('a member who is not the owner cannot invite', function () {
    Mail::fake();
    $owner = User::factory()->create();
    $household = $owner->currentHousehold();
    $member = User::factory()->create();
    $household->members()->attach($member);

    $this->actingAs($member)
        ->post(route('households.invites.store', $household), ['email' => 'carol@example.com'])
        ->assertForbidden();

    expect(HouseholdInvite::count())->toBe(0);
    Mail::assertNothingSent();
});

test('a non-member cannot invite', function () {
    $household = User::factory()->create()->currentHousehold();
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->post(route('households.invites.store', $household), ['email' => 'carol@example.com'])
        ->assertForbidden();

    expect(HouseholdInvite::count())->toBe(0);
});

test('inviting an existing member is a validation error', function () {
    $owner = User::factory()->create();
    $household = $owner->currentHousehold();
    $member = User::factory()->create(['email' => 'bob@example.com']);
    $household->members()->attach($member);

    $this->actingAs($owner)
        ->post(route('households.invites.store', $household), ['email' => 'BOB@example.com'])
        ->assertSessionHasErrors(['email' => 'bob@example.com is already a member.']);

    expect(HouseholdInvite::count())->toBe(0);
});

test('an invalid email is a validation error', function () {
    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('households.invites.store', $owner->currentHousehold()), ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email');
});

test('a failing mailer still creates the invite and warns the owner', function () {
    Exceptions::fake();
    Mail::extend('failing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Connection timed out');
        }

        public function __toString(): string
        {
            return 'failing://';
        }
    });
    config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);

    $owner = User::factory()->create();

    $this->actingAs($owner)
        ->post(route('households.invites.store', $owner->currentHousehold()), ['email' => 'carol@example.com'])
        ->assertRedirect(route('household.show'))
        ->assertInertiaFlash('toast.type', 'warning')
        ->assertInertiaFlash('toast.message', "Invite created, but the email couldn't be sent — copy the link below and share it.");

    expect(HouseholdInvite::where('email', 'carol@example.com')->exists())->toBeTrue();
    Exceptions::assertReported(TransportException::class);
});

test('invite creation allows 20 requests a minute per user', function () {
    Mail::fake();
    $owner = User::factory()->create();
    $household = $owner->currentHousehold();

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($owner)
            ->post(route('households.invites.store', $household), ['email' => "guest{$i}@example.com"])
            ->assertRedirect();
    }

    $this->actingAs($owner)
        ->post(route('households.invites.store', $household), ['email' => 'one-more@example.com'])
        ->assertTooManyRequests();

    // Keyed by user: another owner still has their own budget.
    $other = User::factory()->create();
    $this->actingAs($other)
        ->post(route('households.invites.store', $other->currentHousehold()), ['email' => 'carol@example.com'])
        ->assertRedirect(route('household.show'));
});

test('a name with Markdown stays text in the invite email', function () {
    $owner = User::factory()->create(['name' => '[Claim prize](https://evil.test)']);
    $invite = HouseholdInvite::factory()->create(['household_id' => $owner->currentHousehold()->id]);

    $html = (new HouseholdInviteMail($invite))->render();

    expect($html)->not->toContain('href="https://evil.test"')
        ->and($html)->toContain('[Claim prize](https://evil.test)');
});
