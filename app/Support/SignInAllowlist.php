<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * TEMPORARY: only the emails in config services.google.allowed_emails may sign in; "*" allows
 * anyone, and an empty list nobody. Delete this class, its two callers and the config key to remove it.
 */
class SignInAllowlist
{
    public static function allows(string $email): bool
    {
        $allowed = (array) config('services.google.allowed_emails');

        return in_array('*', $allowed, true) || in_array(Str::lower($email), $allowed, true);
    }
}
