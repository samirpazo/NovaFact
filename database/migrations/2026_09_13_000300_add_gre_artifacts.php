<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('McrDocument', function (Blueprint $table) {
            $table->string('McrZipPath', 500)->nullable();
            $table->integer('McrZipFilID')->nullable();
            $table->foreign('McrZipFilID')->references('FilID')->on('GenFile')->nullOnDelete();
        });
    }
    public function down(): void {
        Schema::table('McrDocument', function (Blueprint $table) {
            $table->dropForeign(['McrZipFilID']);
            $table->dropColumn(['McrZipPath', 'McrZipFilID']);
        });
    }
};
