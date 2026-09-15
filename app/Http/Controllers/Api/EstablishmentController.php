<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\McrEstablishment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class EstablishmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $company = $this->company($request);

        return response()->json(['data' => McrEstablishment::where('McrCompanyConfigID', $company->getKey())
            ->orderBy('McrEstablishmentID')->get()->map(fn ($item) => $this->view($item))]);
    }

    public function store(Request $request): JsonResponse
    {
        $company = $this->company($request);
        $data = $this->validated($request, $company);
        if (($data['is_default'] ?? false) && ($data['is_active'] ?? true)
            && McrEstablishment::where('McrCompanyConfigID', $company->getKey())
            ->where('McrIsDefault', true)->where('McrIsActive', true)->where('SecStatus', true)->exists()) {
            abort(422, 'The company already has an active default establishment.');
        }
        $item = McrEstablishment::create($this->columns($data) + ['McrCompanyConfigID' => $company->getKey(),
            'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now()]);

        return response()->json(['data' => $this->view($item)], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $company = $this->company($request);
        $item = McrEstablishment::whereKey($id)
            ->where('McrCompanyConfigID', $company->getKey())->firstOrFail();
        $data = $this->validated($request, $company, $item);
        if (isset($data['external_code']) && $data['external_code'] !== $item->McrExternalCode
            && $item->documents()->exists()) {
            abort(422, 'External code is immutable after the establishment has documents.');
        }
        if (isset($data['sunat_code']) && $data['sunat_code'] !== $item->McrSunatCode
            && $item->documents()->exists()) {
            abort(422, 'SUNAT code requires a controlled change after documents exist.');
        }
        $willBeDefault = $data['is_default'] ?? (bool) $item->McrIsDefault;
        $willBeActive = $data['is_active'] ?? (bool) $item->McrIsActive;
        if ($willBeDefault && $willBeActive && McrEstablishment::where('McrCompanyConfigID', $company->getKey())
            ->where($item->getKeyName(), '<>', $item->getKey())
            ->where('McrIsDefault', true)->where('McrIsActive', true)->where('SecStatus', true)->exists()) {
            abort(422, 'The company already has an active default establishment.');
        }
        $item->update($this->columns($data) + ['UpdateUserId' => 0, 'UpdateDate' => now()]);

        return response()->json(['data' => $this->view($item->fresh())]);
    }

    private function company(Request $request): Empresa
    {
        $id = $request->header('X-Company-Id');
        abort_unless(is_string($id) && ctype_digit($id), 422, 'Explicit company scope is required.');

        return Empresa::whereKey((int) $id)->where('McrIsActive', true)->where('SecStatus', true)->firstOrFail();
    }

    private function validated(Request $request, Empresa $company, ?McrEstablishment $item = null): array
    {
        $sometimes = $item ? 'sometimes' : 'required';

        return $request->validate([
            'external_code' => [$sometimes, 'string', 'max:100', 'regex:/^[A-Za-z0-9._:-]+$/', Rule::unique('McrEstablishment', 'McrExternalCode')->where('McrCompanyConfigID', $company->getKey())->ignore($item?->getKey(), 'McrEstablishmentID')],
            'sunat_code' => [$sometimes, 'regex:/^\d{4}$/D'], 'name' => [$sometimes, 'string', 'max:250'],
            'trade_name' => ['nullable', 'string', 'max:250'], 'address' => [$sometimes, 'string', 'max:500'],
            'address_reference' => ['nullable', 'string', 'max:500'], 'ubigeo' => [$sometimes, 'regex:/^\d{6}$/D'],
            'department' => ['nullable', 'string', 'max:100'], 'province' => ['nullable', 'string', 'max:100'],
            'district' => ['nullable', 'string', 'max:100'], 'country_code' => ['sometimes', 'string', 'size:2'],
            'is_default' => ['sometimes', 'boolean'], 'is_active' => ['sometimes', 'boolean'],
        ]);
    }

    private function columns(array $data): array
    {
        $map = ['external_code' => 'McrExternalCode', 'sunat_code' => 'McrSunatCode', 'name' => 'McrName', 'trade_name' => 'McrTradeName',
            'address' => 'McrAddress', 'address_reference' => 'McrAddressReference', 'ubigeo' => 'McrUbigeo', 'department' => 'McrDepartment',
            'province' => 'McrProvince', 'district' => 'McrDistrict', 'country_code' => 'McrCountryCode', 'is_default' => 'McrIsDefault', 'is_active' => 'McrIsActive'];
        $result = [];
        foreach ($map as $source => $target) {
            if (array_key_exists($source, $data)) {
                $result[$target] = $data[$source];
            }
        }

        return $result;
    }

    private function view(McrEstablishment $item): array
    {
        $series = $item->series()->whereIn('McrDocumentType', ['01', '03'])
            ->orderBy('McrDocumentType')->get(['McrDocumentType', 'McrSeriesCode', 'McrNextCorrelative', 'McrIsActive'])
            ->map(fn ($row) => ['document_type' => $row->McrDocumentType, 'series_code' => $row->McrSeriesCode,
                'next_correlative' => (int) $row->McrNextCorrelative, 'is_active' => (bool) $row->McrIsActive])->values()->all();

        return ['id' => $item->getKey(), 'company_id' => (int) $item->McrCompanyConfigID, 'external_code' => $item->McrExternalCode,
            'sunat_code' => $item->McrSunatCode, 'name' => $item->McrName, 'trade_name' => $item->McrTradeName,
            'address' => $item->McrAddress, 'address_reference' => $item->McrAddressReference, 'ubigeo' => $item->McrUbigeo,
            'department' => $item->McrDepartment, 'province' => $item->McrProvince, 'district' => $item->McrDistrict,
            'country_code' => $item->McrCountryCode, 'is_default' => (bool) $item->McrIsDefault, 'is_active' => (bool) $item->McrIsActive,
            'series' => $series];
    }
}
