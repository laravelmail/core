<?php

declare(strict_types=1);

namespace LaravelMail\Support;

final class PhoneNumber
{
    public const REGEX = '/^\+[1-9][0-9]{6,14}$/';

    public static function normalize(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $clean = preg_replace('/[\s().\-]/', '', trim($value));
        if (str_starts_with($clean, '00')) {
            $clean = '+'.substr($clean, 2);
        }

        return $clean;
    }

    public static function isValid(?string $value): bool
    {
        $n = self::normalize($value);

        return $n !== null && preg_match(self::REGEX, $n) === 1;
    }
}
