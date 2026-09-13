<?php

namespace App\Providers;

use App\Services\Sunat\GreTransport;
use App\Services\Sunat\SunatGreTransport;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void { $this->app->bind(GreTransport::class, SunatGreTransport::class); }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
