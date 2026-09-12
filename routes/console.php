<?php

use App\Models\Empresa;
use App\Repositories\EmpresaRepository;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sunat:test-factura', function (EmpresaRepository $empresaRepo) {
    $this->info('Iniciando prueba de envío a SUNAT (Beta)...');

    $empresa = $empresaRepo->getActive();
    if (!$empresa) {
        $this->error('No se encontró una empresa activa.');
        return;
    }

    $endpoint = config('sunat.production') 
        ? config('sunat.endpoints.soap') 
        : 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService';

    $this->comment("MODO: " . (config('sunat.production') ? 'PRODUCCIÓN' : 'BETA/DEMO') . " ($empresa->CpyRuc)");

    $certificate = app(\App\Services\Sunat\CertificateService::class)->getCertificate($empresa->CpyNameCertificate, $empresa->CpyPasswordCertificate);
    if (!$certificate) {
        $this->error("❌ Error: No se pudo cargar el certificado. Verifica que el archivo exista en storage/app/certificates/ y que la contraseña sea correcta.");
        return;
    }

    $see = new \Greenter\See();
    $see->setCertificate($certificate);
    $see->setService($endpoint);
    $see->setClaveSOL($empresa->CpyRuc, $empresa->CpyUserSol ?? 'MODDATOS', $empresa->CpyPasswordSol ?? 'MODDATOS');

    // 3. Crear Factura mínima
    $client = (new \Greenter\Model\Client\Client())
        ->setTipoDoc('6')
        ->setNumDoc('20405544458')
        ->setRznSocial('EMPRESA DE PRUEBA');

    $company = (new \Greenter\Model\Company\Company())
        ->setRuc($empresa->CpyRuc)
        ->setRazonSocial($empresa->CpyBusinessName)
        ->setAddress((new \Greenter\Model\Company\Address())
            ->setUbigueo($empresa->ubigeo)
            ->setDepartamento($empresa->departamento)
            ->setProvincia($empresa->provincia)
            ->setDistrito($empresa->distrito)
            ->setDireccion($empresa->CpyAddress)
            ->setCodLocal('0000'));

    $invoice = (new \Greenter\Model\Sale\Invoice())
        ->setUblVersion('2.1')
        ->setTipoOperacion('0101')
        ->setTipoDoc('01')
        ->setSerie('F001')
        ->setCorrelativo('1')
        ->setFechaEmision(new \DateTime())
        ->setTipoMoneda('PEN')
        ->setClient($client)
        ->setCompany($company)
        ->setMtoOperExoneradas(100)
        ->setMtoIGV(0)
        ->setTotalImpuestos(0)
        ->setValorVenta(100)
        ->setSubTotal(100)
        ->setTotalAnticipos(0)
        ->setMtoImpVenta(100)
        ->setFormaPago(new \Greenter\Model\Sale\FormaPagos\FormaPagoContado());

    $item = (new \Greenter\Model\Sale\SaleDetail())
        ->setCodProducto('P001')
        ->setUnidad('NIU')
        ->setCantidad(1)
        ->setDescripcion('PRODUCTO DE PRUEBA EXONERADO')
        ->setMtoBaseIgv(100)
        ->setPorcentajeIgv(0)
        ->setIgv(0)
        ->setTipAfeIgv('20') // Exonerado
        ->setTotalImpuestos(0)
        ->setMtoValorVenta(100)
        ->setMtoValorUnitario(100)
        ->setMtoPrecioUnitario(100);

    $invoice->setDetails([$item])
            ->setLegends([
                (new \Greenter\Model\Sale\Legend())
                    ->setCode('1000')
                    ->setValue('SON CIENTO DIECIOCHO SOLES')
            ]);

    $name = $invoice->getName();
    $xml = $see->getXmlSigned($invoice);
    \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/01/test/{$name}.xml", $xml);
    $this->info("✅ XML guardado en: storage/app/sunat/01/test/{$name}.xml");

    // 4. Enviar
    $this->info("Enviando factura {$name}...");
    try {
        $result = $see->send($invoice);

        if ($result->isSuccess()) {
            $cdr = $result->getCdrResponse();
            $this->info('✅ ¡ÉXITO! Factura aceptada.');
            $this->info('ID: ' . $cdr->getId());
            $this->info('Descripción: ' . $cdr->getDescription());
            
            $cdrZip = $result->getCdrZip();
            if ($cdrZip) {
                \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/01/test/R-{$name}.zip", $cdrZip);
                $this->info("✅ CDR guardado en: storage/app/sunat/01/test/R-{$name}.zip");
            }

            if ($cdr->getNotes()) {
                $this->info('Notas: ' . implode(', ', $cdr->getNotes()));
            }
        } else {
            $this->error('❌ Error: ' . $result->getError()->getMessage());
            $this->error('Código: ' . $result->getError()->getCode());
        }
    } catch (\Exception $e) {
        $this->error('💥 Excepción: ' . $e->getMessage());
    }

})->purpose('Realiza una prueba de envío real usando los servicios Beta de SUNAT');

Artisan::command('sunat:test-factura-guia', function (EmpresaRepository $empresaRepo) {
    $this->info('Iniciando prueba de envío a SUNAT de Factura con Guía de Remisión (Beta)...');

    $empresa = $empresaRepo->getActive();
    if (!$empresa) {
        $this->error('No se encontró una empresa activa.');
        return;
    }

    $endpoint = config('sunat.production') 
        ? config('sunat.endpoints.soap') 
        : 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService';

    $this->comment("MODO: " . (config('sunat.production') ? 'PRODUCCIÓN' : 'BETA/DEMO') . " ($empresa->CpyRuc)");

    $certificate = app(\App\Services\Sunat\CertificateService::class)->getCertificate($empresa->CpyNameCertificate, $empresa->CpyPasswordCertificate);
    if (!$certificate) {
        $this->error("❌ Error: No se pudo cargar el certificado. Verifica que el archivo exista en storage/app/certificates/ y que la contraseña sea correcta.");
        return;
    }

    $see = new \Greenter\See();
    $see->setCertificate($certificate);
    $see->setService($endpoint);
    $see->setClaveSOL($empresa->CpyRuc, $empresa->CpyUserSol ?? 'MODDATOS', $empresa->CpyPasswordSol ?? 'MODDATOS');

    // 3. Crear Factura mínima con Guía relacionada
    $client = (new \Greenter\Model\Client\Client())
        ->setTipoDoc('6')
        ->setNumDoc('20405544458')
        ->setRznSocial('EMPRESA DE PRUEBA');

    $company = (new \Greenter\Model\Company\Company())
        ->setRuc($empresa->CpyRuc)
        ->setRazonSocial($empresa->CpyBusinessName)
        ->setAddress((new \Greenter\Model\Company\Address())
            ->setUbigueo($empresa->ubigeo)
            ->setDepartamento($empresa->departamento)
            ->setProvincia($empresa->provincia)
            ->setDistrito($empresa->distrito)
            ->setDireccion($empresa->CpyAddress)
            ->setCodLocal('0000'));

    $invoice = (new \Greenter\Model\Sale\Invoice())
        ->setUblVersion('2.1')
        ->setTipoOperacion('0101')
        ->setTipoDoc('01')
        ->setSerie('F001')
        ->setCorrelativo('2') // Incrementamos el correlativo para pruebas
        ->setFechaEmision(new \DateTime())
        ->setTipoMoneda('PEN')
        ->setClient($client)
        ->setCompany($company)
        ->setMtoOperExoneradas(100)
        ->setMtoIGV(0)
        ->setTotalImpuestos(0)
        ->setValorVenta(100)
        ->setSubTotal(100)
        ->setTotalAnticipos(0)
        ->setMtoImpVenta(100)
        ->setFormaPago(new \Greenter\Model\Sale\FormaPagos\FormaPagoContado());

    // --- AQUÍ RELACIONAMOS LA GUÍA ---
    $guiaRelacionada = (new \Greenter\Model\Sale\Document())
        ->setTipoDoc('09')
        ->setNroDoc('T001-1');
        
    $invoice->setGuias([$guiaRelacionada]);
    // ---------------------------------

    $item = (new \Greenter\Model\Sale\SaleDetail())
        ->setCodProducto('P001')
        ->setUnidad('NIU')
        ->setCantidad(1)
        ->setDescripcion('PRODUCTO DE PRUEBA EXONERADO CON GUIA')
        ->setMtoBaseIgv(100)
        ->setPorcentajeIgv(0)
        ->setIgv(0)
        ->setTipAfeIgv('20')
        ->setTotalImpuestos(0)
        ->setMtoValorVenta(100)
        ->setMtoValorUnitario(100)
        ->setMtoPrecioUnitario(100);

    $invoice->setDetails([$item])
            ->setLegends([
                (new \Greenter\Model\Sale\Legend())
                    ->setCode('1000')
                    ->setValue('SON CIENTO DIECIOCHO SOLES')
            ]);

    $name = $invoice->getName();
    $xml = $see->getXmlSigned($invoice);
    \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/01/test/{$name}.xml", $xml);
    $this->info("✅ XML guardado en: storage/app/sunat/01/test/{$name}.xml");

    // 4. Enviar
    $this->info("Enviando factura {$name}...");
    try {
        $result = $see->send($invoice);

        if ($result->isSuccess()) {
            $cdr = $result->getCdrResponse();
            $this->info('✅ ¡ÉXITO! Factura aceptada.');
            $this->info('ID: ' . $cdr->getId());
            $this->info('Descripción: ' . $cdr->getDescription());
            
            $cdrZip = $result->getCdrZip();
            if ($cdrZip) {
                \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/01/test/R-{$name}.zip", $cdrZip);
                $this->info("✅ CDR guardado en: storage/app/sunat/01/test/R-{$name}.zip");
            }

            if ($cdr->getNotes()) {
                $this->info('Notas: ' . implode(', ', $cdr->getNotes()));
            }
        } else {
            $this->error('❌ Error: ' . $result->getError()->getMessage());
            $this->error('Código: ' . $result->getError()->getCode());
        }
    } catch (\Exception $e) {
        $this->error('💥 Excepción: ' . $e->getMessage());
    }

})->purpose('Realiza una prueba de envío real usando los servicios Beta de SUNAT con una Guía Relacionada');

Artisan::command('sunat:test-factura-igv', function (EmpresaRepository $empresaRepo) {
    $this->info('Iniciando prueba de envío a SUNAT de Factura con IGV (Beta)...');

    $empresa = $empresaRepo->getActive();
    if (!$empresa) {
        $this->error('No se encontró una empresa activa.');
        return;
    }

    $endpoint = config('sunat.production') 
        ? config('sunat.endpoints.soap') 
        : 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService';

    $certificate = app(\App\Services\Sunat\CertificateService::class)->getCertificate($empresa->CpyNameCertificate, $empresa->CpyPasswordCertificate);
    if (!$certificate) {
        $this->error("❌ Error: No se pudo cargar el certificado.");
        return;
    }

    $see = new \Greenter\See();
    $see->setCertificate($certificate);
    $see->setService($endpoint);
    $see->setClaveSOL($empresa->CpyRuc, $empresa->CpyUserSol ?? 'MODDATOS', $empresa->CpyPasswordSol ?? 'MODDATOS');

    $client = (new \Greenter\Model\Client\Client())
        ->setTipoDoc('6')
        ->setNumDoc('20405544458')
        ->setRznSocial('EMPRESA DE PRUEBA');

    $company = (new \Greenter\Model\Company\Company())
        ->setRuc($empresa->CpyRuc)
        ->setRazonSocial($empresa->CpyBusinessName)
        ->setAddress((new \Greenter\Model\Company\Address())
            ->setUbigueo($empresa->ubigeo)->setDepartamento($empresa->departamento)->setProvincia($empresa->provincia)
            ->setDistrito($empresa->distrito)->setDireccion($empresa->CpyAddress)->setCodLocal('0000'));

    $invoice = (new \Greenter\Model\Sale\Invoice())
        ->setUblVersion('2.1')->setTipoOperacion('0101')->setTipoDoc('01')
        ->setSerie('F001')->setCorrelativo('3')->setFechaEmision(new \DateTime())
        ->setTipoMoneda('PEN')->setClient($client)->setCompany($company)
        ->setMtoOperGravadas(100.00)
        ->setMtoIGV(18.00)
        ->setTotalImpuestos(18.00)
        ->setValorVenta(100.00)
        ->setSubTotal(118.00)
        ->setMtoImpVenta(118.00)
        ->setFormaPago(new \Greenter\Model\Sale\FormaPagos\FormaPagoContado());

    $item = (new \Greenter\Model\Sale\SaleDetail())
        ->setCodProducto('P002')
        ->setUnidad('NIU')->setCantidad(1)
        ->setDescripcion('PRODUCTO DE PRUEBA GRAVADO')
        ->setMtoBaseIgv(100.00)
        ->setPorcentajeIgv(18.00)
        ->setIgv(18.00)
        ->setTipAfeIgv('10') // Gravado - Operación Onerosa
        ->setTotalImpuestos(18.00)
        ->setMtoValorVenta(100.00)
        ->setMtoValorUnitario(100.00)
        ->setMtoPrecioUnitario(118.00);

    $invoice->setDetails([$item])
            ->setLegends([
                (new \Greenter\Model\Sale\Legend())
                    ->setCode('1000')
                    ->setValue('SON CIENTO DIECIOCHO Y 00/100 SOLES')
            ]);

    $name = $invoice->getName();
    $xml = $see->getXmlSigned($invoice);
    \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/01/test/{$name}.xml", $xml);
    $this->info("✅ XML guardado en: storage/app/sunat/01/test/{$name}.xml");

    $this->info("Enviando factura {$name}...");
    try {
        $result = $see->send($invoice);
        if ($result->isSuccess()) {
            $cdr = $result->getCdrResponse();
            $this->info('✅ ¡ÉXITO! Factura aceptada. ID: ' . $cdr->getId());
            $cdrZip = $result->getCdrZip();
            if ($cdrZip) {
                \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/01/test/R-{$name}.zip", $cdrZip);
                $this->info("✅ CDR guardado en: storage/app/sunat/01/test/R-{$name}.zip");
            }
        } else {
            $this->error('❌ Error: ' . $result->getError()->getMessage());
        }
    } catch (\Exception $e) { $this->error('💥 Excepción: ' . $e->getMessage()); }
})->purpose('Prueba de envío de Factura con IGV');

Artisan::command('sunat:test-factura-anticipo', function (EmpresaRepository $empresaRepo) {
    $this->info('Iniciando prueba de envío a SUNAT de Factura con Anticipo (Beta)...');

    $empresa = $empresaRepo->getActive();
    if (!$empresa) {
        $this->error('No se encontró una empresa activa.');
        return;
    }

    $endpoint = config('sunat.production') 
        ? config('sunat.endpoints.soap') 
        : 'https://e-beta.sunat.gob.pe/ol-ti-itcpfegem-beta/billService';

    $certificate = app(\App\Services\Sunat\CertificateService::class)->getCertificate($empresa->CpyNameCertificate, $empresa->CpyPasswordCertificate);
    if (!$certificate) {
        $this->error("❌ Error: No se pudo cargar el certificado.");
        return;
    }

    $see = new \Greenter\See();
    $see->setCertificate($certificate);
    $see->setService($endpoint);
    $see->setClaveSOL($empresa->CpyRuc, $empresa->CpyUserSol ?? 'MODDATOS', $empresa->CpyPasswordSol ?? 'MODDATOS');

    $client = (new \Greenter\Model\Client\Client())
        ->setTipoDoc('6')
        ->setNumDoc('20405544458')
        ->setRznSocial('EMPRESA DE PRUEBA');

    $company = (new \Greenter\Model\Company\Company())
        ->setRuc($empresa->CpyRuc)
        ->setRazonSocial($empresa->CpyBusinessName)
        ->setAddress((new \Greenter\Model\Company\Address())
            ->setUbigueo($empresa->ubigeo)->setDepartamento($empresa->departamento)->setProvincia($empresa->provincia)
            ->setDistrito($empresa->distrito)->setDireccion($empresa->CpyAddress)->setCodLocal('0000'));

    $invoice = (new \Greenter\Model\Sale\Invoice())
        ->setUblVersion('2.1')->setTipoOperacion('0101')->setTipoDoc('01')
        ->setSerie('F001')->setCorrelativo('4')->setFechaEmision(new \DateTime())
        ->setTipoMoneda('PEN')->setClient($client)->setCompany($company)
        ->setMtoOperGravadas(50.00) // Sumatoria de Valor Venta (100) menos descuentos globales anticipo base (50) = 50
        ->setMtoIGV(9.00) // IGV de la operación gravada restante
        ->setTotalImpuestos(9.00) // Total de impuestos restantes
        ->setValorVenta(100.00) // Sumatoria de valor venta (de los detalles)
        ->setSubTotal(118.00) // Valor Venta detallado (100) + IGV detallado (18)
        ->setTotalAnticipos(59.00) // Monto de anticipo que se resta (Monto total con IGV)
        ->setMtoImpVenta(59.00) // SubTotal (118) - Anticipos (59) = 59
        ->setFormaPago(new \Greenter\Model\Sale\FormaPagos\FormaPagoContado());

    // El anticipo cuenta como un Descuento Global tipo 04
    $descuentoAnticipo = (new \Greenter\Model\Sale\Charge())
        ->setCodTipo('04') // 04: Descuento global por anticipo gravado
        ->setFactor(1.00)
        ->setMonto(50.00) // Base imponible del anticipo
        ->setMontoBase(50.00); 

    $invoice->setDescuentos([$descuentoAnticipo]);

    // Se vincula el anticipo realizado previamente (Factura de anticipo)
    $anticipo = (new \Greenter\Model\Sale\Prepayment())
        ->setTipoDocRel('02') 
        ->setNroDocRel('F001-1')
        ->setTotal(59.00);

    $invoice->setAnticipos([$anticipo]);

    $item = (new \Greenter\Model\Sale\SaleDetail())
        ->setCodProducto('P002')
        ->setUnidad('NIU')->setCantidad(1)
        ->setDescripcion('PRODUCTO DE PRUEBA GRAVADO CON ANTICIPO')
        ->setMtoBaseIgv(100.00) // Base imponible detallada
        ->setPorcentajeIgv(18.00)
        ->setIgv(18.00) // IGV detallado
        ->setTipAfeIgv('10')
        ->setTotalImpuestos(18.00)
        ->setMtoValorVenta(100.00)
        ->setMtoValorUnitario(100.00)
        ->setMtoPrecioUnitario(118.00);
        
    $invoice->setDetails([$item])
            ->setLegends([
                (new \Greenter\Model\Sale\Legend())
                    ->setCode('1000')
                    ->setValue('SON CINCUENTA Y NUEVE Y 00/100 SOLES')
            ]);

    $name = $invoice->getName();
    $xml = $see->getXmlSigned($invoice);
    \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/01/test/{$name}.xml", $xml);
    $this->info("✅ XML guardado en: storage/app/sunat/01/test/{$name}.xml");

    $this->info("Enviando factura {$name}...");
    try {
        $result = $see->send($invoice);
        if ($result->isSuccess()) {
            $cdr = $result->getCdrResponse();
            $this->info('✅ ¡ÉXITO! Factura aceptada. ID: ' . $cdr->getId());
            $cdrZip = $result->getCdrZip();
            if ($cdrZip) {
                \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/01/test/R-{$name}.zip", $cdrZip);
                $this->info("✅ CDR guardado en: storage/app/sunat/01/test/R-{$name}.zip");
            }
        } else {
            $this->error('❌ Error: ' . $result->getError()->getMessage());
            $this->error('Código err: ' . $result->getError()->getCode());
        }
    } catch (\Exception $e) { $this->error('💥 Excepción: ' . $e->getMessage()); }
})->purpose('Prueba de envío de Factura regularizando un Anticipo');

Artisan::command('sunat:test-guia', function (\App\Services\Sunat\SunatRestService $restService, EmpresaRepository $empresaRepo) {
    $this->info('Iniciando prueba de Guía de Remisión Remitente via API REST GRE...');

    $empresa = $empresaRepo->getActive();
    if (!$empresa) {
        $this->error('No se encontró una empresa activa.');
        return;
    }

    $destinatario = (new \Greenter\Model\Client\Client())
        ->setTipoDoc('6')
        ->setNumDoc('20405544458')
        ->setRznSocial('CLIENTE DE PRUEBA');

    $shipment = (new \Greenter\Model\Despatch\Shipment())
        ->setModTraslado('02') // Privado
        ->setCodTraslado('01') // Venta
        ->setFecTraslado(new \DateTime('+1 day'))
        ->setPesoTotal(100)
        ->setUndPesoTotal('KGM')
        ->setPartida(new \Greenter\Model\Despatch\Direction($empresa->ubigeo, $empresa->CpyAddress))
        ->setLlegada(new \Greenter\Model\Despatch\Direction('150101', 'AV. CENTRAL 456'))
        ->setVehiculo((new \Greenter\Model\Despatch\Vehicle())->setPlaca('V3X998'))
        ->setChoferes([
            (new \Greenter\Model\Despatch\Driver())
                ->setTipoDoc('1')
                ->setNroDoc('44445555')
                ->setNombres('JUAN')
                ->setApellidos('PEREZ GARCIA')
                ->setLicencia('Q98765432')
                ->setTipo('Principal')
        ]);

    $despatch = (new \Greenter\Model\Despatch\Despatch())
        ->setVersion('2022')
        ->setTipoDoc('09')
        ->setSerie('T001')
        ->setCorrelativo('200')
        ->setFechaEmision(new \DateTime())
        ->setCompany((new \Greenter\Model\Company\Company())
            ->setRuc($empresa->CpyRuc)
            ->setRazonSocial($empresa->CpyBusinessName)
            ->setAddress((new \Greenter\Model\Company\Address())
                ->setUbigueo($empresa->ubigeo)
                ->setDepartamento($empresa->departamento)
                ->setProvincia($empresa->provincia)
                ->setDistrito($empresa->distrito)
                ->setDireccion($empresa->CpyAddress)
                ->setCodLocal('0000')))
        ->setDestinatario($destinatario)
        ->setEnvio($shipment)
        ->setDetails([
            (new \Greenter\Model\Despatch\DespatchDetail())
                ->setCodigo('P001')
                ->setDescripcion('PRODUCTO DE PRUEBA')
                ->setUnidad('NIU')
                ->setCantidad(10)
        ]);

    // Guardar XML
    $greenterService = app(\App\Services\Sunat\GreenterService::class);
    $xml = $greenterService->getXml($despatch);
    $name = $despatch->getName();
    \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/09/test/{$name}.xml", $xml);
    $this->info("✅ XML guardado en: storage/app/sunat/09/test/{$name}.xml");

    // Enviar via REST
    $this->comment("Enviando {$name} por API REST GRE...");
    $result = $restService->send($despatch);

    if ($result['success']) {
        $this->info('✅ Envío exitoso! Ticket: ' . $result['ticket']);
        $status = $restService->getStatus($result['ticket']);
        if ($status['success']) {
            $this->info('Estado: ' . $status['cod_respuesta']);
            if (!empty($status['arc_cdr'])) {
                \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/09/test/R-{$name}.zip", base64_decode($status['arc_cdr']));
                $this->info("✅ CDR guardado en: storage/app/sunat/09/test/R-{$name}.zip");
            }
            if (isset($status['error'])) {
                $this->error('Errores SUNAT: ' . json_encode($status['error']));
            }
        } else {
            $this->error('Error al consultar estado: ' . $status['error']);
        }
    } else {
        $this->error('❌ Error: ' . $result['error']);
    }
})->purpose('Test Guía de Remisión Remitente (tipo 09) via API REST GRE');

Artisan::command('sunat:test-guia-transportista', function (\App\Services\Sunat\SunatRestService $restService, EmpresaRepository $empresaRepo) {
    $this->info('Iniciando prueba de Guía de Remisión Transportista (tipo 31) via API REST GRE...');

    $empresa = $empresaRepo->getActive();
    if (!$empresa) {
        $this->error('No se encontró una empresa activa.');
        return;
    }

    $data = \App\DTO\GuiaData::fromArray([
        'tipoDoc' => '31', 
        'serie' => 'V001',
        'correlativo' => '3',
        'fechaEmision' => now()->format('Y-m-d'),
        'fechaTraslado' => now()->addDay()->format('Y-m-d'),
        'modalidadTraslado' => '01', 
        'motivoTraslado' => '01',    
        'pesoTotal' => '500',
        'unidadMedida' => 'KGM',
        'destinatarioTipoDoc' => '6',
        'destinatarioNumDoc' => '20405544458',
        'destinatarioRznSocial' => 'EMPRESA DESTINATARIO SAC',
        'remitenteTipoDoc' => '6',
        'remitenteNumDoc' => '20405544458',
        'remitenteRznSocial' => 'EMPRESA REMITENTE SAC',
        'transportistaTipoDoc' => '6',
        'transportistaNumDoc' => $empresa->CpyRuc,
        'transportistaRznSocial' => $empresa->CpyBusinessName,
        'transportistaNroMtc' => '100101',
        'direccionPartida' => 'ALMACEN REMITENTE 123',
        'ubigeoPartida' => '150101',
        'direccionLlegada' => 'ALMACEN DESTINO 456',
        'ubigeoLlegada' => '150101',
        'placaVehiculo' => 'TRA999',
        'choferTipoDoc' => '1',
        'choferNumDoc' => '77778888',
        'choferNombres' => 'PEDRO',
        'choferApellidos' => 'GOMEZ LOPEZ',
        'choferLicencia' => 'A12345678',
        'relatedDocs' => [
            [
                'tipo' => '01',
                'nro' => 'F001-100',
                'tipoDesc' => 'Factura',
                'emisor' => $empresa->CpyRuc,
            ]
        ],
        'details' => [
            ['codigo' => 'SER001', 'descripcion' => 'TRANSPORTE CARGA PESADA', 'unidad' => 'NIU', 'cantidad' => 1],
        ],
    ]);

    $guiaService = app(\App\Services\Facturacion\GuiaService::class);
    $reflect = new \ReflectionClass($guiaService);
    $method = $reflect->getMethod('mapToDespatch');
    $method->setAccessible(true);
    $despatch = $method->invoke($guiaService, $data);

    // Guardar XML
    $greenterService = app(\App\Services\Sunat\GreenterService::class);
    $xml = $greenterService->getXml($despatch);
    $name = $despatch->getName();
    \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/31/test/{$name}.xml", $xml);
    $this->info("✅ XML guardado en: storage/app/sunat/31/test/{$name}.xml");

    // Enviar via REST
    $this->comment("Enviando {$name} por API REST GRE...");
    $result = $restService->send($despatch);

    if ($result['success']) {
        $this->info('✅ Envío exitoso! Ticket: ' . $result['ticket']);
        $status = $restService->getStatus($result['ticket']);
        if ($status['success']) {
            $this->info('Estado: ' . $status['cod_respuesta']);
            if (!empty($status['arc_cdr'])) {
                \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/31/test/R-{$name}.zip", base64_decode($status['arc_cdr']));
                $this->info("✅ CDR guardado en: storage/app/sunat/31/test/R-{$name}.zip");
            }
            if (isset($status['error'])) {
                $this->error('Errores SUNAT: ' . json_encode($status['error']));
            }
        } else {
            $this->error('Error al consultar estado: ' . $status['error']);
        }
    } else {
        $this->error('❌ Error: ' . $result['error']);
    }
})->purpose('Test Guía de Remisión Transportista (tipo 31) via API REST GRE');

Artisan::command('sunat:test-gre-rest-privado', function (\App\Services\Sunat\SunatRestService $restService, EmpresaRepository $empresaRepo) {
    $this->info("Iniciando prueba de envío de Guía GRE via API REST (Beta)...");

    $empresa = $empresaRepo->getActive();
    if (!$empresa) {
        $this->error('No se encontró una empresa activa.');
        return;
    }

    $data = \App\DTO\GuiaData::fromArray([
        'tipoDoc' => '09',
        'serie' => 'T001',
        'correlativo' => '1',
        'fechaEmision' => now()->format('Y-m-d'),
        'fechaTraslado' => now()->addDay()->format('Y-m-d'),
        'modalidadTraslado' => '02',
        'motivoTraslado' => '01',
        'pesoTotal' => '10.5',
        'unidadMedida' => 'KGM',
        'destinatarioTipoDoc' => '6',
        'destinatarioNumDoc' => '20405544458',
        'destinatarioRznSocial' => 'CLIENTE PRUEBA REST',
        'direccionPartida' => $empresa->CpyAddress,
        'ubigeoPartida' => $empresa->ubigeo,
        'direccionLlegada' => 'AV. AREQUIPA 456',
        'ubigeoLlegada' => '150102',
        'placaVehiculo' => 'F4A888',
        'choferTipoDoc' => '1',
        'choferNumDoc' => '12345678',
        'choferNombres' => 'CARLOS',
        'choferApellidos' => 'RODRIGUEZ PEREZ',
        'choferLicencia' => 'Q12345678',
        'relatedDocs' => [
            [
                'tipo' => '01',
                'nro' => 'F001-123',
                'tipoDesc' => 'Factura',
                'emisor' => $empresa->CpyRuc,
            ]
        ],
        'details' => [
            ['codigo' => 'P001', 'descripcion' => 'PRODUCTO REST', 'unidad' => 'KGM', 'cantidad' => 5],
        ],
    ]);

    $guiaService = app(\App\Services\Facturacion\GuiaService::class);
    $reflect = new \ReflectionClass($guiaService);
    $method = $reflect->getMethod('mapToDespatch');
    $method->setAccessible(true);
    $despatch = $method->invoke($guiaService, $data);

    // Generar y guardar el XML
    $greenterService = app(\App\Services\Sunat\GreenterService::class);
    $xml = $greenterService->getXml($despatch);
    \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/09/test/{$data->serie}-{$data->correlativo}.xml", $xml);
    $this->info("✅ XML generado y guardado en: storage/app/sunat/09/test/{$data->serie}-{$data->correlativo}.xml");

    // Debug: mostrar info del token
    try {
        $tokenDebug = app(\App\Services\Sunat\SunatRestService::class);
        $reflect2 = new \ReflectionClass($tokenDebug);
        $getToken = $reflect2->getMethod('getToken');
        $getToken->setAccessible(true);
        $tok = $getToken->invoke($tokenDebug);
        $this->comment("Token obtenido: " . substr($tok->getAccessToken(), 0, 30) . "...");
        $this->comment("Tipo: " . $tok->getTokenType() . " | Expira en: " . $tok->getExpiresIn() . "s");
    } catch (\Exception $e) {
        $this->error("❌ Error al obtener token: " . $e->getMessage());
        return;
    }

    $this->comment("Enviando guía T001-1...");
    $result = $restService->send($despatch);

    if ($result['success']) {
        $this->info("✅ Envío exitoso!");
        $this->info("Ticket: " . $result['ticket']);
        
        $this->comment("Consultando estado del ticket...");
        
        $status = $restService->getStatus($result['ticket']);
        
        if ($status['success']) {
            $this->info("Estado: " . $status['cod_respuesta']);
            if (isset($status['description'])) {
                $this->info("Respuesta CDR: " . $status['description']);
            }
            
            if (!empty($status['arc_cdr'])) {
                $cdrZip = base64_decode($status['arc_cdr']);
                \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/09/test/R-{$data->serie}-{$data->correlativo}.zip", $cdrZip);
                $this->info("✅ CDR (ZIP) guardado en: storage/app/sunat/09/test/R-{$data->serie}-{$data->correlativo}.zip");
            }

            if (isset($status['error'])) {
                 $this->error("Errores: " . json_encode($status['error']));
            }
        } else {
            $this->error("Error al consultar: " . $status['error']);
        }
    } else {
        $this->error("❌ Error: " . $result['error']);
        if (str_contains($result['error'], '401')) {
            $this->line("<fg=yellow>⚠ Asegúrate de configurar SUNAT_CLIENT_ID y SUNAT_CLIENT_SECRET en .env</>");
        }
    }
})->purpose('Test Guía de Remisión via API REST (Modalidad 02 - Privado)');

Artisan::command('sunat:test-gre-rest-publico', function (\App\Services\Sunat\SunatRestService $restService, EmpresaRepository $empresaRepo) {
    $this->info("Iniciando prueba de Guía GRE Modalidad 01 (Transporte Público) via API REST...");

    $empresa = $empresaRepo->getActive();
    if (!$empresa) {
        $this->error('No se encontró una empresa activa.');
        return;
    }

    $data = \App\DTO\GuiaData::fromArray([
        'tipoDoc' => '09',
        'serie' => 'T001',
        'correlativo' => '3',
        'fechaEmision' => now()->format('Y-m-d'),
        'fechaTraslado' => now()->addDay()->format('Y-m-d'),
        'modalidadTraslado' => '01', 
        'motivoTraslado' => '01',    
        'pesoTotal' => '250.5',
        'unidadMedida' => 'KGM',
        'destinatarioTipoDoc' => '6',
        'destinatarioNumDoc' => '20405544458',
        'destinatarioRznSocial' => 'CLIENTE PRUEBA PUBLICO',
        'direccionPartida' => $empresa->CpyAddress,
        'ubigeoPartida' => $empresa->ubigeo,
        'direccionLlegada' => 'AV. COMERCIAL 800',
        'ubigeoLlegada' => '150102',
        'transportistaTipoDoc' => '6',
        'transportistaNumDoc' => '20000000002',
        'transportistaRznSocial' => 'TRANSPORTES RAPIDO SAC',
        'relatedDocs' => [
            [
                'tipo' => '01',
                'nro' => 'F001-456',
                'tipoDesc' => 'Factura',
                'emisor' => $empresa->CpyRuc,
            ]
        ],
        'details' => [
            ['codigo' => 'P002', 'descripcion' => 'MANGO FRESCO KG', 'unidad' => 'KGM', 'cantidad' => 250],
        ],
    ]);

    $guiaService = app(\App\Services\Facturacion\GuiaService::class);
    $reflect = new \ReflectionClass($guiaService);
    $method = $reflect->getMethod('mapToDespatch');
    $method->setAccessible(true);
    $despatch = $method->invoke($guiaService, $data);

    // Guardar XML
    $greenterService = app(\App\Services\Sunat\GreenterService::class);
    $xml = $greenterService->getXml($despatch);
    $name = $despatch->getName();
    \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/09/test/{$name}.xml", $xml);
    $this->info("✅ XML guardado en: storage/app/sunat/09/test/{$name}.xml");

    // Debug: mostrar info del token
    try {
        $tokenDebug = app(\App\Services\Sunat\SunatRestService::class);
        $reflect2 = new \ReflectionClass($tokenDebug);
        $getToken = $reflect2->getMethod('getToken');
        $getToken->setAccessible(true);
        $tok = $getToken->invoke($tokenDebug);
        $this->comment("Token obtenido: " . substr($tok->getAccessToken(), 0, 30) . "...");
        $this->comment("Tipo: " . $tok->getTokenType() . " | Expira en: " . $tok->getExpiresIn() . "s");
    } catch (\Exception $e) {
        $this->error("❌ Error al obtener token: " . $e->getMessage());
        return;
    }

    // Enviar via REST
    $this->comment("Enviando {$name} por API REST GRE (Modalidad 01 - Público)...");
    $result = $restService->send($despatch);

    if ($result['success']) {
        $this->info("✅ Envío exitoso! Ticket: " . $result['ticket']);
        $status = $restService->getStatus($result['ticket']);
        if ($status['success']) {
            $this->info("Estado: " . $status['cod_respuesta']);
            if (isset($status['description'])) {
                $this->info("Respuesta CDR: " . $status['description']);
            }
            if (!empty($status['arc_cdr'])) {
                \Illuminate\Support\Facades\Storage::disk('local')->put("sunat/09/test/R-{$name}.zip", base64_decode($status['arc_cdr']));
                $this->info("✅ CDR guardado en: storage/app/sunat/09/test/R-{$name}.zip");
            }
            if (isset($status['error'])) {
                $this->error("Errores: " . json_encode($status['error']));
            }
        } else {
            $this->error("Error al consultar: " . $status['error']);
        }
    } else {
        $this->error("❌ Error: " . $result['error']);
    }
})->purpose('Test Guía de Remisión via API REST (Modalidad 01 - Transporte Público)');

Artisan::command('sunat:test-gre-history {ticket}', function ($ticket) {
    $this->info("Consultando historial/estado del ticket GRE: {$ticket}");

    try {
        $restService = app(\App\Services\Sunat\SunatRestService::class);
        $status = $restService->getStatus($ticket);

        if ($status['success']) {
            $this->info("Estado del Ticket (cod_respuesta): " . $status['cod_respuesta']);
            
            if (!empty($status['arc_cdr'])) {
                $cdrZip = base64_decode($status['arc_cdr']);
                $path = "sunat/09/test/R-HISTORIAL-{$ticket}.zip";
                \Illuminate\Support\Facades\Storage::disk('local')->put($path, $cdrZip);
                $this->info("✅ Historial CDR descargado y guardado en: storage/app/{$path}");
            } else {
                $this->comment("El ticket no tiene CDR adjunto todavía.");
            }

            if (isset($status['error'])) {
                 $this->error("Errores registrados en el historial: " . json_encode($status['error']));
            }
        } else {
            $this->error("❌ Error al consultar ticket: " . $status['error']);
        }
    } catch (\Exception $e) {
        $this->error("❌ Excepción en consulta de historial: " . $e->getMessage());
    }
})->purpose('Consultar historial de una Guía de Remisión (GRE) por ticket devuelto');
