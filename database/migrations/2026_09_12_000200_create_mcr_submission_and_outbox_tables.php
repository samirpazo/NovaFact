<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $audit = static function (Blueprint $table): void {
            $table->boolean('SecStatus')->default(true);
            $table->integer('CreateUserId')->default(0);
            $table->integer('UpdateUserId')->nullable();
            $table->integer('DeleteUserId')->nullable();
            $table->timestampTz('CreateDate')->useCurrent();
            $table->timestampTz('UpdateDate')->nullable();
            $table->timestampTz('DeleteDate')->nullable();
        };

        Schema::create('McrSunatSubmission', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrSunatSubmissionID');
            $table->unsignedBigInteger('McrDocumentID')->nullable();
            $table->string('McrOperation', 30)->default('emitir');
            $table->string('McrTransport', 10)->default('soap');
            $table->integer('McrAttemptNumber')->default(0);
            $table->string('McrTicketNumber', 50)->nullable();
            $table->string('McrTicket', 100)->nullable();
            $table->string('McrStatus', 30)->default('created');
            $table->string('McrProcessingStatus', 20)->default('processing');
            $table->string('McrFailureCategory', 50)->nullable();
            $table->string('McrCheckpoint', 40)->default('admitted');
            $table->boolean('McrIsAmbiguous')->default(false);
            $table->integer('McrAttempts')->default(0);
            $table->string('McrLastErrorCode', 50)->nullable();
            $table->text('McrLastErrorMessage')->nullable();
            $table->timestampTz('McrSentAt')->nullable();
            $table->timestampTz('McrCompletedAt')->nullable();
            $table->integer('McrHttpStatus')->nullable();
            $table->text('McrError')->nullable();
            $table->jsonb('McrMetadata')->nullable();
            $table->timestampTz('McrLastPolledAt')->nullable();
            $table->timestampTz('McrNextPollAt')->nullable();
            $table->timestampTz('McrNextAttemptAt')->nullable();
            $table->unsignedSmallInteger('McrRetryCount')->default(0);
            $table->unsignedSmallInteger('McrPollCount')->default(0);
            $table->unsignedSmallInteger('McrReconciliationCount')->default(0);
            $table->timestampTz('McrLastReconciledAt')->nullable();
            $table->string('McrManualReviewReason', 60)->nullable();
            $table->timestampTz('McrClaimedAt')->nullable();
            $table->uuid('McrClaimToken')->nullable();
            $table->timestampTz('McrLastRecoveryCheckAt')->nullable();
            $table->integer('McrRecoveryAttemptCount')->default(0);
            $table->timestampTz('McrUpdatedAt')->nullable();
            $audit($table);

            $table->foreign('McrDocumentID')->references('McrDocumentID')->on('McrDocument')->cascadeOnDelete();
            $table->index(['McrDocumentID', 'McrStatus'], 'IX_McrSunatSubmission_DocumentStatus');
            $table->index(['McrStatus', 'McrNextAttemptAt'], 'IX_McrSunatSubmission_RecoveryDue');
        });

        Schema::create('McrSunatAttempt', function (Blueprint $table) {
            $table->bigIncrements('McrSunatAttemptID');
            $table->unsignedBigInteger('McrSunatSubmissionID');
            $table->unsignedInteger('McrAttemptNumber');
            $table->string('McrTransport', 10)->default('soap');
            $table->string('McrAttemptType', 20)->default('send');
            $table->string('McrStatus', 30);
            $table->string('McrOperation', 30)->nullable();
            $table->string('McrOutcome', 50)->nullable();
            $table->integer('McrHttpStatus')->nullable();
            $table->boolean('McrRetryable')->nullable();
            $table->boolean('McrIsAmbiguous')->default(false);
            $table->string('McrCheckpoint', 40)->nullable();
            $table->string('McrTicket', 100)->nullable();
            $table->string('McrErrorCategory', 50)->nullable();
            $table->timestampTz('McrStartedAt')->useCurrent();
            $table->timestampTz('McrCompletedAt')->nullable();
            $table->unsignedBigInteger('McrDurationMs')->nullable();
            $table->string('McrResponseCode', 20)->nullable();
            $table->string('McrErrorCode', 50)->nullable();
            $table->text('McrErrorMessage')->nullable();
            $table->text('McrError')->nullable();
            $table->jsonb('McrResult')->nullable();
            $table->timestampTz('CreateDate')->useCurrent();

            $table->foreign('McrSunatSubmissionID')->references('McrSunatSubmissionID')->on('McrSunatSubmission')->cascadeOnDelete();
            $table->unique(['McrSunatSubmissionID', 'McrAttemptNumber'], 'UX_McrSunatAttempt_Number');
        });

        Schema::create('McrSunatResponse', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrSunatResponseID');
            $table->unsignedBigInteger('McrSunatSubmissionID');
            $table->string('McrResponseCode', 20)->nullable();
            $table->string('McrSunatResponseCode', 10)->nullable();
            $table->text('McrDescription')->nullable();
            $table->jsonb('McrNotes')->nullable();
            $table->jsonb('McrRawResponse')->nullable();
            $table->string('McrCdrPath', 500)->nullable();
            $audit($table);

            $table->foreign('McrSunatSubmissionID')->references('McrSunatSubmissionID')->on('McrSunatSubmission')->cascadeOnDelete();
        });

        Schema::create('McrDailySummary', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrDailySummaryID');
            $table->integer('McrCompanyConfigID');
            $table->date('McrReferenceDate');
            $table->date('McrIssueDate')->nullable();
            $table->date('McrSummaryDate')->nullable();
            $table->integer('McrCorrelative')->nullable();
            $table->string('McrIdentifier', 30)->nullable();
            $table->string('McrTicketNumber', 50)->nullable();
            $table->string('McrTicket', 100)->nullable();
            $table->string('McrStatus', 30)->default('draft');
            $table->string('McrXmlPath', 500)->nullable();
            $table->string('McrCdrPath', 500)->nullable();
            $audit($table);

            $table->foreign('McrCompanyConfigID')->references('McrCompanyConfigID')->on('McrCompanyConfig');
        });

        Schema::create('McrDailySummaryDetail', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrDailySummaryDetailID');
            $table->unsignedBigInteger('McrDailySummaryID');
            $table->unsignedBigInteger('McrDocumentID');
            $table->string('McrConditionCode', 1)->default('1');
            $table->integer('McrCondition')->default(1);
            $audit($table);

            $table->foreign('McrDailySummaryID')->references('McrDailySummaryID')->on('McrDailySummary')->cascadeOnDelete();
            $table->foreign('McrDocumentID')->references('McrDocumentID')->on('McrDocument');
            $table->unique(['McrDailySummaryID', 'McrDocumentID'], 'UX_McrDailySummaryDetail_Document');
        });

        Schema::create('McrVoidedDocument', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrVoidedDocumentID');
            $table->integer('McrCompanyConfigID');
            $table->unsignedBigInteger('McrDocumentID')->nullable();
            $table->date('McrReferenceDate')->nullable();
            $table->date('McrIssueDate')->nullable();
            $table->date('McrVoidedDate')->nullable();
            $table->integer('McrCorrelative')->nullable();
            $table->string('McrIdentifier', 30)->nullable();
            $table->string('McrTicketNumber', 50)->nullable();
            $table->string('McrTicket', 100)->nullable();
            $table->string('McrReason', 500);
            $table->string('McrStatus', 30)->default('draft');
            $table->string('McrXmlPath', 500)->nullable();
            $table->string('McrCdrPath', 500)->nullable();
            $audit($table);

            $table->foreign('McrCompanyConfigID')->references('McrCompanyConfigID')->on('McrCompanyConfig');
            $table->foreign('McrDocumentID')->references('McrDocumentID')->on('McrDocument');
        });

        Schema::create('McrOutboxEvent', function (Blueprint $table) {
            $table->uuid('McrOutboxEventID')->primary();
            $table->unsignedBigInteger('McrDocumentID');
            $table->unsignedBigInteger('McrSunatSubmissionID');
            $table->unsignedBigInteger('McrApiClientID');
            $table->integer('McrCompanyConfigID');
            $table->string('McrEventType', 60);
            $table->unsignedSmallInteger('McrEventVersion')->default(1);
            $table->unsignedBigInteger('McrStateVersion');
            $table->string('McrDeduplicationKey', 180);
            $table->jsonb('McrPayload');
            $table->text('McrPayloadBody');
            $table->timestampTz('McrOccurredAt');
            $table->timestampTz('McrFannedOutAt')->nullable();
            $table->timestampTz('McrClaimedAt')->nullable();
            $table->uuid('McrClaimToken')->nullable();

            $table->foreign('McrDocumentID')->references('McrDocumentID')->on('McrDocument')->cascadeOnDelete();
            $table->foreign('McrSunatSubmissionID')->references('McrSunatSubmissionID')->on('McrSunatSubmission')->cascadeOnDelete();
            $table->foreign('McrApiClientID')->references('McrApiClientID')->on('McrApiClient');
            $table->foreign('McrCompanyConfigID')->references('McrCompanyConfigID')->on('McrCompanyConfig');
            $table->unique('McrDeduplicationKey', 'UX_McrOutboxEvent_Dedup');
            $table->unique(['McrDocumentID', 'McrStateVersion'], 'UX_McrOutboxEvent_DocumentVersion');
            $table->index(['McrFannedOutAt', 'McrOccurredAt'], 'IX_McrOutboxEvent_Pending');
        });

        Schema::create('McrWebhookSubscription', function (Blueprint $table) {
            $table->bigIncrements('McrWebhookSubscriptionID');
            $table->unsignedBigInteger('McrApiClientID');
            $table->integer('McrCompanyConfigID');
            $table->string('McrUrl', 2048);
            $table->boolean('McrIsEnabled')->default(true);
            $table->text('McrEncryptedSecret')->nullable();
            $table->jsonb('McrEventTypes');
            $table->timestampTz('McrCreatedAt')->useCurrent();
            $table->timestampTz('McrUpdatedAt')->useCurrent();

            $table->foreign('McrApiClientID')->references('McrApiClientID')->on('McrApiClient')->cascadeOnDelete();
            $table->foreign('McrCompanyConfigID')->references('McrCompanyConfigID')->on('McrCompanyConfig')->cascadeOnDelete();
            $table->unique(['McrApiClientID', 'McrCompanyConfigID', 'McrUrl'], 'UX_McrWebhookSubscription_ScopeUrl');
            $table->index(['McrApiClientID', 'McrCompanyConfigID', 'McrIsEnabled'], 'IX_McrWebhookSubscription_Scope');
        });

        Schema::create('McrWebhookDelivery', function (Blueprint $table) {
            $table->bigIncrements('McrWebhookDeliveryID');
            $table->uuid('McrOutboxEventID');
            $table->unsignedBigInteger('McrWebhookSubscriptionID');
            $table->unsignedInteger('McrAttemptCount')->default(0);
            $table->unsignedSmallInteger('McrCycleAttemptCount')->default(0);
            $table->string('McrStatus', 30)->default('pending');
            $table->timestampTz('McrNextAttemptAt')->nullable();
            $table->timestampTz('McrLastAttemptAt')->nullable();
            $table->timestampTz('McrDeliveredAt')->nullable();
            $table->integer('McrHttpStatus')->nullable();
            $table->string('McrErrorCategory', 50)->nullable();
            $table->string('McrResponseExcerpt', 4096)->nullable();
            $table->timestampTz('McrClaimedAt')->nullable();
            $table->uuid('McrClaimToken')->nullable();
            $table->timestampTz('McrCreatedAt')->useCurrent();
            $table->timestampTz('McrUpdatedAt')->useCurrent();

            $table->foreign('McrOutboxEventID')->references('McrOutboxEventID')->on('McrOutboxEvent')->cascadeOnDelete();
            $table->foreign('McrWebhookSubscriptionID')->references('McrWebhookSubscriptionID')->on('McrWebhookSubscription')->cascadeOnDelete();
            $table->unique(['McrOutboxEventID', 'McrWebhookSubscriptionID'], 'UX_McrWebhookDelivery_EventSubscription');
            $table->index(['McrStatus', 'McrNextAttemptAt'], 'IX_McrWebhookDelivery_Due');
        });

        Schema::create('McrWebhookDeliveryAttempt', function (Blueprint $table) {
            $table->bigIncrements('McrWebhookDeliveryAttemptID');
            $table->unsignedBigInteger('McrWebhookDeliveryID');
            $table->unsignedInteger('McrAttemptNumber');
            $table->timestampTz('McrStartedAt')->useCurrent();
            $table->timestampTz('McrCompletedAt')->useCurrent();
            $table->integer('McrHttpStatus')->nullable();
            $table->string('McrOutcome', 30);
            $table->string('McrErrorCategory', 50)->nullable();
            $table->string('McrResponseExcerpt', 4096)->nullable();

            $table->foreign('McrWebhookDeliveryID')->references('McrWebhookDeliveryID')->on('McrWebhookDelivery')->cascadeOnDelete();
            $table->unique(['McrWebhookDeliveryID', 'McrAttemptNumber'], 'UX_McrWebhookDeliveryAttempt_Number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('McrWebhookDeliveryAttempt');
        Schema::dropIfExists('McrWebhookDelivery');
        Schema::dropIfExists('McrWebhookSubscription');
        Schema::dropIfExists('McrOutboxEvent');
        Schema::dropIfExists('McrVoidedDocument');
        Schema::dropIfExists('McrDailySummaryDetail');
        Schema::dropIfExists('McrDailySummary');
        Schema::dropIfExists('McrSunatResponse');
        Schema::dropIfExists('McrSunatAttempt');
        Schema::dropIfExists('McrSunatSubmission');
    }
};
