<?php

namespace App\Support;

class BenefitCategory
{
    public static function matches(?string $categories, string $benefit): bool
    {
        $benefit = trim($benefit);
        if (strcasecmp($benefit, 'All residents') === 0) {
            return true;
        }

        if ($benefit === '') {
            return false;
        }

        foreach (explode(',', (string) $categories) as $category) {
            if (strcasecmp(trim($category), $benefit) === 0) {
                return true;
            }
        }

        return false;
    }
}
