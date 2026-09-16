<?php

use App\Services\Facturacion\InvoicePdfService;
use Greenter\Model\Company\Company;
use Greenter\Model\Client\Client;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__.'/../Support/PipelineDatabase.php';

uses(TestCase::class);

beforeEach(function () {
    bootPipelineDatabase();
});

afterEach(function () {
    if (isset($this->pipelineSchema)) {
        Illuminate\Support\Facades\DB::statement('DROP SCHEMA "'.$this->pipelineSchema.'" CASCADE');
        Illuminate\Support\Facades\DB::disconnect('pipeline_test');
    }
});

test('generates PDF with custom twig template without errors', function () {
    Storage::fake('local');

    $company = (new Company())
        ->setRuc('20329725431')
        ->setRazonSocial('SUNSHINE EXPORT S.A.C.');

    $client = (new Client())
        ->setTipoDoc('6')
        ->setNumDoc('20602831222')
        ->setRznSocial('TROPICAL FOOD INC S.A.C.');

    $detail = (new SaleDetail())
        ->setCodProducto('I06206')
        ->setDescripcion('PALTA CONVENCIONAL HASS')
        ->setUnidad('KGM')
        ->setCantidad(19396.60)
        ->setMtoPrecioUnitario(1.65)
        ->setMtoValorVenta(32004.39);

    $invoice = (new Invoice())
        ->setTipoDoc('01')
        ->setSerie('F025')
        ->setCorrelativo('00002178')
        ->setFechaEmision(new DateTime('2026-09-05'))
        ->setTipoMoneda('PEN')
        ->setCompany($company)
        ->setClient($client)
        ->setDetails([$detail])
        ->setMtoOperInafectas(32745.24)
        ->setMtoImpVenta(32745.24)
        ->setLegends([
            (new Legend())
                ->setCode('1000')
                ->setValue('TREINTA Y DOS MIL SETECIENTOS CUARENTA Y CINCO Y 24/100 SOLES')
        ]);

    $pdfService = new InvoicePdfService();
    $result = $pdfService->generate($invoice, 'test_invoice.pdf');

    expect($result)->toHaveKey('path');
    expect($result)->toHaveKey('content');
    expect($result['content'])->not->toBeEmpty();
    expect(Storage::disk('local')->exists($result['path']))->toBeTrue();
});

