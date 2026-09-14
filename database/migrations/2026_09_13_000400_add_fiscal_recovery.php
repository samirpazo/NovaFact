<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('McrSunatSubmission', function (Blueprint $table): void {
            $table->string('McrFailureCategory', 50)->nullable();
            $table->string('McrCheckpoint', 40)->default('admitted');
            $table->boolean('McrIsAmbiguous')->default(false);
            $table->timestampTz('McrNextAttemptAt')->nullable();
            $table->unsignedSmallInteger('McrRetryCount')->default(0);
            $table->unsignedSmallInteger('McrPollCount')->default(0);
            $table->unsignedSmallInteger('McrReconciliationCount')->default(0);
            $table->timestampTz('McrLastReconciledAt')->nullable();
            $table->string('McrManualReviewReason', 60)->nullable();
            $table->timestampTz('McrClaimedAt')->nullable();
            $table->uuid('McrClaimToken')->nullable();
            $table->index(['McrStatus', 'McrNextAttemptAt'], 'IX_McrSunatSubmission_RecoveryDue');
        });
        Schema::table('McrSunatAttempt', function (Blueprint $table): void {
            $table->string('McrOperation', 30)->nullable();
            $table->string('McrOutcome', 50)->nullable();
            $table->integer('McrHttpStatus')->nullable();
            $table->boolean('McrRetryable')->nullable();
            $table->boolean('McrIsAmbiguous')->default(false);
            $table->string('McrCheckpoint', 40)->nullable();
            $table->string('McrTicket', 100)->nullable();
            $table->string('McrErrorCategory', 50)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('McrSunatAttempt', function (Blueprint $table): void {
            $table->dropColumn(['McrOperation', 'McrOutcome', 'McrHttpStatus', 'McrRetryable', 'McrIsAmbiguous', 'McrCheckpoint', 'McrTicket', 'McrErrorCategory']);
        });
        Schema::table('McrSunatSubmission', function (Blueprint $table): void {
            $table->dropIndex('IX_McrSunatSubmission_RecoveryDue');
            $table->dropColumn(['McrFailureCategory', 'McrCheckpoint', 'McrIsAmbiguous', 'McrNextAttemptAt', 'McrRetryCount', 'McrPollCount', 'McrReconciliationCount', 'McrLastReconciledAt', 'McrManualReviewReason', 'McrClaimedAt', 'McrClaimToken']);
        });
    }
};
