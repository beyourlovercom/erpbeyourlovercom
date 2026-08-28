<?php

namespace App\Modules\Identity\Application;

enum RolePreset: string
{
    case Logistics = 'logistics';
    case SalesRepresentative = 'sales_representative';
    case KolSpecialist = 'kol_specialist';
    case Finance = 'finance';
    case Operations = 'operations';
    case Writer = 'writer';
    case Administrator = 'administrator';

    public function roleKey(): string
    {
        return 'role_preset_'.$this->value;
    }

    public function label(): string
    {
        return match ($this) {
            self::Logistics => '物流',
            self::SalesRepresentative => '业务员',
            self::KolSpecialist => '网红专员',
            self::Finance => '财务',
            self::Operations => '运营',
            self::Writer => '写手',
            self::Administrator => '管理员',
        };
    }

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::Logistics => ['dashboard.view', 'orders.view', 'logistics.view', 'logistics.operate', 'sync.view', 'sync.operate'],
            self::SalesRepresentative => ['dashboard.view', 'customers.view', 'customers.operate', 'orders.view', 'orders.create', 'orders.edit'],
            self::KolSpecialist => ['dashboard.view', 'kol.view', 'kol.operate', 'orders.view', 'orders.create', 'orders.edit'],
            self::Finance => ['dashboard.view', 'orders.view', 'accounting.view', 'analysis.view'],
            self::Operations => ['dashboard.view', 'customers.view', 'customers.operate', 'orders.view', 'orders.create', 'orders.edit', 'logistics.view', 'sync.view', 'analysis.view', 'inventory.view', 'procurement.view', 'products.view', 'kol.view', 'kol.operate', 'kol.views.manage'],
            self::Writer => ['dashboard.view', 'guides.view', 'guides.manage'],
            self::Administrator => self::allPermissionKeys(),
        };
    }

    /** @return list<string> */
    public static function allPermissionKeys(): array
    {
        $keys = array_values(array_filter(array_map(
            fn (AuthorizationKeys $key): ?string => str_contains($key->value, '.') ? $key->value : null,
            AuthorizationKeys::cases(),
        )));
        sort($keys);

        return $keys;
    }

    public static function fromRoleKey(?string $roleKey): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->roleKey() === $roleKey) {
                return $case;
            }
        }

        return null;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $case): array => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
