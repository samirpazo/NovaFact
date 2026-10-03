<?php

use App\Models\Empresa;
use App\Models\McrEstablishment;
use App\Models\McrSeries;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (config('database.default') !== 'pgsql' || config('database.connections.pgsql.host') !== '127.0.0.1' || config('database.connections.pgsql.database') !== 'postgres' || app()->environment() !== 'testing' || ! str_starts_with(storage_path(), '/private/tmp/novafact-http-storage') || config('database.connections.pgsql.port') != 55439 || config('database.connections.pgsql.search_path') !== 'readiness_http' || config('sunat.production')) {
    throw new RuntimeException('isolated beta only');
}
$c = Empresa::firstOrFail();
$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => 'NovaFact Demo'], $key);
$cert = openssl_csr_sign($csr, null, $key, 1);
openssl_x509_export($cert, $pem);
openssl_pkey_export($key, $private);
Storage::disk('local')->put('certificates/demo.pem', $pem."\n".$private);
$c->update(['McrCertificateName' => 'demo.pem', 'McrSolUser' => 'MODDATOS', 'McrSolPassword' => 'MODDATOS']);
$e = McrEstablishment::firstOrCreate(['McrCompanyConfigID' => $c->getKey(), 'McrExternalCode' => 'DEFAULT'], ['McrSunatCode' => '0000', 'McrName' => 'NovaFact Demo', 'McrAddress' => 'Av. Demo 123', 'McrUbigeo' => '150101', 'McrDepartment' => 'LIMA', 'McrProvince' => 'LIMA', 'McrDistrict' => 'LIMA', 'McrCountryCode' => 'PE', 'McrIsDefault' => true, 'McrIsActive' => true, 'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now()]);
foreach ([['01', 'F001'], ['03', 'B001'], ['07', 'FC01'], ['08', 'FD01'], ['09', 'T001'], ['31', 'V001']] as [$type,$series]) {
    McrSeries::firstOrCreate(['McrCompanyConfigID' => $c->getKey(), 'McrEstablishmentID' => $e->getKey(), 'McrDocumentType' => $type, 'McrSeriesCode' => $series], ['McrNextCorrelative' => random_int(100000, 999000), 'McrIsActive' => true, 'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now()]);
}
echo 'Isolated beta fixtures prepared';
