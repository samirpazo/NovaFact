<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('McrDocument', function (Blueprint $table): void {
            $table->unsignedBigInteger('McrStateVersion')->default(0);
        });

        Schema::create('McrOutboxEvent', function (Blueprint $table): void {
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

        Schema::create('McrWebhookSubscription', function (Blueprint $table): void {
            $table->bigIncrements('McrWebhookSubscriptionID');
            $table->unsignedBigInteger('McrApiClientID');
            $table->integer('McrCompanyConfigID');
            $table->string('McrUrl', 2048);
            $table->boolean('McrIsEnabled')->default(true);
            $table->text('McrEncryptedSecret');
            $table->jsonb('McrEventTypes');
            $table->timestampTz('McrCreatedAt');
            $table->timestampTz('McrUpdatedAt');
            $table->foreign('McrApiClientID')->references('McrApiClientID')->on('McrApiClient')->cascadeOnDelete();
            $table->foreign('McrCompanyConfigID')->references('McrCompanyConfigID')->on('McrCompanyConfig')->cascadeOnDelete();
            $table->unique(['McrApiClientID', 'McrCompanyConfigID', 'McrUrl'], 'UX_McrWebhookSubscription_ScopeUrl');
            $table->index(['McrApiClientID', 'McrCompanyConfigID', 'McrIsEnabled'], 'IX_McrWebhookSubscription_Scope');
        });

        Schema::create('McrWebhookDelivery', function (Blueprint $table): void {
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
            $table->timestampTz('McrCreatedAt');
            $table->timestampTz('McrUpdatedAt');
            $table->foreign('McrOutboxEventID')->references('McrOutboxEventID')->on('McrOutboxEvent')->cascadeOnDelete();
            $table->foreign('McrWebhookSubscriptionID')->references('McrWebhookSubscriptionID')->on('McrWebhookSubscription')->cascadeOnDelete();
            $table->unique(['McrOutboxEventID', 'McrWebhookSubscriptionID'], 'UX_McrWebhookDelivery_EventSubscription');
            $table->index(['McrStatus', 'McrNextAttemptAt'], 'IX_McrWebhookDelivery_Due');
        });

        Schema::create('McrWebhookDeliveryAttempt', function (Blueprint $table): void {
            $table->bigIncrements('McrWebhookDeliveryAttemptID');
            $table->unsignedBigInteger('McrWebhookDeliveryID');
            $table->unsignedInteger('McrAttemptNumber');
            $table->timestampTz('McrStartedAt');
            $table->timestampTz('McrCompletedAt');
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
        Schema::table('McrDocument', fn (Blueprint $table) => $table->dropColumn('McrStateVersion'));
    }
};
