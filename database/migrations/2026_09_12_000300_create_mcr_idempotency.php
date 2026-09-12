<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::create('McrIdempotency', function (Blueprint $t) {
            $t->bigIncrements('McrIdempotencyID');
            $t->string('McrKey', 128);
            $t->char('McrRequestHash', 64);
            $t->string('McrStatus', 20);
            $t->smallInteger('McrResponseStatus')->nullable();
            $t->text('McrResponseBody')->nullable();
            $t->timestampTz('McrCreatedAt')->useCurrent();
            $t->timestampTz('McrUpdatedAt')->useCurrent();
            $t->unique('McrKey', 'UX_McrIdempotency_Key');
            $t->index(['McrStatus', 'McrUpdatedAt'], 'IX_McrIdempotency_Cleanup');
        });
    }
    public function down(): void { Schema::dropIfExists('McrIdempotency'); }
};
