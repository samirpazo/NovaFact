<?php

namespace App\Console\Commands;

use App\Models\ApiCredential;
use App\Models\McrApiClient;
use App\Services\Auth\ApiCredentialService;
use Illuminate\Console\Command;

final class ManageApiCredential extends Command
{
    protected $signature = 'billing:credential {action : issue or revoke} {--client=} {--company=*} {--permission=*} {--id=}';

    protected $description = 'Issue a consumer credential (shown once) or revoke its ID.';

    public function handle(ApiCredentialService $service): int
    {
        if ($this->argument('action') === 'revoke') {
            $credential = ApiCredential::findOrFail((int) $this->option('id'));
            $credential->update(['revoked_at' => now()]);
            $this->info('Credential revoked.');

            return self::SUCCESS;
        }
        if ($this->argument('action') !== 'issue') {
            $this->error('Use issue or revoke.');

            return self::FAILURE;
        }
        $client = McrApiClient::where('McrCode', $this->option('client'))->firstOrFail();
        $issued = $service->issue($client->getKey(), $this->option('company'), $this->option('permission'));
        $this->info('Credential ID: '.$issued['credential']->id);
        $this->line($issued['token']);

        return self::SUCCESS;
    }
}
