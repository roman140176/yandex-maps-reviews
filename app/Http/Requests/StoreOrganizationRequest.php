<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Rules\SupportedReviewSourceUrl;
use Illuminate\Foundation\Http\FormRequest;

final class StoreOrganizationRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'url' => ['required', 'string', 'max:2048', app(SupportedReviewSourceUrl::class)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'url.required' => 'Вставьте ссылку на карточку организации.',
            'url.max' => 'Ссылка слишком длинная.',
        ];
    }

    public function url(): string
    {
        return trim((string) $this->input('url'));
    }
}
