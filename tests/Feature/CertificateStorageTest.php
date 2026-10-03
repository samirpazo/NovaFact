<?php

use App\Services\Sunat\CertificateService;
use Illuminate\Support\Facades\Storage;

it('reads certificates uploaded to the configured private local disk and refuses traversal', function () {
    Storage::fake('local');
    Storage::disk('local')->put('certificates/uploaded.pem', 'test certificate');
    expect(app(CertificateService::class)->getCertificate('uploaded.pem'))->toBe('test certificate')
        ->and(app(CertificateService::class)->getCertificate('../.env'))->toBeNull();
});
