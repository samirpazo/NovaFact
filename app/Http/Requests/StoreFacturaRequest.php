<?php

namespace App\Http\Requests;

use App\Enums\DocumentType;
use App\Support\DecimalAmount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreFacturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipoDoc' => ['required', Rule::enum(DocumentType::class)],
            'external_reference' => ['nullable', 'string', 'max:150', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'establishment' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'serie' => ['nullable', 'regex:/^[FB][A-Z0-9]{3}$/'],
            'correlativo' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'fechaEmision' => ['required', 'date'],
            'tipoMoneda' => ['required', 'in:PEN,USD'],
            'clientTipoDoc' => ['required', 'in:0,1,4,6,7,A'],
            'clientNumDoc' => ['required', 'string', 'max:15'],
            'clientRznSocial' => ['required', 'string', 'max:250'],
            'mtoOperGravada' => ['required', 'numeric', 'min:0'],
            'mtoIGV' => ['required', 'numeric', 'min:0'],
            'mtoTotal' => ['required', 'numeric', 'gt:0'],
            // Opcional para mantener compatibilidad con clientes antiguos; las
            // emisiones de Nova lo envían para evitar depender de una config global desactualizada.
            'igvRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'sumDsctoGlobal' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.codigo' => ['nullable', 'string', 'max:100'],
            'items.*.descripcion' => ['required', 'string', 'max:500'],
            'items.*.unidad' => ['nullable', 'string', 'size:3'],
            'items.*.cantidad' => ['required', 'numeric', 'gt:0'],
            'items.*.mtoBaseIgv' => ['required', 'numeric', 'min:0'],
            'items.*.igv' => ['required', 'numeric', 'min:0'],
            'items.*.mtoValorUnitario' => ['required', 'numeric', 'min:0'],
            'items.*.mtoValorVenta' => ['required', 'numeric', 'min:0'],
            'items.*.mtoPrecioUnitario' => ['required', 'numeric', 'min:0'],
            'reference' => ['required_if:tipoDoc,07,08', 'array'],
            'reference.kind' => ['required_if:tipoDoc,07,08', 'in:internal,external'],
            'reference.document_id' => ['nullable', 'integer', 'min:1', 'required_if:reference.kind,internal'],
            'reference.document_type' => ['nullable', 'in:01,03', 'required_if:reference.kind,external'],
            'reference.series' => ['nullable', 'regex:/^[FB][A-Z0-9]{3}$/', 'required_if:reference.kind,external'],
            'reference.correlative' => ['nullable', 'integer', 'min:1', 'max:99999999', 'required_if:reference.kind,external'],
            'reference.issue_date' => ['nullable', 'date', 'required_if:reference.kind,external'],
            'reference.currency' => ['nullable', 'in:PEN,USD', 'required_if:reference.kind,external'],
            'reference.customer_document_type' => ['nullable', 'in:0,1,4,6,7,A', 'required_if:reference.kind,external'],
            'reference.customer_document_number' => ['nullable', 'string', 'max:15', 'required_if:reference.kind,external'],
            'reference.reason_code' => ['required_if:tipoDoc,07,08', 'string', 'size:2'],
            'reference.reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $d = $this->validated();
            $affectedType = $d['reference']['document_type'] ?? null;
            if (($d['tipoDoc'] ?? null) === DocumentType::Invoice->value && ($d['clientTipoDoc'] ?? null) !== '6') {
                $v->errors()->add('clientTipoDoc', 'La factura requiere cliente con RUC (tipo 6).');
            }
            if (($d['tipoDoc'] ?? null) === DocumentType::Receipt->value && ($d['clientTipoDoc'] ?? null) === '6') {
                $v->errors()->add('clientTipoDoc', 'La boleta no debe emitirse con RUC como documento del cliente.');
            }
            if (DocumentType::tryFrom($d['tipoDoc'] ?? '')?->isNote() && $affectedType === DocumentType::Invoice->value && ($d['clientTipoDoc'] ?? null) !== '6') {
                $v->errors()->add('clientTipoDoc', 'Una nota sobre factura requiere adquirente con RUC.');
            }
            if (! $d || ! isset($d['items'])) {
                return;
            }
            if (DocumentType::tryFrom($d['tipoDoc'] ?? '')?->isNote()) {
                try {
                    $base = 0;
                    $igv = 0;
                    $rate = $d['igvRate'] ?? 18;
                    foreach ($d['items'] as $index => $item) {
                        $lineBase = DecimalAmount::minorUnits($item['mtoBaseIgv']);
                        $lineIgv = DecimalAmount::minorUnits($item['igv']);
                        if (DecimalAmount::percentageMinorUnits($item['mtoBaseIgv'], $rate) !== $lineIgv) {
                            $v->errors()->add("items.$index.igv", 'El IGV de la línea no coincide con su base y tasa.');
                        }
                        DecimalAmount::normalize($item['cantidad'], 6);
                        DecimalAmount::normalize($item['mtoValorUnitario'], 6);
                        DecimalAmount::normalize($item['mtoPrecioUnitario'], 6);
                        $base += $lineBase;
                        $igv += $lineIgv;
                    }
                    if ($base !== DecimalAmount::minorUnits($d['mtoOperGravada'])) {
                        $v->errors()->add('mtoOperGravada', 'No coincide exactamente con la suma de bases del detalle.');
                    }
                    if ($igv !== DecimalAmount::minorUnits($d['mtoIGV'])) {
                        $v->errors()->add('mtoIGV', 'No coincide exactamente con la suma del IGV del detalle.');
                    }
                    if ($base + $igv !== DecimalAmount::minorUnits($d['mtoTotal'])) {
                        $v->errors()->add('mtoTotal', 'No coincide exactamente con base gravada + IGV.');
                    }
                } catch (\InvalidArgumentException $exception) {
                    $v->errors()->add('mtoTotal', 'Los importes de notas deben enviarse como enteros o strings decimales exactos con máximo 2 decimales; cantidades y precios unitarios permiten 6.');
                }

                return;
            }
            $base = round(array_sum(array_map(fn ($i) => (float) $i['mtoBaseIgv'], $d['items'])), 2);
            $igv = round(array_sum(array_map(fn ($i) => (float) $i['igv'], $d['items'])), 2);
            $total = round($base + $igv, 2);
            $rate = isset($d['igvRate']) ? (float) $d['igvRate'] : 18.0;
            foreach ($d['items'] as $index => $item) {
                $expected = round(((float) $item['mtoBaseIgv']) * $rate / 100, 2);
                if (abs($expected - (float) $item['igv']) > 0.01) {
                    $v->errors()->add("items.$index.igv", 'El IGV de la línea no coincide con su base y tasa.');
                }
            }
            if (abs($base - (float) ($d['mtoOperGravada'] ?? 0)) > 0.01) {
                $v->errors()->add('mtoOperGravada', 'No coincide con la suma de bases del detalle.');
            }
            if (abs($igv - (float) ($d['mtoIGV'] ?? 0)) > 0.01) {
                $v->errors()->add('mtoIGV', 'No coincide con la suma del IGV del detalle.');
            }
            if (abs($total - (float) ($d['mtoTotal'] ?? 0)) > 0.01) {
                $v->errors()->add('mtoTotal', 'No coincide con base gravada + IGV.');
            }
        });
    }
}
