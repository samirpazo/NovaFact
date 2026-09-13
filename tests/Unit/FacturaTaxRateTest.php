<?php

use App\DTO\FacturaData;
use App\Models\Empresa;
use App\Services\Facturacion\FacturaService;
use App\Services\Facturacion\InvoicePdfService;
use App\Services\Facturacion\ManagedFileService;
use App\Services\Sunat\GreenterService;
use Greenter\Xml\Builder\InvoiceBuilder;

test('the XML keeps the requested IGV rate and coherent line amounts', function () {
    $company = new Empresa([
        'McrRuc' => '20123456789',
        'McrBusinessName' => 'Nova Test',
        'McrTradeName' => 'Nova',
        'McrAddress' => 'Lima',
        'McrUbigeo' => '150101',
        'McrDepartment' => 'Lima',
        'McrProvince' => 'Lima',
        'McrDistrict' => 'Lima',
        'McrIgvRate' => 18,
    ]);

    $service = new FacturaService(
        Mockery::mock(GreenterService::class),
        Mockery::mock(InvoicePdfService::class),
        Mockery::mock(ManagedFileService::class),
    );

    $data = FacturaData::fromArray([
        'tipoDoc' => '03',
        'serie' => 'B001',
        'correlativo' => '1',
        'fechaEmision' => '2026-09-12T23:25:00',
        'tipoMoneda' => 'PEN',
        'clientTipoDoc' => '1',
        'clientNumDoc' => '00000000',
        'clientRznSocial' => 'CLIENTE VARIOS',
        'mtoOperGravada' => 65.16,
        'mtoIGV' => 6.84,
        'mtoTotal' => 72.00,
        'igvRate' => 10.5,
        'items' => [[
            'codigo' => 'OFF_1',
            'descripcion' => 'Hamburguesa Clásica',
            'unidad' => 'NIU',
            'cantidad' => 9,
            'mtoValorUnitario' => 7.239819,
            'mtoPrecioUnitario' => 8.00,
            'mtoBaseIgv' => 65.16,
            'igv' => 6.84,
            'mtoValorVenta' => 65.16,
        ]],
    ]);

    $method = new ReflectionMethod(FacturaService::class, 'mapToInvoice');
    $invoice = $method->invoke($service, $data, $company);
    $xml = (new InvoiceBuilder)->build($invoice);
    $document = new DOMDocument;
    $document->loadXML($xml);
    $xpath = new DOMXPath($document);

    expect($xpath->evaluate('string(//*[local-name()="InvoiceLine"]//*[local-name()="Percent"])'))->toBe('10.5')
        ->and($xpath->evaluate('string(//*[local-name()="InvoiceLine"]//*[local-name()="TaxableAmount"])'))->toBe('65.16')
        ->and($xpath->evaluate('string(//*[local-name()="InvoiceLine"]//*[local-name()="TaxSubtotal"]/*[local-name()="TaxAmount"])'))->toBe('6.84');
});
