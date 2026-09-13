<?php

namespace App\Support;

use InvalidArgumentException;

final class DecimalAmount
{
    public static function normalize(mixed $value, int $scale = 2): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        $fractionPattern = $scale > 0 ? '(?:\.([0-9]{1,'.$scale.'}))?' : '';
        if (! is_string($value) || $scale < 0 || $scale > 6
            || ! preg_match('/^(0|[1-9][0-9]{0,11})'.$fractionPattern.'$/D', $value, $matches)) {
            throw new InvalidArgumentException("Amount must be a non-negative decimal string or integer with at most $scale decimal places.");
        }
        $fraction = str_pad($matches[2] ?? '', $scale, '0');

        $whole = ltrim($matches[1], '0') ?: '0';

        return $scale > 0 ? $whole.'.'.$fraction : $whole;
    }

    public static function minorUnits(mixed $value): int
    {
        $normalized = self::normalize($value, 2);
        [$whole, $fraction] = explode('.', $normalized);
        if (strlen($whole) > 12) {
            throw new InvalidArgumentException('Amount exceeds the supported monetary range.');
        }

        return ((int) $whole * 100) + (int) $fraction;
    }

    public static function fromMinorUnits(int $minorUnits): string
    {
        if ($minorUnits < 0) {
            throw new InvalidArgumentException('Amount cannot be negative.');
        }

        return intdiv($minorUnits, 100).'.'.str_pad((string) ($minorUnits % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function add(mixed ...$values): string
    {
        $total = 0;
        foreach ($values as $value) {
            $units = self::minorUnits($value);
            if ($total > PHP_INT_MAX - $units) {
                throw new InvalidArgumentException('Amount exceeds the supported monetary range.');
            }
            $total += $units;
        }

        return self::fromMinorUnits($total);
    }

    public static function percentageMinorUnits(mixed $base, mixed $percentage): int
    {
        $baseUnits = self::minorUnits($base);
        $rate = self::normalize($percentage, 4);
        [$whole, $fraction] = explode('.', $rate);
        $rateUnits = ((int) $whole * 10_000) + (int) $fraction;
        if ($rateUnits > 1_000_000) {
            throw new InvalidArgumentException('Percentage cannot exceed 100.');
        }
        $wholeResult = intdiv($baseUnits, 1_000_000) * $rateUnits;
        $remainderNumerator = ($baseUnits % 1_000_000) * $rateUnits;

        return $wholeResult + intdiv($remainderNumerator + 500_000, 1_000_000);
    }
}
