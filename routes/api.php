<?php

use App\Http\Controllers\Api\FacturacionController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\WebhookSubscriptionController;

Route::middleware(['session.token'])->group(function () {
    Route::prefix('webhooks/subscriptions')->group(function () {
        Route::get('/', [WebhookSubscriptionController::class, 'index']);
        Route::post('/', [WebhookSubscriptionController::class, 'store']);
        Route::get('{id}', [WebhookSubscriptionController::class, 'show'])->whereNumber('id');
        Route::patch('{id}', [WebhookSubscriptionController::class, 'update'])->whereNumber('id');
        Route::delete('{id}', [WebhookSubscriptionController::class, 'destroy'])->whereNumber('id');
        Route::post('{id}/rotate-secret', [WebhookSubscriptionController::class, 'rotate'])->whereNumber('id');
    });
    Route::prefix('facturacion')->group(function () {
        Route::post('emitir-factura', [FacturacionController::class, 'emitFactura']);
        Route::post('boletas/resumen-diario', [FacturacionController::class, 'resumenBoletas']);
        Route::post('boletas/baja', [FacturacionController::class, 'baja']);
        Route::get('boletas/resumen/{ticket}', [FacturacionController::class, 'estadoResumen']);
        Route::get('submissions/{submissionId}', [FacturacionController::class, 'estadoEnvio']);
        Route::get('configuracion/empresa', [FacturacionController::class, 'companyConfig']);
        Route::put('configuracion/empresa', [FacturacionController::class, 'updateCompanyConfig']);
        Route::post('configuracion/empresa/logo', [FacturacionController::class, 'updateCompanyLogo']);
        Route::put('configuracion/empresa/credenciales-sol', [FacturacionController::class, 'updateSolCredentials']);
        Route::post('configuracion/empresa/certificado', [FacturacionController::class, 'updateCertificate']);
        Route::put('configuracion/empresa/series', [FacturacionController::class, 'updateSeries']);
        Route::get('documentos/{documentId}/ticket-80mm', [FacturacionController::class, 'ticket80mm'])
            ->whereNumber('documentId');
        Route::post('emitir-guia', [FacturacionController::class, 'emitGuia']);
        Route::get('historial-guia/{ticket}', [FacturacionController::class, 'consultarHistorialGuia']);
        Route::get('archivo/{tipo}/{nombre}', [FacturacionController::class, 'descargarArchivo'])
            ->whereIn('tipo', ['pdf', 'xml', 'zip', 'cdr'])
            ->where('nombre', '[A-Za-z0-9._-]+');
    });
});
