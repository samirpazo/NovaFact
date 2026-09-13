<?php

namespace App\Enums;

enum DocumentType: string
{
    case Invoice = '01';
    case Receipt = '03';
    case CreditNote = '07';
    case DebitNote = '08';

    public function isNote(): bool
    {
        return in_array($this, [self::CreditNote, self::DebitNote], true);
    }
}
