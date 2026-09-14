<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('McrEstablishment', function (Blueprint $table): void {
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
            $table->boolean('McrIsDefault')->default(false);
            $table->boolean('McrIsActive')->default(true);
            $table->boolean('SecStatus')->default(true);
            $table->integer('CreateUserId')->default(0);
            $table->integer('UpdateUserId')->nullable();
            $table->integer('DeleteUserId')->nullable();
            $table->timestampTz('CreateDate')->useCurrent();
            $table->timestampTz('UpdateDate')->nullable();
            $table->timestampTz('DeleteDate')->nullable();
            $table->foreign('McrCompanyConfigID')->references('McrCompanyConfigID')->on('McrCompanyConfig');
            $table->unique(['McrCompanyConfigID', 'McrExternalCode'], 'UX_McrEstablishment_CompanyExternal');
            $table->unique(['McrEstablishmentID', 'McrCompanyConfigID'], 'UX_McrEstablishment_IDCompany');
            $table->index(['McrCompanyConfigID', 'McrIsActive'], 'IX_McrEstablishment_CompanyActive');
        });

        DB::statement('CREATE UNIQUE INDEX "UX_McrEstablishment_ActiveDefault" ON "McrEstablishment" ("McrCompanyConfigID") WHERE "McrIsDefault" = true AND "McrIsActive" = true AND "SecStatus" = true');

        Schema::table('McrSeries', function (Blueprint $table): void {
            $table->integer('McrEstablishmentID')->nullable()->after('McrCompanyConfigID');
        });
        Schema::table('McrDocument', function (Blueprint $table): void {
            $table->integer('McrEstablishmentID')->nullable()->after('McrCompanyConfigID');
            $table->jsonb('McrEstablishmentSnapshot')->nullable()->after('McrEstablishmentID');
        });

        DB::table('McrCompanyConfig')->orderBy('McrCompanyConfigID')->each(function (object $company): void {
            $address = trim((string) ($company->McrAddress ?? ''));
            $ubigeo = trim((string) ($company->McrUbigeo ?? ''));
            if ($address === '' || ! preg_match('/^\d{6}$/', $ubigeo)) {
                return;
            }
            $id = DB::table('McrEstablishment')->insertGetId([
                'McrCompanyConfigID' => $company->McrCompanyConfigID,
                'McrExternalCode' => 'DEFAULT',
                'McrSunatCode' => '0000',
                'McrName' => $company->McrTradeName ?: $company->McrBusinessName,
                'McrTradeName' => $company->McrTradeName,
                'McrAddress' => $address,
                'McrUbigeo' => $ubigeo,
                'McrDepartment' => $company->McrDepartment,
                'McrProvince' => $company->McrProvince,
                'McrDistrict' => $company->McrDistrict,
                'McrCountryCode' => 'PE',
                'McrIsDefault' => true,
                'McrIsActive' => true,
                'SecStatus' => true,
                'CreateUserId' => 0,
                'CreateDate' => now(),
            ], 'McrEstablishmentID');
            $snapshot = json_encode([
                'external_code' => 'DEFAULT', 'sunat_code' => '0000',
                'name' => $company->McrTradeName ?: $company->McrBusinessName,
                'trade_name' => $company->McrTradeName, 'address' => $address,
                'address_reference' => null, 'ubigeo' => $ubigeo,
                'department' => $company->McrDepartment, 'province' => $company->McrProvince,
                'district' => $company->McrDistrict, 'country_code' => 'PE',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            DB::table('McrSeries')->where('McrCompanyConfigID', $company->McrCompanyConfigID)
                ->whereNull('McrEstablishmentID')->update(['McrEstablishmentID' => $id]);
            DB::table('McrDocument')->where('McrCompanyConfigID', $company->McrCompanyConfigID)
                ->whereNull('McrEstablishmentID')->update(['McrEstablishmentID' => $id, 'McrEstablishmentSnapshot' => $snapshot]);
        });

        Schema::table('McrSeries', function (Blueprint $table): void {
            $table->foreign(['McrEstablishmentID', 'McrCompanyConfigID'], 'FK_McrSeries_EstablishmentCompany')
                ->references(['McrEstablishmentID', 'McrCompanyConfigID'])->on('McrEstablishment');
            $table->index(['McrEstablishmentID', 'McrDocumentType', 'McrIsActive'], 'IX_McrSeries_EstablishmentTypeActive');
        });
        Schema::table('McrDocument', function (Blueprint $table): void {
            $table->foreign(['McrEstablishmentID', 'McrCompanyConfigID'], 'FK_McrDocument_EstablishmentCompany')
                ->references(['McrEstablishmentID', 'McrCompanyConfigID'])->on('McrEstablishment');
            $table->index(['McrEstablishmentID', 'McrDocumentType', 'McrIssueDate'], 'IX_McrDocument_EstablishmentTypeDate');
        });
    }

    public function down(): void
    {
        Schema::table('McrDocument', function (Blueprint $table): void {
            $table->dropForeign('FK_McrDocument_EstablishmentCompany');
            $table->dropIndex('IX_McrDocument_EstablishmentTypeDate');
            $table->dropColumn(['McrEstablishmentID', 'McrEstablishmentSnapshot']);
        });
        Schema::table('McrSeries', function (Blueprint $table): void {
            $table->dropForeign('FK_McrSeries_EstablishmentCompany');
            $table->dropIndex('IX_McrSeries_EstablishmentTypeActive');
            $table->dropColumn('McrEstablishmentID');
        });
        Schema::dropIfExists('McrEstablishment');
    }
};
