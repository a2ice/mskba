<?php

namespace App\Modules\SportsSection\Application\UseCases;

use App\Modules\Contact\Domain\Enums\ContactTypeEnum;
use App\Modules\Contact\Domain\Models\Contact;
use App\Modules\Contact\Domain\ValueObjects\ContactValue;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\SportsSection\Application\Services\SportsSectionAccess;
use App\Modules\SportsSection\Domain\Enums\SportsSectionPermissionEnum;
use App\Modules\SportsSection\Domain\Exceptions\SportsSectionException;
use App\Modules\SportsSection\Domain\Models\SportsSection;

final readonly class ManageSportsSectionContactHandler
{
    public function __construct(private SportsSectionAccess $access) {}

    /** @param array<string, mixed> $data */
    public function store(SportsSection $section, User $issuer, array $data): Contact
    {
        $this->authorize($section, $issuer);
        $type = ContactTypeEnum::from($data['type']);
        $value = (new ContactValue($type, $data['value']))->value();

        return $section->contacts()->create([
            'type' => $type,
            'value' => $value,
            'label' => $data['label'] ?? null,
            'is_primary' => false,
            'is_public' => true,
        ]);
    }

    public function delete(SportsSection $section, Contact $contact, User $issuer): void
    {
        $this->authorize($section, $issuer);
        if ($contact->contactable_type !== 'sports_section' || $contact->contactable_id !== $section->id) {
            throw new SportsSectionException('Контакт не относится к этой секции.');
        }
        $contact->delete();
    }

    private function authorize(SportsSection $section, User $issuer): void
    {
        if (! $this->access->allows($issuer->canonical(), $section, SportsSectionPermissionEnum::MANAGE_CONTACTS)) {
            throw new SportsSectionException('Недостаточно прав для управления контактами секции.');
        }
    }
}
