<?php

namespace App\Providers;

use App\Services\Sunat\GreTransport;
use App\Services\Sunat\SunatGreTransport;
use App\Services\Sunat\SoapStatusConsultant;
use App\Services\Sunat\SunatSoapStatusConsultant;

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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
