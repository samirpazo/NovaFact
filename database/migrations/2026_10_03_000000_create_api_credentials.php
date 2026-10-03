<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->string('token_hash', 64)->unique();
            $table->json('permissions');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('created_at');
            $table->foreign('client_id')->references('McrApiClientID')->on('McrApiClient');
        });
        Schema::create('api_credential_companies', function (Blueprint $table) {
            $table->unsignedBigInteger('credential_id');
            $table->integer('company_id');
            $table->primary(['credential_id', 'company_id']);
            $table->foreign('credential_id')->references('id')->on('api_credentials')->cascadeOnDelete();
            $table->foreign('company_id')->references('McrCompanyConfigID')->on('McrCompanyConfig');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_credential_companies');
        Schema::dropIfExists('api_credentials');
    }
};
