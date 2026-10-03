<?php

use App\Models\Empresa;
use App\Services\Facturacion\FiscalCompanyFactory;
use App\Services\Sunat\CertificateService;
use App\Services\Sunat\GreenterService;
use Greenter\Model\Client\Client;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Sale\Note;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Summary\Summary;
use Greenter\Model\Summary\SummaryDetail;
use Greenter\Model\Voided\Voided;
use Greenter\Model\Voided\VoidedDetail;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('sunat.production') || ! str_contains(config('sunat.endpoints.soap'), 'e-beta.sunat.gob.pe')) {
    throw new RuntimeException('Beta only');
}
$options = getopt('', ['company:', 'poll-directory:']);
$companies = Empresa::where('McrEnvironment', 'beta')->where('McrIsActive', true)->where('SecStatus', true);
if (isset($options['company'])) {
    $companies->whereKey((int) $options['company']);
}
if ($companies->count() !== 1) {
    throw new RuntimeException('Select exactly one active beta company with --company=ID.');
}
$c = $companies->firstOrFail();
$c->McrSolUser = 'MODDATOS';
$c->McrSolPassword = 'MODDATOS';
$evidence = getenv('NOVAFact_BETA_EVIDENCE') ?: sys_get_temp_dir().'/novafact-beta-'.date('Ymd-His');
if (! is_dir($evidence)) {
    mkdir($evidence, 0700, true);
}
$accepted = [];
$gre = app(GreenterService::class);
if (isset($options['poll-directory'])) {
    $directory = realpath($options['poll-directory']);
    if (! $directory || ! is_dir($directory)) {
        throw new RuntimeException('Evidence directory not found.');
    }
    foreach (['RC-summary', 'RC', 'RA'] as $protocol) {
        $path = $directory.'/'.$protocol.'.json';
        if (! is_file($path)) {
            continue;
        }
        $out = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (! is_string($out['ticket'] ?? null) || ! preg_match('/^[A-Za-z0-9._:-]{1,100}$/D', $out['ticket'])) {
            continue;
        }
        // A retained CDR remains the fiscal evidence even if beta later expires its ticket.
        $archive = new ZipArchive;
        $recordedCode = null;
        if (is_file($directory.'/'.$protocol.'.zip') && $archive->open($directory.'/'.$protocol.'.zip') === true) {
            for ($index = 0; $index < $archive->numFiles; $index++) {
                if (! str_ends_with($archive->getNameIndex($index), '.xml')) {
                    continue;
                }
                $document = new DOMDocument;
                if ($document->loadXML($archive->getFromIndex($index), LIBXML_NONET)) {
                    $code = (new DOMXPath($document))->evaluate('string(//*[local-name()="ResponseCode"][1])');
                    if (ctype_digit($code)) {
                        $recordedCode = $code;
                    }
                }
            }
            $archive->close();
        }
        if ($recordedCode !== null) {
            $out['status_code'] = '0';
            $out['cdr_code'] = $recordedCode;
            $out['evidence_source'] = 'persisted_cdr';
            unset($out['poll_error']);
            file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT));
            echo json_encode($out)."\n";

            continue;
        }
        $status = $gre->getStatus($out['ticket'], $c);
        $out['status_code'] = $status->getCode();
        $out['cdr_code'] = $status->getCdrResponse()?->getCode();
        $out['poll_error'] = $status->getError()?->getCode();
        if ($status->getCdrZip()) {
            file_put_contents($directory.'/'.$protocol.'.zip', $status->getCdrZip());
        }
        file_put_contents($path, json_encode($out, JSON_PRETTY_PRINT));
        echo json_encode($out)."\n";
    }
    exit;
}
$invoice = (new Invoice)->setUblVersion('2.1')->setTipoOperacion('0101')->setTipoDoc('01')->setSerie('F999')->setCorrelativo((string) random_int(100000, 999999))->setFechaEmision(new DateTime)->setTipoMoneda('PEN')->setCompany(app(FiscalCompanyFactory::class)->make($c, null))->setClient((new Client)->setTipoDoc('6')->setNumDoc('20123456789')->setRznSocial('NOVAfact Demo'))->setMtoOperGravadas(100)->setMtoIGV(18)->setTotalImpuestos(18)->setValorVenta(100)->setSubTotal(118)->setMtoImpVenta(118)->setFormaPago(new FormaPagoContado)->setDetails([(new SaleDetail)->setCodProducto('DEMO')->setUnidad('NIU')->setDescripcion('Prueba NovaFact beta')->setCantidad(1)->setMtoValorUnitario(100)->setMtoValorVenta(100)->setMtoBaseIgv(100)->setPorcentajeIgv(18)->setIgv(18)->setTipAfeIgv('10')->setTotalImpuestos(18)->setMtoPrecioUnitario(118)])->setLegends([(new Legend)->setCode('1000')->setValue('CIENTO DIECIOCHO CON 00/100 SOLES')]);
foreach (['01' => 'F999', '03' => 'B999'] as $type => $series) {
    $gre = new GreenterService(app(CertificateService::class));
    $invoice->setTipoDoc($type)->setSerie($series);
    if ($type === '03') {
        $invoice->setClient((new Client)->setTipoDoc('1')->setNumDoc('12345678')->setRznSocial('NOVAfact Demo'));
    }
    try {
        $xml = $gre->getXml($invoice, $c);
        file_put_contents($evidence.'/'.$type.'.xml', $xml);
        $r = $gre->sendSignedXml(Invoice::class, $invoice->getName(), $xml, $c);
        $out = ['type' => $type, 'success' => $r->isSuccess(), 'code' => $r->getError()?->getCode(), 'description' => $r->getError()?->getMessage(), 'cdr_code' => $r->getCdrResponse()?->getCode()];
        if ($r->getCdrZip()) {
            file_put_contents($evidence.'/'.$type.'.zip', $r->getCdrZip());
        } file_put_contents($evidence.'/'.$type.'.json', json_encode($out, JSON_PRETTY_PRINT));
        if ($r->isSuccess() && $r->getCdrResponse()?->getCode() === '0') {
            $accepted[$type] = $invoice->getName();
        } echo json_encode($out)."\n";
    } catch (Throwable $e) {
        echo json_encode(['type' => $type, 'exception' => get_class($e), 'message' => $e->getMessage()])."\n";
    }
}

if (isset($accepted['01'])) {
    foreach (['07' => '01', '08' => '02'] as $type => $reason) {
        $parts = explode('-', $accepted['01']);
        $note = (new Note)->setUblVersion('2.1')->setTipoDoc($type)->setSerie($type === '07' ? 'FC99' : 'FD99')->setCorrelativo((string) random_int(100000, 999999))->setFechaEmision(new DateTime)->setTipoMoneda('PEN')->setCompany($invoice->getCompany())->setClient((new Client)->setTipoDoc('6')->setNumDoc('20123456789')->setRznSocial('NOVAfact Demo'))->setTipDocAfectado('01')->setNumDocfectado($parts[2].'-'.$parts[3])->setCodMotivo($reason)->setDesMotivo('Prueba beta')->setMtoOperGravadas(100)->setMtoIGV(18)->setTotalImpuestos(18)->setValorVenta(100)->setSubTotal(118)->setMtoImpVenta(118)->setDetails($invoice->getDetails())->setLegends($invoice->getLegends());
        try {
            $xml = $gre->getXml($note, $c);
            file_put_contents($evidence.'/'.$type.'.xml', $xml);
            $r = $gre->sendSignedXml(Note::class, $note->getName(), $xml, $c);
            $out = ['type' => $type, 'success' => $r->isSuccess(), 'code' => $r->getError()?->getCode(), 'cdr_code' => $r->getCdrResponse()?->getCode()];
            if ($r->getCdrZip()) {
                file_put_contents($evidence.'/'.$type.'.zip', $r->getCdrZip());
            }file_put_contents($evidence.'/'.$type.'.json', json_encode($out, JSON_PRETTY_PRINT));
            echo json_encode($out)."\n";
        } catch (Throwable $e) {
            echo json_encode(['type' => $type, 'exception' => get_class($e)])."\n";
        }
    }
}
echo 'Evidence: '.$evidence."\n";
foreach (['RC-summary' => '03', 'RC' => '03', 'RA' => '01'] as $protocol => $type) {
    if (! isset($accepted[$type])) {
        continue;
    }
    $parts = explode('-', $accepted[$type]);
    $correlative = (string) random_int(100, 999);
    if (str_starts_with($protocol, 'RC')) {
        $doc = (new Summary)->setCompany($invoice->getCompany())->setCorrelativo($correlative)->setFecGeneracion(new DateTime)->setFecResumen(new DateTime)->setMoneda('PEN')->setDetails([(new SummaryDetail)->setTipoDoc('03')->setSerieNro($parts[2].'-'.$parts[3])->setClienteTipo('1')->setClienteNro('12345678')->setEstado($protocol === 'RC-summary' ? '1' : '3')->setTotal(118)->setMtoOperGravadas(100)->setMtoIGV(18)]);
    } else {
        $doc = (new Voided)->setCompany($invoice->getCompany())->setCorrelativo($correlative)->setFecGeneracion(new DateTime)->setFecComunicacion(new DateTime)->setDetails([(new VoidedDetail)->setTipoDoc('01')->setSerie($parts[2])->setCorrelativo($parts[3])->setDesMotivoBaja('Documento beta no entregado')]);
    }
    try {
        $xml = $gre->getXml($doc, $c);
        file_put_contents($evidence.'/'.$protocol.'.xml', $xml);
        $r = $gre->sendFiscalXml(substr($protocol, 0, 2), $doc->getName(), $xml, $c);
        $out = ['protocol' => $protocol, 'success' => $r->isSuccess(), 'ticket' => $r->getTicket(), 'code' => $r->getError()?->getCode()];
        if ($r->isSuccess() && $r->getTicket()) {
            $status = $gre->getStatus($r->getTicket(), $c);
            $out['status_code'] = $status->getCode();
            $out['cdr_code'] = $status->getCdrResponse()?->getCode();
            if ($status->getCdrZip()) {
                file_put_contents($evidence.'/'.$protocol.'.zip', $status->getCdrZip());
            }
        }
        file_put_contents($evidence.'/'.$protocol.'.json', json_encode($out, JSON_PRETTY_PRINT));
        echo json_encode($out)."\n";
    } catch (Throwable $e) {
        echo json_encode(['protocol' => $protocol, 'exception' => get_class($e), 'message' => $e->getMessage()])."\n";
    }
}
