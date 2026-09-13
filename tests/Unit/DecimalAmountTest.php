<?php

use App\Support\DecimalAmount;

it('converts exact decimal money to minor units without float arithmetic', function (string|int $value, int $expected) {
    expect(DecimalAmount::minorUnits($value))->toBe($expected);
})->with([
    ['0.01', 1], ['0.10', 10], ['10.10', 1010], ['1000.00', 100000], ['999999999.99', 99999999999],
]);

it('adds and rounds percentage results deterministically in integers', function () {
    expect(DecimalAmount::add('0.10', '0.20'))->toBe('0.30')
        ->and(DecimalAmount::percentageMinorUnits('0.05', '10.0000'))->toBe(1)
        ->and(DecimalAmount::percentageMinorUnits('100.00', '18'))->toBe(1800)
        ->and(DecimalAmount::percentageMinorUnits('999999999999.99', '100'))->toBe(99999999999999);
});

it('rejects floats ambiguous formats and excess monetary precision', function (mixed $value) {
    expect(fn () => DecimalAmount::minorUnits($value))->toThrow(InvalidArgumentException::class);
})->with([[0.1], ['1.001'], ['1,00'], [' 1.00'], ['01.00'], ['1e2'], [-1]]);
