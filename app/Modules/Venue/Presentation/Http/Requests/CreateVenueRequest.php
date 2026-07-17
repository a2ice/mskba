<?php

namespace App\Modules\Venue\Presentation\Http\Requests;

use App\Modules\Identity\Application\Services\CurrentActorResolver;
use App\Modules\Location\Application\DTO\CreateLocationDTO;
use App\Modules\Venue\Application\Services\VenueUniquenessChecker;
use App\Modules\Venue\Domain\Enums\VenueStatusEnum;
use App\Modules\Venue\Domain\Enums\VenueTypeEnum;
use App\Modules\Venue\Presentation\Http\Requests\Concerns\InteractsWithVenueTags;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateVenueRequest extends FormRequest
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
        $telegramRequired = $this->boolean('telegram_flow') ? ['required'] : ['nullable'];
        $telegramAddressSelected = $this->boolean('telegram_flow') ? ['required', 'accepted'] : ['nullable'];

        return [
            'telegram_flow' => ['sometimes', 'accepted'],
            'name' => ['required', 'string', 'min:3', 'max:255'],
            'type' => ['required', Rule::enum(VenueTypeEnum::class)],
            'is_free' => ['sometimes', 'boolean'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'full_description' => ['nullable', 'string', 'max:10000'],
            'tags' => ['nullable', 'string', 'max:1000'],
            'raw_address' => ['nullable', 'string', 'max:1000'],
            'location' => ['nullable', 'array'],
            'location.raw_address' => [...$telegramRequired, 'string', 'max:1000'],
            'location.address_selected' => $telegramAddressSelected,
            'location.city' => ['nullable', 'string', 'max:255'],
            'location.street' => ['nullable', 'string', 'max:255'],
            'location.building' => ['nullable', 'string', 'max:255'],
            'location.postal_code' => ['nullable', 'string', 'max:32'],
            'location.latitude' => [...$telegramRequired, 'numeric', 'between:-90,90'],
            'location.longitude' => [...$telegramRequired, 'numeric', 'between:-180,180'],
            'location.metro_station_ids' => ['nullable', 'array'],
            'location.metro_station_ids.*' => ['integer', 'distinct', 'exists:metro_stations,id'],
        ];
    }

    public function withValidator($validator): void
    {
        $this->addVenueTagValidation($validator);

        $validator->after(function ($validator): void {
            $checker = app(VenueUniquenessChecker::class);
            $name = $this->nullableInputString('name');
            $type = VenueTypeEnum::tryFrom((string) $this->input('type'));

            if ($name !== null && $type !== null && $checker->aliasExistsForName($name, $type, [VenueStatusEnum::CONFIRMED])) {
                $validator->errors()->add('name', 'Площадка с таким названием уже существует.');
            }

            $actor = app(CurrentActorResolver::class)->resolveForRequest($this);

            if (
                $actor !== null
                && $name !== null
                && $type !== null
                && $checker->aliasExistsForActor(
                    $actor,
                    $checker->aliasForName($name),
                    $type,
                    [VenueStatusEnum::UNCONFIRMED, VenueStatusEnum::BLOCKED],
                )
            ) {
                $validator->errors()->add('name', 'Вы уже добавили площадку с таким названием.');
            }

            if ($checker->addressExists(
                rawAddress: $this->inputAddress(),
                city: $this->nullableInputString('location.city'),
                street: $this->nullableInputString('location.street'),
                building: $this->nullableInputString('location.building'),
                type: $type,
                statuses: [VenueStatusEnum::CONFIRMED],
            )) {
                $validator->errors()->add($this->addressErrorField(), 'Площадка с таким адресом уже существует.');
            }

            if (
                $actor !== null
                && $type !== null
                && $checker->addressExistsForActor(
                    actor: $actor,
                    rawAddress: $this->inputAddress(),
                    city: $this->nullableInputString('location.city'),
                    street: $this->nullableInputString('location.street'),
                    building: $this->nullableInputString('location.building'),
                    type: $type,
                    statuses: [VenueStatusEnum::UNCONFIRMED, VenueStatusEnum::BLOCKED],
                )
            ) {
                $validator->errors()->add($this->addressErrorField(), 'Вы уже добавили площадку с таким адресом.');
            }
        });
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

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'название',
            'type' => 'тип площадки',
            'short_description' => 'краткое описание',
            'full_description' => 'полное описание',
            'tags' => 'теги',
            'is_free' => 'свободный доступ',
            'raw_address' => 'адрес',
            'location.raw_address' => 'адрес',
            'location.address_selected' => 'адрес из подсказки',
            'location.city' => 'город',
            'location.street' => 'улица',
            'location.building' => 'дом',
            'location.postal_code' => 'почтовый индекс',
            'location.latitude' => 'широта',
            'location.longitude' => 'долгота',
            'location.metro_station_ids' => 'ближайшее метро',
            'location.metro_station_ids.*' => 'станция метро',
        ];
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function nullableInputString(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) !== ''
            ? trim($value)
            : null;
    }

    private function inputAddress(): ?string
    {
        return $this->nullableInputString('location.raw_address')
            ?? $this->nullableInputString('raw_address');
    }

    private function addressErrorField(): string
    {
        return $this->has('location')
            ? 'location.raw_address'
            : 'raw_address';
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
            ->unique()
            ->values()
            ->all();
    }
}
