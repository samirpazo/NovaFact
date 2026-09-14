<?php

namespace App\Services\Documents\Processors;

use App\DTO\DespatchData;
use App\Enums\DocumentState;
use App\Enums\ProcessingCheckpoint;
use App\Exceptions\ClassifiedSubmissionException;
use App\Models\Empresa;
use App\Models\McrDocument;
use App\Services\Documents\ElectronicDocumentProcessor;
use App\Services\Documents\ProcessingResult;
use App\Services\Documents\SubmissionCheckpoint;
use App\Services\Facturacion\DespatchPdfService;
use App\Services\Facturacion\DespatchService;
use App\Services\Facturacion\ManagedFileService;
use App\Services\Sunat\GreenterService;
use App\Services\Sunat\GreTransport;
use Illuminate\Support\Facades\Storage;

abstract class AbstractDespatchProcessor implements ElectronicDocumentProcessor
{
    public function __construct(private DespatchService $mapper, private GreenterService $greenter,
        private GreTransport $transport, private ManagedFileService $files, private DespatchPdfService $pdf,
        private SubmissionCheckpoint $checkpoint) {}

    abstract protected function expectedType(): string;

    public function process(McrDocument $document, array $payload): ProcessingResult
    {
        if ($document->McrDocumentType !== $this->expectedType()) {
            throw new \LogicException('GRE processor type mismatch.');
        }
        $company = Empresa::whereKey($document->McrCompanyConfigID)->where('McrIsActive', true)->where('SecStatus', true)->firstOrFail();
        $despatch = $this->mapper->map($company, DespatchData::fromArray($payload), $document->McrEstablishmentSnapshot);
        $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::XmlGenerated);
        $name = $despatch->getName();
        $root = 'facturacion/'.$company->getKey().'/'.$document->McrDocumentType.'/'.$document->McrSeriesCode.'/'.$document->McrCorrelative;
        $xml = $this->greenter->getXml($despatch, $company);
        $xmlPath = $root.'/'.$name.'.xml';
        Storage::disk('local')->put($xmlPath, $xml);
        $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::XmlSigned);
        $zip = $this->zip($name.'.xml', $xml);
        $zipPath = $root.'/'.$name.'.zip';
        Storage::disk('local')->put($zipPath, $zip);
        $document->update(['McrXmlPath' => $xmlPath, 'McrXmlFilID' => $this->files->register($xmlPath, $name.'.xml', 'application/xml'),
            'McrZipPath' => $zipPath, 'McrZipFilID' => $this->files->register($zipPath, $name.'.zip', 'application/zip')]);
        $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::SubmissionStarted, true);
        try {
            $sent = $this->transport->send($company, $name, $zip);
        } catch (\Throwable $exception) {
            throw ClassifiedSubmissionException::ambiguous(ProcessingCheckpoint::SubmissionStarted, $exception);
        }
        $this->checkpoint->forDocument($document->getKey(), ProcessingCheckpoint::RemoteResponseReceived);

        return new ProcessingResult(DocumentState::AwaitingSunat, '98', 'SUNAT GRE received the ZIP and returned a ticket.', ticket: $sent->ticket,
            metadata: ['received_at' => $sent->receivedAt, 'xml_sha256' => hash('sha256', $xml), 'zip_sha256' => hash('sha256', $zip)],
            failureCategory: \App\Enums\FailureCategory::RemotePending);
    }

    private function zip(string $filename, string $xml): string
    {
        $temp = tempnam(sys_get_temp_dir(), 'gre_zip_');
        $zip = new \ZipArchive;
        if ($zip->open($temp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create GRE ZIP.');
        }
        $zip->addFromString($filename, $xml);
        $zip->close();
        $bytes = file_get_contents($temp);
        @unlink($temp);
        if (! is_string($bytes)) {
            throw new \RuntimeException('Could not read GRE ZIP.');
        }

        return $bytes;
    }
}
