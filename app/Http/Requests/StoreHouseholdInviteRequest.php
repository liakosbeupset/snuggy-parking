<?php

namespace App\Http\Requests;

use App\Models\Household;
use App\Support\SignInAllowlist;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreHouseholdInviteRequest extends FormRequest
{
    /**
     * Only the owner may invite (AC9).
     */
    public function authorize(): bool
    {
        return $this->user()?->can('invite', $this->household()) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email:rfc',
                'max:255',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $email = Str::lower((string) $value);

                    if ($this->household()->members()->where('email', $email)->exists()) {
                        $fail("{$email} is already a member.");
                    } elseif (! SignInAllowlist::allows($email)) {
                        // TEMPORARY allowlist: an invite they could never accept is refused up front.
                        $fail("{$email} can't sign in to Snuggy Parking yet.");
                    }
                },
            ],
        ];
    }

    public function household(): Household
    {
        /** @var Household */
        return $this->route('household');
    }
}
