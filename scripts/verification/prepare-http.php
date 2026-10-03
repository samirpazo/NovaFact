<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
use App\Models\ApiCredential;
use App\Models\Empresa;
use App\Models\McrApiClient;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

if (config('database.default') !== 'pgsql' || app()->environment() !== 'testing' || config('database.connections.pgsql.host') !== '127.0.0.1' || (int) config('database.connections.pgsql.port') !== 55439 || config('database.connections.pgsql.database') !== 'postgres' || config('database.connections.pgsql.search_path') !== 'readiness_http' || ! str_starts_with(storage_path(), '/private/tmp/novafact-http-storage')) {
    throw new RuntimeException('Use the isolated verification environment only.');
}
DB::statement('CREATE SCHEMA IF NOT EXISTS readiness_http');
if (Schema::hasTable('api_credentials')) {
    echo "Isolated fixture already exists\n";
    exit;
}
foreach (glob(database_path('migrations/*.php')) as $file) {
    (require $file)->up();
}
$c = Empresa::create(['McrRuc' => '20123456789', 'McrBusinessName' => 'NovaFact Demo', 'McrTradeName' => 'NovaFact', 'McrEnvironment' => 'beta', 'McrAddress' => 'Av. Demo 123', 'McrUbigeo' => '150101', 'McrDepartment' => 'LIMA', 'McrProvince' => 'LIMA', 'McrDistrict' => 'LIMA', 'McrIsActive' => true, 'SecStatus' => true, 'McrIgvRate' => 18]);
$client = McrApiClient::create(['McrCode' => 'novafact-demo', 'McrName' => 'NovaFact Demo', 'McrIsActive' => true, 'SecStatus' => true]);
$token = ApiCredential::create(['client_id' => $client->getKey(), 'token_hash' => hash('sha256', 'isolated-demo-token'), 'permissions' => ['read', 'emit', 'admin'], 'created_at' => now()]);
DB::table('api_credential_companies')->insert(['credential_id' => $token->id, 'company_id' => $c->getKey()]);
echo "Isolated HTTP database prepared\n";
