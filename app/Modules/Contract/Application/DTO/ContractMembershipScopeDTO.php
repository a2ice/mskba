<?php

namespace App\Modules\Contract\Application\DTO;

final readonly class ContractMembershipScopeDTO
{
    public function __construct(
        public string $type,
        public string $typeLabel,
        public int|string $id,
        public string $name,
        public ?string $url,
        public string $permissions,
    ) {}

    /**
     * @return array{type: string, type_label: string, id: int|string, name: string, url: ?string, permissions: string}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'type_label' => $this->typeLabel,
            'id' => $this->id,
            'name' => $this->name,
            'url' => $this->url,
            'permissions' => $this->permissions,
        ];
    }
}
