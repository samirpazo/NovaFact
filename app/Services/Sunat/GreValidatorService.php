<?php

namespace App\Services\Sunat;

use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

/**
 * GreValidatorService - Validador de Guías de Remisión 2.0
 * Soporta Guía de Remitente (09) y Guía de Transportista (31)
 * Basado en SUNAT v20250421_0 (Hoja Guía-Remitente2_0 y Guía-Transportista2_0)
 */
class GreValidatorService
{
    public function validate(array $data)
    {
        $tipoDoc = $data['tipoDoc'] ?? '09';

        // 1. Reglas Base (Comunes para ambos)
        $rules = [
            'tipoDoc' => ['required', 'in:09,31'],
            'serie' => ['required', 'regex:/^[T|V][A-Z0-9]{3}$/'],
            'correlativo' => ['required', 'numeric', 'min:1', 'max:99999999'],
            'fechaEmision' => ['required', 'date_format:Y-m-d'],
            // 'horaEmision' => ['required', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/'],
            'motivoTraslado' => ['required', 'string', 'size:2'],
            'fechaTraslado' => ['required', 'date_format:Y-m-d'],
            'pesoTotal' => ['required', 'numeric', 'gt:0'],
            'unidadMedida' => ['required', 'in:KGM,TNE'],
            
            // Destinatario
            'destinatarioTipoDoc' => ['required', 'in:1,4,6,7,0,A'],
            'destinatarioNumDoc' => ['required', 'string', 'min:1', 'max:15'],
            'destinatarioRznSocial' => ['required', 'string', 'max:250'],
            
            // Ubicaciones
            'ubigeoPartida' => ['required', 'digits:6'],
            'direccionPartida' => ['required', 'string', 'min:3', 'max:500'],
            'ubigeoLlegada' => ['required', 'digits:6'],
            'direccionLlegada' => ['required', 'string', 'min:3', 'max:500'],

            // Detalle
            'details' => ['required', 'array', 'min:1'],
        ];

        $messages = [
            'serie.regex' => '1001|ID - La serie no cumple con el formato [T|V][A-Z0-9]{3}',
            'correlativo.max' => '1002|ID - El correlativo no puede ser mayor a 8 dígitos',
            'fechaEmision.date_format' => '3436|El campo de fecha de emision no cumple con el formato establecido (YYYY-MM-DD)',
            'horaEmision.regex' => '3438|El campo de hora de emision no cumple con el formato establecido (hh:mm:ss)',
            'pesoTotal.gt' => '2523|El peso bruto total debe ser mayor a cero.',
            'details.min' => '3450|El documento debe tener al menos un ítem.',
            'ubigeoPartida.digits' => '2578|El ubigeo de partida debe tener 6 dígitos.',
            'ubigeoLlegada.digits' => '2575|El ubigeo de llegada debe tener 6 dígitos.',
        ];

        $validator = Validator::make($data, $rules, $messages);
        $errors = $validator->fails() ? $validator->errors()->all() : [];

        // --- LÓGICA DE NEGOCIO Y CONDICIONALES ---
        $fechaEmision = Carbon::parse($data['fechaEmision']);
        $fechaTraslado = Carbon::parse($data['fechaTraslado']);
        $hoy = Carbon::today('America/Lima');

        // [Global] Fecha de Emisión vs Hoy
        if ($fechaEmision->clone()->addDays(1)->lt($hoy)) {
            $errors[] = "2108|Presentación fuera de fecha (Máximo 1 día de antigüedad).";
        }
        if ($fechaEmision->isFuture()) {
            $errors[] = "2329|La fecha de emisión no puede ser posterior a la fecha actual.";
        }
        if ($fechaTraslado->lt($fechaEmision)) {
            $errors[] = "2521|La fecha de inicio de traslado no puede ser anterior a la emisión.";
        }

        if ($tipoDoc === '09') {
            // ==========================================
            // REGLAS ESPECÍFICAS: GUÍA REMITENTE (09)
            // ==========================================
            
            if (empty($data['modalidadTraslado'])) {
                $errors[] = "2532|No existe información de modalidad de transporte.";
            }

            // A. Modalidad Pública (01)
            if (($data['modalidadTraslado'] ?? '') === '01') {
                if (empty($data['transportistaNumDoc'])) {
                    $errors[] = "2558|En Modalidad Pública, el RUC del transportista es obligatorio.";
                }
                if (empty($data['transportistaRznSocial'])) {
                    $errors[] = "2559|En Modalidad Pública, la Razón Social del transportista es obligatoria.";
                }
            }

            // B. Modalidad Privada (02)
            if (($data['modalidadTraslado'] ?? '') === '02') {
                if (empty($data['placaVehiculo'])) {
                    $errors[] = "2566|En Modalidad Privada, la Placa del vehículo es obligatoria.";
                }
                if (empty($data['choferNumDoc'])) {
                    $errors[] = "2570|En Modalidad Privada, el documento del conductor es obligatorio.";
                }
            }

            // C. Motivos Especiales
            if ($data['motivoTraslado'] === '13' && empty($data['descripcionMotivoTraslado'])) {
                $errors[] = "3457|Si el motivo es 'Otros', debe ingresar descripción detallada.";
            }

        } else if ($tipoDoc === '31') {
            // ==========================================
            // REGLAS ESPECÍFICAS: GUÍA TRANSPORTISTA (31)
            // ==========================================

            // 1. Remitente (Tercero solicitante del servicio) - Obligatorio
            if (empty($data['remitenteNumDoc']) || empty($data['remitenteRznSocial'])) {
                $errors[] = "2544|En Guía de Transportista, los datos del Remitente son obligatorios.";
            }

            // 2. Datos de Vehículo y Chofer - Siempre Obligatorios
            if (empty($data['placaVehiculo'])) {
                $errors[] = "2566|En Guía de Transportista, el número de placa es obligatorio.";
            }
            if (empty($data['choferNumDoc'])) {
                $errors[] = "2570|En Guía de Transportista, el documento del chofer es obligatorio.";
            }

            // 3. Documento Relacionado (Guía de Remitente 09 o Factura 01)
            $relatedDocs = $data['relatedDocs'] ?? [];
            $hasRemitenteGuide = false;
            foreach ($relatedDocs as $doc) {
                if (in_array($doc['tipo'], ['09', '01'])) {
                    $hasRemitenteGuide = true;
                    break;
                }
            }
            if (!$hasRemitenteGuide) {
                $errors[] = "2534|Debe consignar el documento relacionado (Guía de Remitente o Factura).";
            }

            // 4. Transportista MTC (Opcional pero recomendado validar si existe)
            if (!empty($data['transportistaNroMtc']) && strlen($data['transportistaNroMtc']) > 20) {
                $errors[] = "2564|El número de registro MTC no puede exceder los 20 caracteres.";
            }
        }

        // --- VALIDACIONES COMUNES FINALES ---
        
        // Validación de Placa (Formato: Solo números y letras mayúsculas)
        if (!empty($data['placaVehiculo'])) {
            if (!preg_match('/^[A-Z0-9]+$/', $data['placaVehiculo'])) {
                $errors[] = "2567|El formato del número de placa '{$data['placaVehiculo']}' es incorrecto (solo debe contener letras mayúsculas y números).";
            }
        }
        
        // Motivos ADUANEROS (Exportación/Importación)
        if (in_array($data['motivoTraslado'], ['08', '09', '19'])) {
            $hasDam = false;
            foreach ($data['relatedDocs'] ?? [] as $doc) {
                if (in_array($doc['tipo'], ['50', '52'])) $hasDam = true;
            }
            if (!$hasDam) {
                $errorCode = ($data['motivoTraslado'] === '19') ? '3493' : '3440';
                $errors[] = "{$errorCode}|Debe consignar DAM o DS para este motivo aduanero.";
            }
        }

        if (count($errors) > 0) {
            return [
                'success' => false,
                'errors' => array_values(array_unique($errors))
            ];
        }

        return ['success' => true];
    }
}
