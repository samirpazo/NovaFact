<?php

namespace App\Enums;

enum DebitNoteReason: string
{
    case LateInterest = '01';
    case ValueIncrease = '02';
    case PenaltiesOrOther = '03';

    public function description(): string
    {
        return match ($this) {
            self::LateInterest => 'Intereses por mora',
            self::ValueIncrease => 'Aumento en el valor',
            self::PenaltiesOrOther => 'Penalidades / otros conceptos',
        };
    }
}
