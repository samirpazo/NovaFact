<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('McrDocument', function (Blueprint $table) {
            $table->jsonb('McrProcessingResult')->nullable();
            $table->text('McrArtifactError')->nullable();
        });
        Schema::create('McrDocumentPayload', function (Blueprint $table) {
            $table->bigIncrements('McrDocumentPayloadID');
            $table->unsignedBigInteger('McrDocumentID')->unique();
            $table->jsonb('McrPayload');
            $table->char('McrPayloadHash', 64);
            $table->timestampTz('McrCreatedAt');
            $table->foreign('McrDocumentID')->references('McrDocumentID')->on('McrDocument')->cascadeOnDelete();
        });
        Schema::create('McrSunatAttempt', function (Blueprint $table) {
            $table->bigIncrements('McrSunatAttemptID');
            $table->unsignedBigInteger('McrSunatSubmissionID');
            $table->unsignedInteger('McrAttemptNumber');
            $table->string('McrTransport', 10);
            $table->string('McrStatus', 30);
            $table->timestampTz('McrStartedAt');
            $table->timestampTz('McrCompletedAt')->nullable();
            $table->unsignedBigInteger('McrDurationMs')->nullable();
            $table->string('McrResponseCode', 20)->nullable();
            $table->text('McrError')->nullable();
            $table->jsonb('McrResult')->nullable();
            $table->foreign('McrSunatSubmissionID')->references('McrSunatSubmissionID')->on('McrSunatSubmission')->cascadeOnDelete();
            $table->unique(['McrSunatSubmissionID', 'McrAttemptNumber'], 'UX_McrSunatAttempt_Number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('McrSunatAttempt');
        Schema::dropIfExists('McrDocumentPayload');
        Schema::table('McrDocument', function (Blueprint $table) {
            $table->dropColumn(['McrProcessingResult', 'McrArtifactError']);
        });
    }
};
