<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $audit = static function (Blueprint $table): void {
            $table->boolean('SecStatus')->default(true);
            $table->integer('CreateUserId')->default(0);
            $table->integer('UpdateUserId')->nullable();
            $table->integer('DeleteUserId')->nullable();
            $table->timestampTz('CreateDate')->useCurrent();
            $table->timestampTz('UpdateDate')->nullable();
            $table->timestampTz('DeleteDate')->nullable();
        };

        Schema::create('McrCompanyConfig', function (Blueprint $table) use ($audit) {
            $table->increments('McrCompanyConfigID');
            $table->string('McrRuc', 11);
            $table->string('McrBusinessName', 250);
            $table->string('McrTradeName', 250)->nullable();
            $table->string('McrUbigeo', 6)->nullable();
            $table->string('McrDepartment', 100)->nullable();
            $table->string('McrProvince', 100)->nullable();
            $table->string('McrDistrict', 100)->nullable();
            $table->string('McrUrbanization', 150)->nullable();
            $table->string('McrAddress', 500)->nullable();
            $table->string('McrSolUser', 100)->nullable();
            $table->text('McrSolPassword')->nullable();
            $table->string('McrCertificateName', 255)->nullable();
            $table->text('McrCertificatePassword')->nullable();
            $table->string('McrClientId', 255)->nullable();
            $table->text('McrClientSecret')->nullable();
            $table->string('McrEnvironment', 20)->default('beta');
            $table->string('McrLogoPath', 500)->nullable();
            $table->string('McrCurrencyCode', 3)->default('PEN');
            $table->decimal('McrIgvRate', 8, 4)->default(18.0000);
            $table->decimal('McrIpmRate', 8, 4)->default(0.0000);
            $table->decimal('McrTotalTaxRate', 8, 4)->default(18.0000);
            $table->boolean('McrSpecialTaxRegime')->default(false);
            $table->string('McrPhone', 50)->nullable();
            $table->string('McrEmail', 250)->nullable();
            $table->boolean('McrIsActive')->default(true);
            $audit($table);

            $table->unique(['McrRuc', 'McrEnvironment'], 'UX_McrCompanyConfig_RucEnvironment');
        });

        Schema::create('McrEstablishment', function (Blueprint $table) use ($audit) {
            $table->increments('McrEstablishmentID');
            $table->integer('McrCompanyConfigID');
            $table->string('McrExternalCode', 100);
            $table->char('McrSunatCode', 4);
            $table->string('McrName', 250);
            $table->string('McrTradeName', 250)->nullable();
            $table->string('McrAddress', 500);
            $table->string('McrAddressReference', 500)->nullable();
            $table->char('McrUbigeo', 6);
            $table->string('McrDepartment', 100)->nullable();
            $table->string('McrProvince', 100)->nullable();
            $table->string('McrDistrict', 100)->nullable();
            $table->char('McrCountryCode', 2)->default('PE');
            $table->string('McrPhone', 50)->nullable();
            $table->string('McrEmail', 250)->nullable();
            $table->boolean('McrIsDefault')->default(false);
            $table->boolean('McrIsActive')->default(true);
            $audit($table);

            $table->foreign('McrCompanyConfigID')->references('McrCompanyConfigID')->on('McrCompanyConfig');
            $table->unique(['McrCompanyConfigID', 'McrExternalCode'], 'UX_McrEstablishment_CompanyExternal');
            $table->unique(['McrEstablishmentID', 'McrCompanyConfigID'], 'UX_McrEstablishment_IDCompany');
            $table->index(['McrCompanyConfigID', 'McrIsActive'], 'IX_McrEstablishment_CompanyActive');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX "UX_McrEstablishment_ActiveDefault" ON "McrEstablishment" ("McrCompanyConfigID") WHERE "McrIsDefault" = true AND "McrIsActive" = true AND "SecStatus" = true');
        }

        Schema::create('McrApiClient', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrApiClientID');
            $table->string('McrCode', 80)->unique();
            $table->string('McrName', 200);
            $table->boolean('McrIsActive')->default(true);
            $audit($table);
        });

        Schema::create('McrSeries', function (Blueprint $table) use ($audit) {
            $table->increments('McrSeriesID');
            $table->integer('McrCompanyConfigID');
            $table->integer('McrEstablishmentID')->nullable();
            $table->string('McrDocumentType', 2);
            $table->string('McrSeriesCode', 4);
            $table->bigInteger('McrNextCorrelative')->default(1);
            $table->boolean('McrIsActive')->default(true);
            $audit($table);

            $table->foreign('McrCompanyConfigID')->references('McrCompanyConfigID')->on('McrCompanyConfig')->cascadeOnDelete();
            $table->foreign(['McrEstablishmentID', 'McrCompanyConfigID'], 'FK_McrSeries_EstablishmentCompany')
                ->references(['McrEstablishmentID', 'McrCompanyConfigID'])->on('McrEstablishment');
            $table->unique(['McrCompanyConfigID', 'McrDocumentType', 'McrSeriesCode'], 'UX_McrSeries_CompanyTypeCode');
            $table->index(['McrEstablishmentID', 'McrDocumentType', 'McrIsActive'], 'IX_McrSeries_EstablishmentTypeActive');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('McrSeries');
        Schema::dropIfExists('McrApiClient');
        Schema::dropIfExists('McrEstablishment');
        Schema::dropIfExists('McrCompanyConfig');
    }
};
