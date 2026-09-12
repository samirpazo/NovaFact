<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('McrCompanyConfig', function (Blueprint $table) {
            $table->string('McrCurrencyCode', 3)->default('PEN');
            $table->decimal('McrIgvRate', 8, 4)->default(18);
            $table->decimal('McrIpmRate', 8, 4)->default(0);
            $table->decimal('McrTotalTaxRate', 8, 4)->default(18);
            $table->boolean('McrSpecialTaxRegime')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('McrCompanyConfig', function (Blueprint $table) {
            $table->dropColumn([
                'McrCurrencyCode',
                'McrIgvRate',
                'McrIpmRate',
                'McrTotalTaxRate',
                'McrSpecialTaxRegime',
            ]);
        });
    }
};
