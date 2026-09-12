<?php

use App\Http\Controllers\Api\FacturacionController;
use Illuminate\Support\Facades\Route;

Route::middleware('session.token')->group(function () {
    Route::prefix('facturacion')->group(function () {
        Route::post('emitir-factura', [FacturacionController::class, 'emitFactura']);
        Route::post('emitir-guia', [FacturacionController::class, 'emitGuia']);
        Route::get('historial-guia/{ticket}', [FacturacionController::class, 'consultarHistorialGuia']);
    });
});
