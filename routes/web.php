<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/docs', 'docs')->name('documentation');

Route::get('/docs/postman', fn () => response()->download(base_path('docs/postman/SunExpert_API.postman_collection.json')));

Route::get('/docs/produccion', fn () => response()->download(base_path('docs/PRODUCCION.md'), 'NovaFact-Produccion.md'));
