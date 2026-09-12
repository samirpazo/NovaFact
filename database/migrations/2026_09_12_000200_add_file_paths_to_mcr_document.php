<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::table('McrDocument', function (Blueprint $t) { $t->string('McrXmlPath',500)->nullable(); $t->string('McrCdrPath',500)->nullable(); $t->string('McrPdfPath',500)->nullable(); }); }
    public function down(): void { Schema::table('McrDocument', function (Blueprint $t) { $t->dropColumn(['McrXmlPath','McrCdrPath','McrPdfPath']); }); }
};
