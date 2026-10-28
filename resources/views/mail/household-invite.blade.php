<x-mail::message>
# You're invited

{{ $inviterName }} invited you to join **{{ $householdName }}** on Snuggy Parking, so you can see where the household car is parked.

<x-mail::button :url="$url">
Open the invite
</x-mail::button>

Sign in with the Google account for this email address to accept. The invite expires on {{ $expiresAt }}.
</x-mail::message>
