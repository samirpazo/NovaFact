<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFacturaRequest;
use App\Models\Empresa;
use App\Models\McrSeries;
use App\Services\Facturacion\InvoicePdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FacturacionController extends Controller
{
    public function __construct(
        protected \App\Actions\Facturacion\EmitGuiaAction $emitGuiaAction
    ) {}

    public function emitFactura(StoreFacturaRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $context = app(\App\Services\Documents\LegacyAdmissionContextResolver::class)->resolve($request);
        unset($payload['external_reference']);

        return response()->json(app(\App\Services\Documents\AdmitElectronicDocument::class)->execute($context, $payload)->toArray(), 202);
    }

    public function companyConfig(): JsonResponse
    {
        $company = DB::table('McrCompanyConfig')->where('McrIsActive', true)->where('SecStatus', true)->where('McrEnvironment', 'beta')->first();
        if (! $company) {
            return response()->json(['company' => null]);
        }
        $series = DB::table('McrSeries')->where('McrCompanyConfigID', $company->McrCompanyConfigID)
            ->where('SecStatus', true)->orderBy('McrDocumentType')->get()->map(fn ($row) => [
                'document_type' => $row->McrDocumentType,
                'series_code' => $row->McrSeriesCode,
                'next_correlative' => (int) $row->McrNextCorrelative,
                'active' => (bool) $row->McrIsActive,
            ])->values();

        return response()->json(['company' => [
            'id' => $company->McrCompanyConfigID, 'business_name' => $company->McrBusinessName,
            'trade_name' => $company->McrTradeName, 'ruc' => $company->McrRuc, 'address' => $company->McrAddress,
            'phone' => $company->McrPhone, 'email' => $company->McrEmail, 'ubigeo' => $company->McrUbigeo,
            'department' => $company->McrDepartment, 'province' => $company->McrProvince,
            'district' => $company->McrDistrict, 'urbanization' => $company->McrUrbanization,
            'logo_file_id' => $company->McrLogoFilID, 'environment' => $company->McrEnvironment,
            'currency_code' => $company->McrCurrencyCode ?? 'PEN',
            'igv_rate' => (float) ($company->McrIgvRate ?? 18),
            'ipm_rate' => (float) ($company->McrIpmRate ?? 0),
            'total_tax_rate' => (float) ($company->McrTotalTaxRate ?? 18),
            'special_tax_regime' => (bool) ($company->McrSpecialTaxRegime ?? false),
            'has_sol_credentials' => ! empty($company->McrSolUser) && ! empty($company->McrSolPassword),
            'has_certificate' => ! empty($company->McrCertificateName),
            'series' => $series,
        ]]);
    }

    public function updateCompanyConfig(Request $request): JsonResponse
    {
        $data = $request->validate([
            'business_name' => ['required', 'string', 'max:250'], 'ruc' => ['required', 'regex:/^\d{11}$/'],
            'trade_name' => ['nullable', 'string', 'max:250'], 'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:50'], 'email' => ['nullable', 'email', 'max:250'],
            'trade_name' => ['nullable', 'string', 'max:250'],
            'ubigeo' => ['nullable', 'regex:/^\d{6}$/'], 'department' => ['nullable', 'string', 'max:100'],
            'province' => ['nullable', 'string', 'max:100'], 'district' => ['nullable', 'string', 'max:100'],
            'urbanization' => ['nullable', 'string', 'max:150'], 'logo_file_id' => ['nullable', 'integer'],
            'currency_code' => ['required', 'in:PEN,USD'], 'igv_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'ipm_rate' => ['required', 'numeric', 'min:0', 'max:100'], 'total_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'special_tax_regime' => ['required', 'boolean'],
        ]);
        $data['total_tax_rate'] = (float) $data['igv_rate'] + (float) $data['ipm_rate'];
        $company = DB::table('McrCompanyConfig')->where('McrIsActive', true)->where('SecStatus', true)->where('McrEnvironment', 'beta')->first();
        if (! $company) {
            return response()->json(['message' => 'No hay una empresa Beta activa configurada.'], 422);
        }
        DB::table('McrCompanyConfig')->where('McrCompanyConfigID', $company->McrCompanyConfigID)->update([
            'McrBusinessName' => $data['business_name'], 'McrRuc' => $data['ruc'], 'McrTradeName' => $data['trade_name'] ?? null,
            'McrAddress' => $data['address'] ?? null, 'McrPhone' => $data['phone'] ?? null, 'McrEmail' => $data['email'] ?? null,
            'McrUbigeo' => $data['ubigeo'] ?? null, 'McrDepartment' => $data['department'] ?? null,
            'McrProvince' => $data['province'] ?? null, 'McrDistrict' => $data['district'] ?? null,
            'McrUrbanization' => $data['urbanization'] ?? null, 'McrLogoFilID' => $data['logo_file_id'] ?? null,
            'McrCurrencyCode' => $data['currency_code'], 'McrIgvRate' => $data['igv_rate'],
            'McrIpmRate' => $data['ipm_rate'], 'McrTotalTaxRate' => $data['total_tax_rate'],
            'McrSpecialTaxRegime' => $data['special_tax_regime'],
            'UpdateDate' => now(),
        ]);

        return $this->companyConfig();
    }

    public function updateSolCredentials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sol_user' => ['required', 'string', 'max:100'],
            'sol_password' => ['required', 'string', 'min:4', 'max:250'],
        ]);
        $company = Empresa::where('McrIsActive', true)->where('SecStatus', true)->where('McrEnvironment', 'beta')->first();
        if (! $company) {
            return response()->json(['message' => 'No hay una empresa Beta activa configurada.'], 422);
        }
        $company->McrSolUser = $data['sol_user'];
        $company->McrSolPassword = $data['sol_password'];
        $company->UpdateDate = now();
        $company->save();

        return response()->json(['success' => true, 'has_sol_credentials' => true]);
    }

    public function updateCertificate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'certificate' => ['required', 'file', 'mimes:pfx,p12', 'max:4096'],
            'certificate_password' => ['required', 'string', 'max:250'],
        ]);
        $company = Empresa::where('McrIsActive', true)->where('SecStatus', true)->where('McrEnvironment', 'beta')->first();
        if (! $company) {
            return response()->json(['message' => 'No hay una empresa Beta activa configurada.'], 422);
        }
        $file = $request->file('certificate');
        $name = Str::uuid().'.'.$file->extension();
        $file->storeAs('certificates', $name, 'local');
        $company->McrCertificateName = $name;
        $company->McrCertificatePassword = $data['certificate_password'];
        $company->UpdateDate = now();
        $company->save();

        return response()->json(['success' => true, 'has_certificate' => true]);
    }

    public function updateSeries(Request $request): JsonResponse
    {
        $data = $request->validate(['series' => ['required', 'array', 'min:1'], 'series.*.document_type' => ['required', 'in:01,03'], 'series.*.series_code' => ['required', 'regex:/^[FB][A-Z0-9]{3}$/'], 'series.*.next_correlative' => ['required', 'integer', 'min:1'], 'series.*.active' => ['required', 'boolean']]);
        $company = Empresa::where('McrIsActive', true)->where('SecStatus', true)->where('McrEnvironment', 'beta')->first();
        if (! $company) {
            return response()->json(['message' => 'No hay una empresa Beta activa configurada.'], 422);
        }
        DB::transaction(function () use ($data, $company) {
            foreach ($data['series'] as $item) {
                $maxUsed = (int) DB::table('McrDocument')->where('McrCompanyConfigID', $company->getKey())->where('McrDocumentType', $item['document_type'])->where('McrSeriesCode', $item['series_code'])->max('McrCorrelative');
                abort_if($item['next_correlative'] <= $maxUsed, 422, 'El próximo correlativo debe ser mayor al último comprobante emitido.');
                $series = McrSeries::firstOrNew(['McrCompanyConfigID' => $company->getKey(), 'McrDocumentType' => $item['document_type'], 'McrSeriesCode' => $item['series_code']]);
                if (! $series->exists) {
                    $series->CreateUserId = 0;
                    $series->CreateDate = now();
                }
                $series->McrNextCorrelative = $item['next_correlative'];
                $series->McrIsActive = $item['active'];
                $series->SecStatus = true;
                $series->UpdateUserId = 0;
                $series->UpdateDate = now();
                $series->save();
            }
        });

        return $this->companyConfig();
    }

    public function updateCompanyLogo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512']]);
        $company = DB::table('McrCompanyConfig')->where('McrIsActive', true)->where('SecStatus', true)->where('McrEnvironment', 'beta')->first();
        if (! $company) {
            return response()->json(['message' => 'No hay una empresa Beta activa configurada.'], 422);
        }
        $file = $request->file('logo');
        $path = $file->storeAs('facturacion/logo', 'company-logo.'.$file->extension(), 'local');
        DB::table('McrCompanyConfig')->where('McrCompanyConfigID', $company->McrCompanyConfigID)->update(['McrLogoPath' => $path, 'UpdateDate' => now()]);

        return response()->json(['success' => true]);
    }

    public function ticket80mm(int $documentId, InvoicePdfService $pdfService)
    {
        $document = DB::table('McrDocument')->where('McrDocumentID', $documentId)->whereIn('McrStatus', ['accepted', 'accepted_with_observations'])->first();
        if (! $document) {
            return response()->json(['message' => 'Comprobante no encontrado'], 404);
        }
        $lines = DB::table('McrDocumentLine')->where('McrDocumentID', $documentId)->orderBy('McrLineNumber')->get();
        $company = DB::table('McrCompanyConfig')->where('McrCompanyConfigID', $document->McrCompanyConfigID)->first();

        return response($pdfService->generateTicketFromStored($document, $lines, $company), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$document->McrSeriesCode.'-'.$document->McrCorrelative.'-80mm.pdf"',
        ]);
    }

    public function emitGuia(Request $request): JsonResponse
    {
        $result = $this->emitGuiaAction->execute($request->all());

        if (! $result['success']) {
            return response()->json($result, 400);
        }

        return response()->json($result);
    }

    public function consultarHistorialGuia(string $ticket, \App\Services\Sunat\SunatRestService $restService): JsonResponse
    {
        try {
            $status = $restService->getStatus($ticket);

            if ($status['success']) {
                $response = [
                    'success' => true,
                    'cod_respuesta' => $status['cod_respuesta'],
                    'message' => 'Historial consultado exitosamente',
                ];

                if (! empty($status['arc_cdr'])) {
                    $response['cdr_base64'] = $status['arc_cdr']; // Retornamos el Base64 listo para guardar
                }

                if (isset($status['error'])) {
                    $response['sunat_errors'] = $status['error'];
                }

                return response()->json($response);
            }

            return response()->json([
                'success' => false,
                'message' => 'Error al consultar el ticket en SUNAT',
                'error' => $status['error'] ?? 'Desconocido',
            ], 400);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Excepción al consultar historial',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function descargarArchivo(string $tipo, string $nombre): StreamedResponse|JsonResponse
    {
        $directories = [
            'pdf' => 'facturacion/pdf',
            'xml' => 'facturacion/xml',
            'cdr' => 'facturacion/cdr',
        ];

        if (! isset($directories[$tipo]) || $nombre !== basename($nombre)) {
            return response()->json(['message' => 'Archivo inválido'], 400);
        }

        $path = $directories[$tipo].'/'.$nombre;
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return response()->json(['message' => 'Archivo no encontrado'], 404);
        }

        $mime = match ($tipo) {
            'pdf' => 'application/pdf',
            'xml' => 'application/xml; charset=UTF-8',
            'cdr' => 'application/zip',
        };

        return response()->streamDownload(
            static function () use ($disk, $path): void {
                $stream = $disk->readStream($path);
                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            $nombre,
            ['Content-Type' => $mime]
        );
    }

    public function resumenBoletas(Request $request, \App\Services\Facturacion\BoletaSummaryService $service): JsonResponse
    {
        $request->validate(['fecha' => ['required', 'date_format:Y-m-d']]);
        try {
            return response()->json($service->send($request->string('fecha')->toString()));
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'No se pudo enviar el resumen de boletas', 'error' => $e->getMessage()], 500);
        }
    }

    public function baja(Request $request, \App\Services\Facturacion\VoidedDocumentService $service): JsonResponse
    {
        $data = $request->validate(['tipoDoc' => ['nullable', 'in:01,03'], 'numero' => ['required', 'regex:/^[A-Z0-9]{4}-[0-9]+$/'], 'fecha' => ['required', 'date_format:Y-m-d'], 'motivo' => ['required', 'string', 'max:500']]);
        try {
            return response()->json($service->send($data));
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'No se pudo enviar la baja', 'error' => $e->getMessage()], 500);
        }
    }

    public function estadoResumen(string $ticket, \App\Services\Sunat\GreenterService $service): JsonResponse
    {
        $status = $service->getStatus($ticket);

        return response()->json(['success' => $status->isSuccess(), 'ticket' => $ticket, 'code' => $status->getCode(), 'message' => $status->getError()?->getMessage()]);
    }

    public function estadoEnvio(int $submissionId): JsonResponse
    {
        $row = DB::table('McrSunatSubmission as s')->leftJoin('McrDocument as d', 'd.McrDocumentID', '=', 's.McrDocumentID')->where('s.McrSunatSubmissionID', $submissionId)->select('s.*', 'd.McrDocumentType', 'd.McrSeriesCode', 'd.McrCorrelative', 'd.McrPdfPath', 'd.McrXmlPath', 'd.McrCdrPath')->first();
        if (! $row) {
            return response()->json(['message' => 'Envío no encontrado'], 404);
        }

        return response()->json([
            'success' => true,
            'submission_id' => $row->McrSunatSubmissionID,
            'document_id' => $row->McrDocumentID,
            'status' => $row->McrStatus,
            'ticket' => $row->McrTicket,
            'error' => $row->McrError,
            'completed_at' => $row->McrCompletedAt,
            'document_number' => $row->McrSeriesCode ? $row->McrSeriesCode.'-'.$row->McrCorrelative : null,
            'pdf_url' => $row->McrPdfPath ? url('/api/facturacion/archivo/pdf/'.basename($row->McrPdfPath)) : null,
            'xml_url' => $row->McrXmlPath ? url('/api/facturacion/archivo/xml/'.basename($row->McrXmlPath)) : null,
            'cdr_url' => $row->McrCdrPath ? url('/api/facturacion/archivo/cdr/'.basename($row->McrCdrPath)) : null,
        ]);
    }
}
