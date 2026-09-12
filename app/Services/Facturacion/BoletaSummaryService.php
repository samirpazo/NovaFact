<?php
namespace App\Services\Facturacion;
use App\Models\Empresa;
use App\Models\McrDocument;
use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Summary\SummaryDetail;
use Illuminate\Support\Facades\DB;

class BoletaSummaryService
{
    public function send(string $date): array
    {
        $company = app(\App\Repositories\EmpresaRepository::class)->getActive();
        $docs = McrDocument::where('McrCompanyConfigID',$company->getKey())->where('McrDocumentType','03')->where('McrStatus','accepted')->whereDate('McrIssueDate',$date)->get();
        if ($docs->isEmpty()) return ['success'=>false,'message'=>'No hay boletas aceptadas para la fecha indicada.'];
        $correlative = ((int) DB::table('McrDailySummary')->where('McrCompanyConfigID',$company->getKey())->max('McrCorrelative')) + 1;
        $summary = (new Summary())->setCorrelativo((string)$correlative)->setFecGeneracion(new \DateTime($date))->setFecResumen(new \DateTime(date('Y-m-d')))->setMoneda('PEN');
        $summary->setCompany((new Company())->setRuc($company->CpyRuc)->setRazonSocial($company->CpyBusinessName)->setNombreComercial($company->CpyTradename)->setAddress((new Address())->setUbigueo($company->ubigeo)->setDireccion($company->CpyAddress)));
        $details=[]; foreach($docs as $doc){ $details[]=(new SummaryDetail())->setTipoDoc('03')->setSerieNro($doc->McrSeriesCode.'-'.$doc->McrCorrelative)->setClienteTipo($doc->McrCustomerDocumentType)->setClienteNro($doc->McrCustomerDocumentNumber)->setEstado('1')->setTotal((float)$doc->McrTotalAmount)->setMtoOperGravadas((float)$doc->McrTaxableAmount)->setMtoIGV((float)$doc->McrTaxAmount)->setPorcentajeIgv(18); }
        $summary->setDetails($details);
        $xml = app(\App\Services\Sunat\GreenterService::class)->getXml($summary);
        $name=$summary->getName(); app(\App\Services\Sunat\XmlService::class)->save($xml,$name.'.xml');
        $result=app(\App\Services\Sunat\GreenterService::class)->send($summary);
        DB::table('McrDailySummary')->insert(['McrCompanyConfigID'=>$company->getKey(),'McrReferenceDate'=>$date,'McrIssueDate'=>date('Y-m-d'),'McrCorrelative'=>$correlative,'McrStatus'=>'sent','McrXmlPath'=>'facturacion/xml/'.$name.'.xml','McrTicket'=>$result->getTicket(),'SecStatus'=>true,'CreateUserId'=>0,'CreateDate'=>now()]);
        return ['success'=>true,'ticket'=>$result->getTicket(),'summary'=>$name,'documents'=>$docs->count()];
    }
}
