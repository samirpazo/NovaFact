<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('McrSunatSubmission', 'McrUpdatedAt')) {
            Schema::table('McrSunatSubmission', function (Blueprint $table) {
                $table->timestampTz('McrUpdatedAt')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('McrSunatSubmission', 'McrUpdatedAt')) {
            Schema::table('McrSunatSubmission', function (Blueprint $table) {
                $table->dropColumn('McrUpdatedAt');
            });
        }
    }
};
