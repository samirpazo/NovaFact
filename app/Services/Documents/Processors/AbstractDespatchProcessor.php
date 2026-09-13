<?php

namespace App\Services\Documents\Processors;

use App\DTO\DespatchData;
use App\Models\Empresa;
use App\Models\McrDocument;
use App\Services\Documents\ElectronicDocumentProcessor;
use App\Services\Documents\ProcessingResult;
use App\Services\Facturacion\DespatchPdfService;
use App\Services\Facturacion\DespatchService;
use App\Services\Facturacion\ManagedFileService;
use App\Services\Sunat\GreTransport;
use App\Services\Sunat\GreenterService;
use App\Enums\DocumentState;
use Illuminate\Support\Facades\Storage;

abstract class AbstractDespatchProcessor implements ElectronicDocumentProcessor
{
    public function __construct(private DespatchService $mapper, private GreenterService $greenter,
        private GreTransport $transport, private ManagedFileService $files, private DespatchPdfService $pdf) {}
    abstract protected function expectedType(): string;

    public function process(McrDocument $document, array $payload): ProcessingResult
    {
        if ($document->McrDocumentType !== $this->expectedType()) throw new \LogicException('GRE processor type mismatch.');
        $company = Empresa::whereKey($document->McrCompanyConfigID)->where('McrIsActive',true)->where('SecStatus',true)->firstOrFail();
        $despatch = $this->mapper->map($company, DespatchData::fromArray($payload));
        $name = $despatch->getName();
        $root = 'facturacion/'.$company->getKey().'/'.$document->McrDocumentType.'/'.$document->McrSeriesCode.'/'.$document->McrCorrelative;
        $xml = $this->greenter->getXml($despatch, $company);
        $xmlPath=$root.'/'.$name.'.xml'; Storage::disk('local')->put($xmlPath,$xml);
        $zip=$this->zip($name.'.xml',$xml); $zipPath=$root.'/'.$name.'.zip'; Storage::disk('local')->put($zipPath,$zip);
        $pdfPath=$root.'/'.$name.'.pdf'; Storage::disk('local')->put($pdfPath,$this->pdf->render($despatch));
        $document->update(['McrXmlPath'=>$xmlPath,'McrXmlFilID'=>$this->files->register($xmlPath,$name.'.xml','application/xml'),
            'McrZipPath'=>$zipPath,'McrZipFilID'=>$this->files->register($zipPath,$name.'.zip','application/zip'),
            'McrPdfPath'=>$pdfPath,'McrPdfFilID'=>$this->files->register($pdfPath,$name.'.pdf','application/pdf')]);
        $sent = $this->transport->send($company, $name, $zip);
        return new ProcessingResult(DocumentState::AwaitingSunat, '98', 'SUNAT GRE received the ZIP and returned a ticket.', ticket:$sent->ticket,
            metadata:['received_at'=>$sent->receivedAt,'xml_sha256'=>hash('sha256',$xml),'zip_sha256'=>hash('sha256',$zip)]);
    }

    private function zip(string $filename, string $xml): string
    {
        $temp=tempnam(sys_get_temp_dir(),'gre_zip_'); $zip=new \ZipArchive;
        if ($zip->open($temp,\ZipArchive::CREATE|\ZipArchive::OVERWRITE)!==true) throw new \RuntimeException('Could not create GRE ZIP.');
        $zip->addFromString($filename,$xml); $zip->close(); $bytes=file_get_contents($temp); @unlink($temp);
        if (! is_string($bytes)) throw new \RuntimeException('Could not read GRE ZIP.');
        return $bytes;
    }
}
