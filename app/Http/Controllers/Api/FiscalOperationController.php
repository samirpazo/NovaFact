<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FiscalOperation;
use App\Models\McrDocument;
use App\Services\Auth\ApiScope;
use App\Services\Documents\LegacyAdmissionContextResolver;
use App\Services\Fiscal\AdmitFiscalOperation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

final class FiscalOperationController extends Controller
{
    public function summary(Request $request, AdmitFiscalOperation $service)
    {
        $data = $request->validate(['fecha' => ['required', 'date_format:Y-m-d'], 'currency' => ['sometimes', 'in:PEN,USD']]);
        $operation = $service->execute(app(LegacyAdmissionContextResolver::class)->resolve($request), 'summary', $data);

        return response()->json($operation->response(), 202);
    }

    public function void(Request $request, AdmitFiscalOperation $service)
    {
        $data = $request->validate(['document_id' => ['required_without:numero', 'integer', 'min:1'],
            'numero' => ['required_without:document_id', 'regex:/^[A-Z0-9]{4}-[0-9]+$/D'],
            'tipoDoc' => ['required_with:numero', 'in:01,03,07,08'], 'fecha' => ['sometimes', 'date_format:Y-m-d'],
            'motivo' => ['required', 'string', 'max:500'], 'not_delivered' => ['required', 'accepted']]);
        if (! isset($data['document_id'])) {
            [$series, $number] = explode('-', $data['numero'], 2);
            $doc = ApiScope::from($request)->documents(McrDocument::query())->where('McrDocumentType', $data['tipoDoc'])
                ->where('McrSeriesCode', $series)->where('McrCorrelative', (int) $number)->firstOrFail();
            $data['document_id'] = $doc->getKey();
        }
        $data['not_delivered'] = true;
        $operation = $service->execute(app(LegacyAdmissionContextResolver::class)->resolve($request), 'void', $data);

        return response()->json($operation->response(), 202);
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->owned($request)->findOrFail($id)->response());
    }

    public function ticket(Request $request, string $ticket)
    {
        return response()->json($this->owned($request)->where('ticket', $ticket)->firstOrFail()->response());
    }

    public function file(Request $request, int $id, string $type)
    {
        $operation = $this->owned($request)->findOrFail($id);
        $path = $type === 'xml' ? $operation->xml_path : $operation->cdr_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, basename($path), ['Content-Type' => $type === 'xml' ? 'application/xml' : 'application/zip']);
    }

    private function owned(Request $request)
    {
        $scope = ApiScope::from($request);

        return FiscalOperation::where('client_id', $scope->client->getKey())->where('company_id', $scope->company->getKey());
    }
}
