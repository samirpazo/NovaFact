<?php
namespace App\Services\Facturacion;
use Greenter\Model\Company\Company;
use Greenter\Model\Company\Address;
use Greenter\Model\Voided\Voided;
use Greenter\Model\Voided\VoidedDetail;
use Illuminate\Support\Facades\DB;

class VoidedDocumentService
{
    public function send(array $data): array
    {
        $company=app(\App\Repositories\EmpresaRepository::class)->getActive();
        [$serie,$number]=array_pad(explode('-', $data['numero'],2),2,null);
        if (!$serie || !ctype_digit((string)$number)) return ['success'=>false,'message'=>'numero debe tener formato SERIE-CORRELATIVO'];
        $doc=(new Voided())->setCorrelativo((string)(((int)DB::table('McrVoidedDocument')->whereDate('McrIssueDate',$data['fecha'])->max('McrCorrelative'))+1))->setFecGeneracion(new \DateTime($data['fecha']))->setFecComunicacion(new \DateTime())->setCompany((new Company())->setRuc($company->CpyRuc)->setRazonSocial($company->CpyBusinessName)->setNombreComercial($company->CpyTradename)->setAddress((new Address())->setUbigueo($company->ubigeo)->setDireccion($company->CpyAddress)))->setDetails([(new VoidedDetail())->setTipoDoc($data['tipoDoc']??'03')->setSerie($serie)->setCorrelativo($number)->setDesMotivoBaja($data['motivo'])]);
        $gre=app(\App\Services\Sunat\GreenterService::class); $xml=$gre->getXml($doc); app(\App\Services\Sunat\XmlService::class)->save($xml,$doc->getName().'.xml'); $result=$gre->send($doc);
        DB::table('McrVoidedDocument')->insert(['McrCompanyConfigID'=>$company->getKey(),'McrDocumentID'=>null,'McrReferenceDate'=>$data['fecha'],'McrIssueDate'=>$data['fecha'],'McrCorrelative'=>(int)$doc->getCorrelativo(),'McrReason'=>$data['motivo'],'McrStatus'=>'sent','McrTicket'=>$result->getTicket(),'McrXmlPath'=>'facturacion/xml/'.$doc->getName().'.xml','SecStatus'=>true,'CreateUserId'=>0,'CreateDate'=>now()]);
        return ['success'=>true,'ticket'=>$result->getTicket(),'id'=>$doc->getName()];
    }
}
