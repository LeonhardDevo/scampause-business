<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Approver = 'approver';
    case Bookkeeper = 'bookkeeper';
    case Member = 'member';
    case Auditor = 'auditor';

    public function rank(): int
    {
        return match ($this) {
            self::Owner => 100,
            self::Admin => 50,
            self::Approver => 40,
            self::Bookkeeper => 30,
            self::Auditor => 20,
            self::Member => 10,
        };
    }

    public function approvalRank(): ?int
    {
        return match ($this) {
            self::Owner => 3,
            self::Admin => 2,
            self::Approver => 1,
            default => null,
        };
    }

    public function satisfiesApprovalRole(self $required): bool
    {
        $mine = $this->approvalRank();
        $needed = $required->approvalRank();

        return $mine !== null && $needed !== null && $mine >= $needed;
    }

    public function label(): string
    {
        $key = 'roles.'.$this->value;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : ucfirst($this->value);
    }

    /** @return list<string> */
    public function abilities(): array
    {
        /** @var list<string> $catalog */
        $catalog = config('permissions.abilities', []);
        /** @var list<string> $granted */
        $granted = config('permissions.roles.'.$this->value, []);

        if (in_array('*', $granted, true)) {
            return $catalog;
        }

        return array_values(array_intersect($catalog, $granted));
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities(), true);
    }

    public function canManage(self $other): bool
    {
        if ($this === self::Owner) {
            return true;
        }

        return $this->rank() > $other->rank();
    }

    /** @return list<self> */
    public function assignableRoles(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $role): bool => $this === self::Owner || $role->rank() < $this->rank(),
        ));
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(
            fn (self $role): array => ['value' => $role->value, 'label' => $role->label()],
            self::cases(),
        );
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $role): string => $role->value, self::cases());
    }
}
