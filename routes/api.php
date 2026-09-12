<?php

use App\Http\Controllers\Api\FacturacionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['session.token', 'idempotency'])->group(function () {
    Route::prefix('facturacion')->group(function () {
        Route::post('emitir-factura', [FacturacionController::class, 'emitFactura']);
        Route::post('boletas/resumen-diario', [FacturacionController::class, 'resumenBoletas']);
        Route::post('boletas/baja', [FacturacionController::class, 'baja']);
        Route::get('boletas/resumen/{ticket}', [FacturacionController::class, 'estadoResumen']);
        Route::get('submissions/{submissionId}', [FacturacionController::class, 'estadoEnvio']);
        Route::get('configuracion/empresa', [FacturacionController::class, 'companyConfig']);
        Route::put('configuracion/empresa', [FacturacionController::class, 'updateCompanyConfig']);
        Route::post('configuracion/empresa/logo', [FacturacionController::class, 'updateCompanyLogo']);
        Route::get('documentos/{documentId}/ticket-80mm', [FacturacionController::class, 'ticket80mm'])
            ->whereNumber('documentId');
        Route::post('emitir-guia', [FacturacionController::class, 'emitGuia']);
        Route::get('historial-guia/{ticket}', [FacturacionController::class, 'consultarHistorialGuia']);
        Route::get('archivo/{tipo}/{nombre}', [FacturacionController::class, 'descargarArchivo'])
            ->whereIn('tipo', ['pdf', 'xml', 'cdr'])
            ->where('nombre', '[A-Za-z0-9._-]+');
    });
});
