<?php

namespace App\Services\Fiscal;

use App\Models\Empresa;
use App\Models\FiscalOperation;
use App\Services\Facturacion\FiscalCompanyFactory;
use Greenter\Model\DocumentInterface;
use Greenter\Model\Sale\Document;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Summary\SummaryDetail;
use Greenter\Model\Voided\Voided;
use Greenter\Model\Voided\VoidedDetail;

final class FiscalOperationXmlBuilder
{
    public function build(FiscalOperation $operation): DocumentInterface
    {
        $company = app(FiscalCompanyFactory::class)->make(Empresa::findOrFail($operation->company_id), null);
        if ($operation->protocol === 'RA') {
            $details = array_map(fn ($doc) => (new VoidedDetail)->setTipoDoc($doc['type'])->setSerie($doc['series'])
                ->setCorrelativo((string) $doc['number'])->setDesMotivoBaja($operation->payload['reason']), $operation->payload['documents']);

            return (new Voided)->setCompany($company)->setCorrelativo((string) $operation->correlative)
                ->setFecGeneracion(new \DateTime($operation->reference_date))->setFecComunicacion(new \DateTime($operation->issue_date))->setDetails($details);
        }
        $details = [];
        foreach ($operation->payload['documents'] as $doc) {
            $detail = (new SummaryDetail)->setTipoDoc($doc['type'])->setSerieNro($doc['series'].'-'.$doc['number'])
                ->setClienteTipo($doc['customer_type'])->setClienteNro($doc['customer_number'])->setEstado($operation->kind === 'void' ? '3' : '1')
                ->setTotal((float) $doc['total'])->setMtoOperGravadas((float) $doc['taxable'])->setMtoIGV((float) $doc['tax']);
            if ($doc['reference']) {
                $detail->setDocReferencia((new Document)->setTipoDoc($doc['reference']['McrReferencedDocumentType'])->setNroDoc($doc['reference']['McrReferencedNumber']));
            }
            $details[] = $detail;
        }

        return (new Summary)->setCompany($company)->setCorrelativo((string) $operation->correlative)
            ->setFecGeneracion(new \DateTime($operation->reference_date))->setFecResumen(new \DateTime($operation->issue_date))
            ->setMoneda($operation->payload['documents'][0]['currency'])->setDetails($details);
    }
}
