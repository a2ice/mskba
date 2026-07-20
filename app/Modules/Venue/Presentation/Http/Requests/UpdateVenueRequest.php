<?php

namespace App\Modules\Venue\Presentation\Http\Requests;

use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Presentation\Http\Requests\Concerns\InteractsWithVenueTags;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVenueRequest extends FormRequest
{
    use InteractsWithVenueTags;

    public function authorize(): bool
    {
        return ! ($this->user()?->isBlocked() ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'telegram_flow' => ['sometimes', 'accepted'],
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'type' => ['required', Rule::enum(VenueTypeEnum::class)],
            'short_description' => ['nullable', 'string', 'max:500'],
            'full_description' => ['nullable', 'string', 'max:10000'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'raw_address' => ['nullable', 'string', 'max:1000'],
            'location' => ['sometimes', 'required', 'array'],
            'location.raw_address' => ['required_with:location', 'string', 'max:1000'],
            'location.address_selected' => ['sometimes', 'required_with:location', 'accepted'],
            'location.city' => ['required_with:location', 'string', 'max:255'],
            'location.street' => ['required_with:location', 'string', 'max:255'],
            'location.building' => ['required_with:location', 'string', 'max:255'],
            'location.postal_code' => ['nullable', 'string', 'max:32'],
            'location.latitude' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.longitude' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.metro_station_ids' => ['nullable', 'array'],
            'location.metro_station_ids.*' => ['integer', 'distinct', 'exists:metro_stations,id'],
        ];
    }

    public function locationData(): CreateLocationDTO
    {
        return new CreateLocationDTO(
            rawAddress: $this->nullableString('location.raw_address') ?? $this->nullableString('raw_address'),
            city: $this->nullableString('location.city'),
            street: $this->nullableString('location.street'),
            building: $this->nullableString('location.building'),
            postalCode: $this->nullableString('location.postal_code'),
            latitude: $this->nullableFloat('location.latitude'),
            longitude: $this->nullableFloat('location.longitude'),
            metroStationIds: $this->metroStationIds(),
        );
    }

    public function withValidator($validator): void
    {
        $this->addVenueTagValidation($validator);
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function nullableFloat(string $key): ?float
    {
        $value = $this->validated($key);

        return is_numeric($value)
            ? (float) $value
            : null;
    }

    /**
     * @return array<int>
     */
    private function metroStationIds(): array
    {
        $metroStationIds = $this->validated('location.metro_station_ids');

        if (! is_array($metroStationIds)) {
            return [];
        }

        return collect($metroStationIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }
}
