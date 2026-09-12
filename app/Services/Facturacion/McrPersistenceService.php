<?php
namespace App\Services\Facturacion;
use App\DTO\FacturaData;
use App\Models\Empresa;
use App\Models\McrDocument;
use App\Models\McrSeries;
use Greenter\Model\Response\BillResult;
use Illuminate\Support\Facades\DB;

class McrPersistenceService
{
    public function reserveSeries(Empresa $company, FacturaData $data): array
    {
        return DB::transaction(function () use ($company, $data): array {
            $series = McrSeries::query()->where([
                'McrCompanyConfigID' => $company->getKey(),
                'McrDocumentType' => $data->tipoDoc,
                'McrSeriesCode' => $data->serie,
            ])->lockForUpdate()->first();
            if (!$series) {
                $series = McrSeries::create([
                    'McrCompanyConfigID' => $company->getKey(),
                    'McrDocumentType' => $data->tipoDoc,
                    'McrSeriesCode' => $data->serie,
                    'McrNextCorrelative' => max(1, (int) $data->correlativo),
                    'McrIsActive' => true, 'SecStatus' => true,
                    'CreateUserId' => 0, 'CreateDate' => now(),
                ]);
                $series = McrSeries::query()->lockForUpdate()->find($series->getKey());
            }
            $number = (int) $series->McrNextCorrelative;
            $series->update(['McrNextCorrelative' => $number + 1, 'UpdateDate' => now()]);
            return [$series, $number];
        });
    }

    public function storeAccepted(FacturaData $data, Empresa $company, int $number, BillResult $result, ?array $pdf, string $documentName, ?int $xmlFileId = null, ?int $cdrFileId = null, ?int $pdfFileId = null): McrDocument
    {
        return DB::transaction(function () use ($data, $company, $number, $result, $pdf, $documentName, $xmlFileId, $cdrFileId, $pdfFileId): McrDocument {
            $cdr = $result->getCdrResponse();
            $doc = McrDocument::create([
                'McrCompanyConfigID' => $company->getKey(),
                'McrDocumentType' => $data->tipoDoc, 'McrSeriesCode' => $data->serie,
                'McrCorrelative' => $number, 'McrIssueDate' => substr($data->fechaEmision, 0, 10),
                'McrIdempotencyKey' => $data->idempotencyKey,
                'McrIssuedAt' => $data->fechaEmision, 'McrCurrencyCode' => $data->tipoMoneda,
                'McrCustomerDocumentType' => $data->clientTipoDoc, 'McrCustomerDocumentNumber' => $data->clientNumDoc,
                'McrCustomerName' => $data->clientRznSocial, 'McrTaxableAmount' => $data->mtoOperGravada,
                'McrTaxAmount' => $data->mtoIGV, 'McrTotalAmount' => $data->mtoTotal, 'McrStatus' => 'accepted',
                'McrSunatCode' => $cdr?->getCode(), 'McrSunatDescription' => $cdr?->getDescription(),
                'McrXmlPath' => 'facturacion/xml/'.$documentName.'.xml',
                'McrCdrPath' => 'facturacion/cdr/R-'.$documentName.'.zip',
                'McrXmlFilID' => $xmlFileId, 'McrCdrFilID' => $cdrFileId, 'McrPdfFilID' => $pdfFileId,
                'McrPdfPath' => $pdf['path'] ?? null, 'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
            ]);
            foreach ($data->items as $i => $item) {
                DB::table('McrDocumentLine')->insert([
                    'McrDocumentID' => $doc->getKey(), 'McrLineNumber' => $i + 1,
                    'McrProductCode' => $item['codigo'] ?? null, 'McrDescription' => $item['descripcion'],
                    'McrUnitCode' => $item['unidad'] ?? 'NIU', 'McrQuantity' => $item['cantidad'],
                    'McrUnitValue' => $item['mtoValorUnitario'], 'McrUnitPrice' => $item['mtoPrecioUnitario'],
                    'McrTaxBase' => $item['mtoBaseIgv'], 'McrTaxAmount' => $item['igv'],
                    'McrLineTotal' => $item['mtoPrecioUnitario'] * $item['cantidad'],
                    'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
                ]);
            }
            return $doc;
        });
    }
}
