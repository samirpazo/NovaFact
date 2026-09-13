<?php

namespace App\Support;

use InvalidArgumentException;

final class DecimalMeasure
{
    public static function normalize(mixed $value, int $scale = 6, bool $positive = true): string
    {
        if (is_int($value)) {
            $value = (string) $value;
        }
        if (! is_string($value) || $scale < 0 || $scale > 6
            || ! preg_match('/^(0|[1-9][0-9]{0,11})(?:\.([0-9]{1,'.$scale.'}))?$/D', $value, $matches)) {
            throw new InvalidArgumentException("Measure must be an exact decimal string or integer with at most {$scale} decimal places.");
        }
        $whole = ltrim($matches[1], '0') ?: '0';
        $fraction = str_pad($matches[2] ?? '', $scale, '0');
        $normalized = $scale ? $whole.'.'.$fraction : $whole;
        if ($positive && preg_match('/^0(?:\.0+)?$/', $normalized)) {
            throw new InvalidArgumentException('Measure must be greater than zero.');
        }

        return $normalized;
    }
}
