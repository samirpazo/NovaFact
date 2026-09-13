<?php

namespace App\Services\Documents;

final class PayloadCodec
{
    public static function encode(array $payload): string
    {
        $normalize = function (array $value) use (&$normalize): array {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $item = $normalize($item);
                }
            }

            return $value;
        };

        return json_encode($normalize($payload), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function hash(array $payload): string
    {
        return hash('sha256', self::encode($payload));
    }
}
