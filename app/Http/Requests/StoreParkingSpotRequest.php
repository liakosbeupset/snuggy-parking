<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreParkingSpotRequest extends FormRequest
{
    /**
     * Validation only. parkIn is checked by the controller under the household lock (FM18).
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request. No `exists` rule on household_id:
     * an unknown id and a foreign one both end in the same 403 (I1).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'household_id' => ['required', 'integer'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'accuracy' => ['required', 'numeric', 'between:0,100000'],
            'note' => ['nullable', 'string', 'max:500'],
            'photo' => ['nullable', 'image', 'mimes:jpeg,png,webp', 'max:5120'],
        ];
    }
}
