<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('McrEstablishment', function (Blueprint $table): void {
            $table->string('McrPhone', 50)->nullable()->after('McrCountryCode');
            $table->string('McrEmail', 250)->nullable()->after('McrPhone');
        });

        DB::table('McrEstablishment')->get()->each(function (object $establishment): void {
            $company = DB::table('McrCompanyConfig')->where('McrCompanyConfigID', $establishment->McrCompanyConfigID)->first();
            if ($company) {
                DB::table('McrEstablishment')->where('McrEstablishmentID', $establishment->McrEstablishmentID)->update([
                    'McrPhone' => $company->McrPhone,
                    'McrEmail' => $company->McrEmail,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('McrEstablishment', function (Blueprint $table): void {
            $table->dropColumn(['McrPhone', 'McrEmail']);
        });
    }
};
