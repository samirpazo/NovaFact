<?php

use App\Models\Empresa;
use App\Models\McrApiClient;
use App\Models\McrEstablishment;
use App\Models\McrSeries;
use App\Services\Auth\ApiCredentialService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$config = config('database.connections.'.config('database.default'));
$isolated = app()->environment('testing') && $config['database'] === 'postgres' && (int) $config['port'] === 55439
    && $config['search_path'] === 'readiness_gre' && storage_path() === '/private/tmp/novafact-gre-storage';
$local = app()->environment('local') && $config['database'] === 'nova_structural_test' && (int) $config['port'] === 5432;
if (config('sunat.production') || config('database.default') !== 'pgsql' || $config['host'] !== '127.0.0.1' || (! $isolated && ! $local)) {
    throw new RuntimeException('Only the approved local demo or isolated GRE environment may be configured.');
}
$input = json_decode(file_get_contents(base_path('credentials.local.json')), true, 512, JSON_THROW_ON_ERROR);
$gre = $input['sunat']['gre'];
if ($gre['environment'] !== 'beta' || $gre['ruc'] !== '20161515648' || $gre['usuarioSol'] !== 'MODDATOS'
    || $gre['claveSol'] !== 'MODDATOS' || ! str_starts_with($gre['client_id'], 'test-')
    || ! str_starts_with($gre['client_secret'], 'test-') || $gre['auth_url'] !== 'https://gre-test.nubefact.com/v1'
    || $gre['api_url'] !== 'https://gre-test.nubefact.com/v1') {
    throw new RuntimeException('Only public GRE demo credentials are allowed.');
}
if ($isolated) {
    DB::statement('CREATE SCHEMA IF NOT EXISTS readiness_gre');
    if (! Illuminate\Support\Facades\Schema::hasTable('api_credentials')) {
        foreach (glob(database_path('migrations/*.php')) as $file) (require $file)->up();
    }
}
$disk = Storage::disk('local');
if (! $disk->exists('certificates/novafact-demo.pem')) {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $csr = openssl_csr_new(['commonName' => 'NovaFact TEST ONLY'], $key);
    $cert = openssl_csr_sign($csr, null, $key, 30);
    openssl_x509_export($cert, $pem); openssl_pkey_export($key, $private);
    $disk->put('certificates/novafact-demo.pem', $pem."\n".$private);
    chmod($disk->path('certificates/novafact-demo.pem'), 0600);
}
DB::transaction(function () use ($gre, $local, $disk): void {
    if ($local) {
        $soap = Empresa::where('McrRuc', '20123456789')->where('McrEnvironment', 'beta')->firstOrFail();
        $soap->update(['McrSolUser' => 'MODDATOS', 'McrSolPassword' => 'MODDATOS',
            'McrCertificateName' => 'novafact-demo.pem', 'McrCertificatePassword' => null,
            'McrClientId' => null, 'McrClientSecret' => null]);
    }
    $company = Empresa::firstOrCreate(['McrRuc' => $gre['ruc'], 'McrEnvironment' => 'beta'], [
        'McrBusinessName' => 'NovaFact GRE Demo', 'McrTradeName' => 'NovaFact', 'McrAddress' => 'Av. Demo 123',
        'McrUbigeo' => '150101', 'McrDepartment' => 'LIMA', 'McrProvince' => 'LIMA', 'McrDistrict' => 'LIMA',
        'McrIgvRate' => 18, 'McrIsActive' => true, 'SecStatus' => true,
    ]);
    $company->update(['McrSolUser' => 'MODDATOS', 'McrSolPassword' => 'MODDATOS',
        'McrClientId' => $gre['client_id'], 'McrClientSecret' => $gre['client_secret'],
        'McrCertificateName' => 'novafact-demo.pem', 'McrCertificatePassword' => null]);
    $disk->put('facturacion/logo/company-'.$company->getKey().'-logo.png', file_get_contents(public_path('logo-nova.png')));
    $company->update(['McrLogoPath' => 'facturacion/logo/company-'.$company->getKey().'-logo.png']);
    $establishment = McrEstablishment::firstOrCreate(['McrCompanyConfigID' => $company->getKey(), 'McrExternalCode' => 'NOVA-GRE'], [
        'McrSunatCode' => '0000', 'McrName' => 'NovaFact GRE Demo', 'McrAddress' => 'Av. Demo 123', 'McrUbigeo' => '150101',
        'McrDepartment' => 'LIMA', 'McrProvince' => 'LIMA', 'McrDistrict' => 'LIMA', 'McrCountryCode' => 'PE',
        'McrIsDefault' => true, 'McrIsActive' => true, 'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now(),
    ]);
    foreach ([['09', 'T001'], ['31', 'V001']] as [$type, $series]) {
        McrSeries::firstOrCreate(['McrCompanyConfigID' => $company->getKey(), 'McrEstablishmentID' => $establishment->getKey(),
            'McrDocumentType' => $type, 'McrSeriesCode' => $series], ['McrNextCorrelative' => random_int(100000, 999000),
            'McrIsActive' => true, 'SecStatus' => true, 'CreateUserId' => 0, 'CreateDate' => now()]);
    }
    $client = McrApiClient::firstOrCreate(['McrCode' => 'novafact-gre-demo'], ['McrName' => 'NovaFact GRE Demo', 'McrIsActive' => true, 'SecStatus' => true]);
    $path = 'novafact-gre-demo-credential.json';
    $credentialExists = $disk->exists($path) && DB::table('api_credentials')->where('client_id', $client->getKey())->whereNull('revoked_at')->exists();
    if (! $credentialExists) {
        $issued = app(ApiCredentialService::class)->issue($client->getKey(), [$company->getKey()], ['read', 'emit', 'admin']);
        $disk->put($path, json_encode(['token' => $issued['token'], 'company_id' => $company->getKey(), 'client_code' => $client->McrCode], JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
        chmod($disk->path($path), 0600);
    }
    echo json_encode(['company_id' => $company->getKey(), 'ruc' => $company->McrRuc, 'environment' => 'beta',
        'credential_file' => $disk->path($path), 'production' => false], JSON_THROW_ON_ERROR).PHP_EOL;
});
if ($local) {
    $input['sunat']['certificate'] = ['fileName' => 'novafact-demo.pem', 'path' => $disk->path('certificates/novafact-demo.pem'), 'password' => null];
    file_put_contents(base_path('credentials.local.json'), json_encode($input, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL);
    chmod(base_path('credentials.local.json'), 0600);
}
if ($isolated) {
    // Public fixture token: valid exclusively in the isolated readiness_gre schema.
    $client = McrApiClient::where('McrCode', 'novafact-gre-demo')->firstOrFail();
    $credential = App\Models\ApiCredential::where('client_id', $client->getKey())->whereNull('revoked_at')->firstOrFail();
    $credential->update(['token_hash' => hash('sha256', 'isolated-gre-demo-token')]);
    $path = 'novafact-gre-demo-credential.json';
    $record = json_decode($disk->get($path), true, 512, JSON_THROW_ON_ERROR);
    $record['token'] = 'isolated-gre-demo-token';
    $disk->put($path, json_encode($record, JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    chmod($disk->path($path), 0600);
}
