<?php

namespace App\Support;

class AccountRole
{
    public static function normalize(string $role): ?string
    {
        return match (strtolower(trim($role))) {
            'admin', 'super admin', 'superadmin' => 'admin',
            'barangay', 'staff' => 'barangay',
            'secretary', 'barangay secretary' => 'secretary',
            'assistance officer', 'assistance_officer' => 'assistance_officer',
            'distribution staff', 'distribution_staff' => 'distribution_staff',
            'read-only auditor', 'read only auditor', 'auditor' => 'auditor',
            'user', 'resident', 'resident account' => 'user',
            default => null,
        };
    }

    public static function storedName(string $normalized): string
    {
        return match ($normalized) {
            'admin' => 'Admin',
            'barangay' => 'Barangay',
            'secretary' => 'Secretary',
            'assistance_officer' => 'Assistance Officer',
            'distribution_staff' => 'Distribution Staff',
            'auditor' => 'Read-only Auditor',
            default => 'User',
        };
    }
}
