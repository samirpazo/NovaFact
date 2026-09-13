<?php

namespace App\Enums;

enum CreditNoteReason: string
{
    case OperationCancellation = '01';
    case WrongRuc = '02';
    case DescriptionCorrection = '03';
    case GlobalDiscount = '04';
    case ItemDiscount = '05';
    case TotalReturn = '06';
    case ItemReturn = '07';
    case Bonus = '08';
    case ValueDecrease = '09';
    case OtherConcepts = '10';
    case ExportAdjustment = '11';
    case IvapAdjustment = '12';
    case PaymentTermsCorrection = '13';

    public function description(): string
    {
        return match ($this) {
            self::OperationCancellation => 'Anulación de la operación',
            self::WrongRuc => 'Anulación por error en el RUC',
            self::DescriptionCorrection => 'Corrección por error en la descripción',
            self::GlobalDiscount => 'Descuento global',
            self::ItemDiscount => 'Descuento por ítem',
            self::TotalReturn => 'Devolución total',
            self::ItemReturn => 'Devolución por ítem',
            self::Bonus => 'Bonificación',
            self::ValueDecrease => 'Disminución en el valor',
            self::OtherConcepts => 'Otros conceptos',
            self::ExportAdjustment => 'Ajustes de operaciones de exportación',
            self::IvapAdjustment => 'Ajustes afectos al IVAP',
            self::PaymentTermsCorrection => 'Corrección del monto neto pendiente de pago y/o fechas de vencimiento',
        };
    }

    public function requiresOriginalTotal(): bool
    {
        return in_array($this, [self::OperationCancellation, self::WrongRuc, self::DescriptionCorrection, self::TotalReturn], true);
    }

    public function supportedByCurrentTaxContract(): bool
    {
        return ! in_array($this, [self::Bonus, self::ExportAdjustment, self::IvapAdjustment, self::PaymentTermsCorrection], true);
    }
}
