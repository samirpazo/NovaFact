<?php

namespace App\Providers;

use App\Services\Sunat\GreTransport;
use App\Services\Sunat\SunatGreTransport;
use App\Services\Sunat\SoapStatusConsultant;
use App\Services\Sunat\SunatSoapStatusConsultant;
use App\Services\Webhooks\DnsResolver;
use App\Services\Webhooks\NativeDnsResolver;
use App\Services\Webhooks\WebhookTransport;
use App\Services\Webhooks\HttpWebhookTransport;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GreTransport::class, SunatGreTransport::class);
        $this->app->bind(SoapStatusConsultant::class, SunatSoapStatusConsultant::class);
        $this->app->bind(DnsResolver::class, NativeDnsResolver::class);
        $this->app->bind(WebhookTransport::class, HttpWebhookTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
