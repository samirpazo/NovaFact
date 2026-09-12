<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('McrCompanyConfig', function (Blueprint $table) {
            $table->string('McrPhone', 50)->nullable();
            $table->string('McrEmail', 250)->nullable();
            $table->integer('McrLogoFilID')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('McrCompanyConfig', function (Blueprint $table) {
            $table->dropColumn(['McrPhone', 'McrEmail', 'McrLogoFilID']);
        });
    }
};
