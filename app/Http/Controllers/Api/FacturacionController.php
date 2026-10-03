<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFacturaRequest;
use App\Services\Auth\ApiScope;
use App\Services\Documents\AdmitElectronicDocument;
use App\Services\Documents\LegacyAdmissionContextResolver;
use App\Services\Facturacion\SignedXmlDigestValue;
use App\Services\Sunat\CertificateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FacturacionController extends Controller
{
    public function emitFactura(StoreFacturaRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $context = app(LegacyAdmissionContextResolver::class)->resolve($request);
        unset($payload['external_reference']);

        return response()->json(app(AdmitElectronicDocument::class)->execute($context, $payload)->toArray(), 202);
    }

    public function companyConfig(Request $request): JsonResponse
    {
        $company = ApiScope::from($request)->company;
        if (! $company) {
            return response()->json(['company' => null]);
        }

        return response()->json(['company' => [
            'id' => $company->McrCompanyConfigID, 'business_name' => $company->McrBusinessName,
            'trade_name' => $company->McrTradeName, 'ruc' => $company->McrRuc, 'address' => $company->McrAddress,
            'phone' => $company->McrPhone, 'email' => $company->McrEmail, 'ubigeo' => $company->McrUbigeo,
            'department' => $company->McrDepartment, 'province' => $company->McrProvince,
            'district' => $company->McrDistrict, 'urbanization' => $company->McrUrbanization,
            'environment' => $company->McrEnvironment,
            'currency_code' => $company->McrCurrencyCode ?? 'PEN',
            'igv_rate' => (float) ($company->McrIgvRate ?? 18),
            'ipm_rate' => (float) ($company->McrIpmRate ?? 0),
            'total_tax_rate' => (float) ($company->McrTotalTaxRate ?? 18),
            'special_tax_regime' => (bool) ($company->McrSpecialTaxRegime ?? false),
            'has_sol_credentials' => ! empty($company->McrSolUser) && ! empty($company->McrSolPassword),
            'has_certificate' => ! empty($company->McrCertificateName),
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
            'urbanization' => ['nullable', 'string', 'max:150'],
            'currency_code' => ['required', 'in:PEN,USD'], 'igv_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'ipm_rate' => ['required', 'numeric', 'min:0', 'max:100'], 'total_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'special_tax_regime' => ['required', 'boolean'],
        ]);
        $data['total_tax_rate'] = (float) $data['igv_rate'] + (float) $data['ipm_rate'];
        $company = ApiScope::from($request)->company;
        if (! $company) {
            return response()->json(['message' => 'No hay una empresa Beta activa configurada.'], 422);
        }
        abort_if($data['ruc'] !== $company->McrRuc && DB::table('McrDocument')->where('McrCompanyConfigID', $company->getKey())->exists(), 409, 'RUC cannot change after document admission.');
        DB::table('McrCompanyConfig')->where('McrCompanyConfigID', $company->McrCompanyConfigID)->update([
            'McrBusinessName' => $data['business_name'], 'McrRuc' => $data['ruc'], 'McrTradeName' => $data['trade_name'] ?? null,
            'McrAddress' => $data['address'] ?? null, 'McrPhone' => $data['phone'] ?? null, 'McrEmail' => $data['email'] ?? null,
            'McrUbigeo' => $data['ubigeo'] ?? null, 'McrDepartment' => $data['department'] ?? null,
            'McrProvince' => $data['province'] ?? null, 'McrDistrict' => $data['district'] ?? null,
            'McrUrbanization' => $data['urbanization'] ?? null,
            'McrCurrencyCode' => $data['currency_code'], 'McrIgvRate' => $data['igv_rate'],
            'McrIpmRate' => $data['ipm_rate'], 'McrTotalTaxRate' => $data['total_tax_rate'],
            'McrSpecialTaxRegime' => $data['special_tax_regime'],
            'UpdateDate' => now(),
        ]);

        $company->refresh();

        return $this->companyConfig($request);
    }

    public function updateSolCredentials(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sol_user' => ['required', 'string', 'max:100'],
            'sol_password' => ['required', 'string', 'min:4', 'max:250'],
        ]);
        $company = ApiScope::from($request)->company;
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
            'certificate' => ['required', 'file', 'extensions:pfx,p12', 'max:4096'],
            'certificate_password' => ['present', 'nullable', 'string', 'max:250'],
        ]);
        $company = ApiScope::from($request)->company;
        if (! $company) {
            return response()->json(['message' => 'No hay una empresa Beta activa configurada.'], 422);
        }
        $file = $request->file('certificate');
        $name = Str::uuid().'.'.strtolower($file->getClientOriginalExtension());
        $file->storeAs('certificates', $name, 'local');
        try {
            $pem = app(CertificateService::class)->getCertificate($name, $data['certificate_password']);
            $certificate = $pem ? openssl_x509_read($pem) : false;
            $key = $pem ? openssl_pkey_get_private($pem) : false;
            if (! $certificate || ! $key || ! openssl_x509_check_private_key($certificate, $key)) {
                throw new \RuntimeException('Invalid signing certificate.');
            }
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete('certificates/'.$name);
            throw ValidationException::withMessages(['certificate' => 'Certificate or password is invalid.']);
        }
        $company->McrCertificateName = $name;
        $company->McrCertificatePassword = $data['certificate_password'];
        $company->UpdateDate = now();
        $company->save();

        return response()->json(['success' => true, 'has_certificate' => true]);
    }

    public function updateCompanyLogo(Request $request): JsonResponse
    {
        $request->validate(['logo' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512']]);
        $company = ApiScope::from($request)->company;
        if (! $company) {
            return response()->json(['message' => 'No hay una empresa Beta activa configurada.'], 422);
        }
        $file = $request->file('logo');
        $path = $file->storeAs('facturacion/logo', 'company-'.$company->getKey().'-logo.'.$file->extension(), 'local');
        DB::table('McrCompanyConfig')->where('McrCompanyConfigID', $company->McrCompanyConfigID)->update(['McrLogoPath' => $path, 'UpdateDate' => now()]);

        return response()->json(['success' => true]);
    }

    public function emitGuia(Request $request): JsonResponse
    {
        $payload = $request->all();
        $context = app(LegacyAdmissionContextResolver::class)->resolve($request);
        unset($payload['external_reference']);

        return response()->json(app(AdmitElectronicDocument::class)->execute($context, $payload)->toArray(), 202);
    }

    public function consultarHistorialGuia(Request $request, string $ticket): JsonResponse
    {
        $submission = ApiScope::from($request)->documents(DB::table('McrSunatSubmission as s')->join('McrDocument as d', 'd.McrDocumentID', '=', 's.McrDocumentID'), 'd.')->where('s.McrTicket', $ticket)->select('s.*')->first();
        if (! $submission) {
            return response()->json(['message' => 'Ticket no encontrado'], 404);
        }

        return response()->json(['submission_id' => (int) $submission->McrSunatSubmissionID,
            'document_id' => (int) $submission->McrDocumentID, 'ticket' => $submission->McrTicket,
            'status' => $submission->McrStatus, 'error' => $submission->McrError]);
    }

    public function descargarArchivo(Request $request, string $tipo, string $nombre): StreamedResponse|JsonResponse
    {
        $columns = ['pdf' => 'McrPdfPath', 'xml' => 'McrXmlPath', 'zip' => 'McrZipPath', 'cdr' => 'McrCdrPath'];
        if (! isset($columns[$tipo]) || $nombre !== basename($nombre)) {
            return response()->json(['message' => 'Archivo inválido'], 400);
        }
        $column = $columns[$tipo];
        $path = ApiScope::from($request)->documents(DB::table('McrDocument'))->whereNotNull($column)->get([$column])->pluck($column)
            ->first(fn ($candidate) => basename((string) $candidate) === $nombre);
        if (! $path) {
            return response()->json(['message' => 'Archivo no encontrado'], 404);
        }
        $disk = Storage::disk('local');
        if (! $disk->exists($path)) {
            return response()->json(['message' => 'Archivo no encontrado'], 404);
        }

        $mime = match ($tipo) {
            'pdf' => 'application/pdf',
            'xml' => 'application/xml; charset=UTF-8',
            'zip', 'cdr' => 'application/zip',
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

    public function estadoEnvio(Request $request, int $submissionId): JsonResponse
    {
        $row = ApiScope::from($request)->documents(DB::table('McrSunatSubmission as s')->join('McrDocument as d', 'd.McrDocumentID', '=', 's.McrDocumentID'), 'd.')->where('s.McrSunatSubmissionID', $submissionId)->select('s.*', 'd.McrDocumentType', 'd.McrSeriesCode', 'd.McrCorrelative', 'd.McrStateVersion', 'd.McrPdfPath', 'd.McrXmlPath', 'd.McrZipPath', 'd.McrCdrPath', 'd.McrSunatCode', 'd.McrSunatDescription', 'd.McrProcessingResult')->first();
        if (! $row) {
            return response()->json(['message' => 'Envío no encontrado'], 404);
        }

        $fiscalResult = json_decode($row->McrProcessingResult ?? '{}', true) ?? [];

        return response()->json([
            'success' => true,
            'submission_id' => $row->McrSunatSubmissionID,
            'document_id' => $row->McrDocumentID,
            'state_version' => (int) $row->McrStateVersion,
            'status' => match ($row->McrStatus) {
                'reconciliation_pending' => 'processing',
                'manual_review' => 'failed',
                default => $row->McrStatus,
            },
            'recovery_status' => in_array($row->McrStatus, ['reconciliation_pending', 'manual_review'], true) ? $row->McrStatus : null,
            'ticket' => $row->McrTicket,
            'error' => $row->McrError,
            'sunat_code' => $row->McrSunatCode,
            'sunat_description' => $row->McrSunatDescription,
            'notes' => $fiscalResult['notes'] ?? [],
            'completed_at' => $row->McrCompletedAt,
            'document_number' => $row->McrSeriesCode ? $row->McrSeriesCode.'-'.$row->McrCorrelative : null,
            'digest_value' => app(SignedXmlDigestValue::class)->extractFromStorage($row->McrXmlPath),
            'pdf_url' => $row->McrPdfPath ? url('/api/facturacion/archivo/pdf/'.basename($row->McrPdfPath)) : null,
            'xml_url' => $row->McrXmlPath ? url('/api/facturacion/archivo/xml/'.basename($row->McrXmlPath)) : null,
            'zip_url' => $row->McrZipPath ? url('/api/facturacion/archivo/zip/'.basename($row->McrZipPath)) : null,
            'cdr_url' => $row->McrCdrPath ? url('/api/facturacion/archivo/cdr/'.basename($row->McrCdrPath)) : null,
        ]);
    }
}
