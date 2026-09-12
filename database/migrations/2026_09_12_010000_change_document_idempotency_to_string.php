<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
 public function up(): void { DB::statement('ALTER TABLE "McrDocument" ALTER COLUMN "McrIdempotencyKey" TYPE varchar(150) USING "McrIdempotencyKey"::text'); }
 public function down(): void { DB::statement('ALTER TABLE "McrDocument" ALTER COLUMN "McrIdempotencyKey" TYPE uuid USING "McrIdempotencyKey"::uuid'); }
};
