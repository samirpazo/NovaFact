<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('McrCompanyConfig', 'ResID')) {
            Schema::table('McrCompanyConfig', function (Blueprint $table) {
                $table->dropForeign(['ResID']);
                $table->dropColumn('ResID');
            });
        }

        if (Schema::hasColumn('McrDocument', 'SalID')) {
            Schema::table('McrDocument', function (Blueprint $table) {
                $table->dropIndex('IX_McrDocument_SaleStatus');
                $table->dropForeign(['SalID']);
                $table->dropColumn('SalID');
            });
        }

        if (!Schema::hasColumn('McrDocument', 'McrSourceSystem')) {
            Schema::table('McrDocument', function (Blueprint $table) {
                $table->string('McrSourceSystem', 50)->nullable();
                $table->string('McrSourceModule', 50)->nullable();
                $table->string('McrSourceEntity', 100)->nullable();
                $table->string('McrSourceReference', 150)->nullable();
                $table->index(
                    ['McrSourceSystem', 'McrSourceModule', 'McrSourceEntity', 'McrSourceReference'],
                    'IX_McrDocument_Source'
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('McrDocument', 'McrSourceSystem')) {
            Schema::table('McrDocument', function (Blueprint $table) {
                $table->dropIndex('IX_McrDocument_Source');
                $table->dropColumn([
                    'McrSourceSystem',
                    'McrSourceModule',
                    'McrSourceEntity',
                    'McrSourceReference',
                ]);
            });
        }

        if (!Schema::hasColumn('McrCompanyConfig', 'ResID')) {
            Schema::table('McrCompanyConfig', function (Blueprint $table) {
                $table->integer('ResID')->nullable();
                $table->foreign('ResID')->references('ResID')->on('RstRestaurant')->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('McrDocument', 'SalID')) {
            Schema::table('McrDocument', function (Blueprint $table) {
                $table->integer('SalID')->nullable();
                $table->foreign('SalID')->references('SalID')->on('RstSale')->nullOnDelete();
                $table->index(['SalID', 'McrStatus'], 'IX_McrDocument_SaleStatus');
            });
        }
    }
};
