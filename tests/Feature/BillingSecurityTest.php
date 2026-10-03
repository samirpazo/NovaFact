<?php

use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../Support/PipelineDatabase.php';
beforeEach(function () {
    bootPipelineDatabase();
});
afterEach(function () {
    if (isset($this->pipelineSchema)) {
        DB::statement('DROP SCHEMA "'.$this->pipelineSchema.'" CASCADE');
        DB::disconnect('pipeline_test');
    }
});

it('rechaza requests sin token válido', function () {
    $this->postJson('/api/facturacion/emitir-factura', [])->assertUnauthorized();
});

it('rechaza una factura con datos tributarios inválidos', function () {
    $this->withHeader('Authorization', 'Bearer test-token')
        ->postJson('/api/facturacion/emitir-factura', [
            'tipoDoc' => '01',
            'fechaEmision' => '2026-09-12',
            'tipoMoneda' => 'PEN',
            'clientTipoDoc' => '1',
            'clientNumDoc' => '123',
            'clientRznSocial' => 'Cliente',
            'mtoOperGravada' => 100,
            'mtoIGV' => 18,
            'mtoTotal' => 118,
            'items' => [],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['items']);
});

it('no permite acceso a archivos fuera de las rutas gestionadas', function () {
    $this->withHeader('Authorization', 'Bearer test-token')
        ->getJson('/api/facturacion/archivo/pdf/..%2F.env')
        ->assertStatus(404);
});
