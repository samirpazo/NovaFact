<?php

namespace App\Enums;

enum DespatchReason: string
{
    case Sale = '01';
    case Purchase = '02';
    case TransferBetweenEstablishments = '04';
    case Return = '09';
    case Import = '08';
    case Export = '10';
    case Other = '13';
    case NoCustomsDestination = '19';
}
