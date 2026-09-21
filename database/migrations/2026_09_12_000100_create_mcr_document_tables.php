<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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

        Schema::create('McrDocument', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrDocumentID');
            $table->unsignedBigInteger('McrApiClientID')->nullable();
            $table->integer('McrCompanyConfigID');
            $table->integer('McrEstablishmentID')->nullable();
            $table->jsonb('McrEstablishmentSnapshot')->nullable();
            $table->integer('McrSeriesID')->nullable();
            $table->string('McrSourceSystem', 50)->nullable();
            $table->string('McrSourceModule', 50)->nullable();
            $table->string('McrSourceEntity', 100)->nullable();
            $table->string('McrSourceReference', 150)->nullable();
            $table->string('McrDocumentType', 2);
            $table->string('McrSeriesCode', 4);
            $table->bigInteger('McrCorrelative');
            $table->date('McrIssueDate');
            $table->timestampTz('McrIssuedAt')->nullable();
            $table->string('McrCurrencyCode', 3)->default('PEN');
            $table->string('McrCustomerDocumentType', 2);
            $table->string('McrCustomerDocumentNumber', 20);
            $table->string('McrCustomerName', 255);
            $table->string('McrCustomerAddress', 500)->nullable();
            $table->decimal('McrTaxableAmount', 18, 6)->default(0);
            $table->decimal('McrExoneratedAmount', 18, 6)->default(0);
            $table->decimal('McrUnaffectedAmount', 18, 6)->default(0);
            $table->decimal('McrFreeAmount', 18, 6)->default(0);
            $table->decimal('McrDiscountAmount', 18, 6)->default(0);
            $table->decimal('McrTaxAmount', 18, 6)->default(0);
            $table->decimal('McrTotalAmount', 18, 6)->default(0);
            $table->string('McrPaymentTerms', 20)->default('Contado');
            $table->string('McrStatus', 30)->default('pending');
            $table->string('McrProcessingStatus', 20)->default('received');
            $table->jsonb('McrProcessingResult')->nullable();
            $table->text('McrArtifactError')->nullable();
            $table->string('McrExternalReference', 150)->nullable();
            $table->char('McrRequestHash', 64)->nullable();
            $table->string('McrIdempotencyKey', 255)->nullable();
            $table->string('McrSunatTicket', 100)->nullable();
            $table->string('McrSunatCode', 20)->nullable();
            $table->text('McrSunatDescription')->nullable();
            $table->char('McrPayloadHash', 64)->nullable();
            $table->string('McrXmlPath', 500)->nullable();
            $table->string('McrCdrPath', 500)->nullable();
            $table->string('McrPdfPath', 500)->nullable();
            $table->string('McrZipPath', 500)->nullable();
            $table->string('McrFailureCategory', 50)->nullable();
            $table->timestampTz('McrNextRecoveryCheckAt')->nullable();
            $table->integer('McrRecoveryAttemptCount')->default(0);
            $table->unsignedBigInteger('McrStateVersion')->default(0);
            $audit($table);

            $table->foreign('McrApiClientID')->references('McrApiClientID')->on('McrApiClient');
            $table->foreign('McrCompanyConfigID')->references('McrCompanyConfigID')->on('McrCompanyConfig');
            $table->foreign('McrSeriesID')->references('McrSeriesID')->on('McrSeries')->nullOnDelete();
            $table->foreign(['McrEstablishmentID', 'McrCompanyConfigID'], 'FK_McrDocument_EstablishmentCompany')
                ->references(['McrEstablishmentID', 'McrCompanyConfigID'])->on('McrEstablishment');
            $table->unique(['McrCompanyConfigID', 'McrDocumentType', 'McrSeriesCode', 'McrCorrelative'], 'UX_McrDocument_Number');
            $table->index('McrApiClientID', 'IX_McrDocument_Client');
            $table->index('McrStatus', 'IX_McrDocument_Status');
            $table->index('McrProcessingStatus', 'IX_McrDocument_ProcessingStatus');
            $table->index(['McrCompanyConfigID', 'McrStatus'], 'IX_McrDocument_CompanyStatus');
            $table->index(['McrCompanyConfigID', 'McrNextRecoveryCheckAt'], 'IX_McrDocument_RecoveryDue');
            $table->index(['McrEstablishmentID', 'McrDocumentType', 'McrIssueDate'], 'IX_McrDocument_EstablishmentTypeDate');
        });

        if (in_array(DB::getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('CREATE UNIQUE INDEX "UX_McrDocument_ClientCompanyExternal" ON "McrDocument" ("McrApiClientID", "McrCompanyConfigID", "McrExternalReference") WHERE "McrExternalReference" IS NOT NULL');
        }

        Schema::create('McrDocumentLine', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrDocumentLineID');
            $table->unsignedBigInteger('McrDocumentID');
            $table->integer('McrLineNumber')->default(1);
            $table->string('McrProductCode', 100)->nullable();
            $table->string('McrItemCode', 50)->nullable();
            $table->string('McrDescription', 500);
            $table->string('McrUnitCode', 5)->default('NIU');
            $table->decimal('McrQuantity', 18, 6);
            $table->decimal('McrUnitValue', 18, 6)->default(0);
            $table->decimal('McrUnitPrice', 18, 6);
            $table->decimal('McrTaxBase', 18, 6)->default(0);
            $table->decimal('McrTaxRate', 8, 4)->default(18);
            $table->decimal('McrTaxAmount', 18, 6)->default(0);
            $table->string('McrTaxAffectationCode', 2)->default('10');
            $table->decimal('McrDiscountAmount', 18, 6)->default(0);
            $table->decimal('McrValueAmount', 18, 6)->default(0);
            $table->decimal('McrLineTotal', 18, 6)->default(0);
            $table->decimal('McrTotalAmount', 18, 6)->default(0);
            $audit($table);

            $table->foreign('McrDocumentID')->references('McrDocumentID')->on('McrDocument')->cascadeOnDelete();
            $table->unique(['McrDocumentID', 'McrLineNumber'], 'UX_McrDocumentLine_Number');
        });

        Schema::create('McrDocumentReference', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrDocumentReferenceID');
            $table->unsignedBigInteger('McrDocumentID');
            $table->string('McrReferenceKind', 20)->default('internal');
            $table->unsignedBigInteger('McrAffectedDocumentID')->nullable();
            $table->string('McrAffectedDocumentType', 2)->nullable();
            $table->string('McrAffectedSeriesCode', 4)->nullable();
            $table->integer('McrAffectedCorrelative')->nullable();
            $table->string('McrReasonCode', 5)->nullable();
            $table->string('McrReasonDescription', 255)->nullable();
            $table->date('McrReferenceIssueDate')->nullable();
            $table->string('McrReferenceCurrencyCode', 3)->nullable();
            $table->string('McrCustomerDocumentType', 2)->nullable();
            $table->string('McrCustomerDocumentNumber', 20)->nullable();
            $table->string('McrReferenceType', 30)->nullable();
            $table->string('McrReferencedDocumentType', 2)->nullable();
            $table->string('McrReferencedNumber', 30)->nullable();
            $table->string('McrReason', 500)->nullable();
            $table->unsignedBigInteger('ReferencedMcrDocumentID')->nullable();
            $table->boolean('McrIsExternal')->nullable();
            $table->string('McrReferencedSeriesCode', 4)->nullable();
            $table->bigInteger('McrReferencedCorrelative')->nullable();
            $table->date('McrReferencedIssueDate')->nullable();
            $table->string('McrReferencedCurrencyCode', 3)->nullable();
            $table->string('McrReferencedCustomerDocumentType', 2)->nullable();
            $table->string('McrReferencedCustomerDocumentNumber', 15)->nullable();
            $audit($table);

            $table->foreign('McrDocumentID')->references('McrDocumentID')->on('McrDocument')->cascadeOnDelete();
            $table->foreign('McrAffectedDocumentID')->references('McrDocumentID')->on('McrDocument')->nullOnDelete();
            $table->foreign('ReferencedMcrDocumentID', 'FK_McrDocumentReference_ReferencedDocument')
                ->references('McrDocumentID')->on('McrDocument')->restrictOnDelete();
            $table->index('ReferencedMcrDocumentID', 'IX_McrDocumentReference_ReferencedDocument');
            $table->index(['McrReferencedDocumentType', 'McrReferencedSeriesCode', 'McrReferencedCorrelative'], 'IX_McrDocumentReference_ExternalNumber');
        });

        Schema::create('McrDocumentPayload', function (Blueprint $table) {
            $table->bigIncrements('McrDocumentPayloadID');
            $table->unsignedBigInteger('McrDocumentID');
            $table->jsonb('McrPayload')->nullable();
            $table->text('McrRawJson')->nullable();
            $table->char('McrPayloadHash', 64)->nullable();
            $table->timestampTz('McrCreatedAt')->useCurrent();
            $table->timestampTz('CreateDate')->useCurrent();

            $table->foreign('McrDocumentID')->references('McrDocumentID')->on('McrDocument')->cascadeOnDelete();
        });

        Schema::create('McrIdempotency', function (Blueprint $table) {
            $table->bigIncrements('McrIdempotencyID');
            $table->unsignedBigInteger('McrApiClientID')->nullable();
            $table->unsignedInteger('McrCompanyConfigID')->nullable();
            $table->string('McrKey', 128)->nullable();
            $table->string('McrIdempotencyKey', 255)->nullable();
            $table->char('McrRequestHash', 64)->nullable();
            $table->string('McrStatus', 20)->default('pending');
            $table->smallInteger('McrResponseStatus')->nullable();
            $table->text('McrResponseBody')->nullable();
            $table->unsignedBigInteger('McrDocumentID')->nullable();
            $table->unsignedBigInteger('McrSunatSubmissionID')->nullable();
            $table->string('McrExternalReference', 255)->nullable();
            $table->text('McrResponsePayload')->nullable();
            $table->timestampTz('McrCreatedAt')->useCurrent();
            $table->timestampTz('McrUpdatedAt')->useCurrent();
            $table->timestampTz('CreateDate')->useCurrent();

            $table->foreign('McrApiClientID')->references('McrApiClientID')->on('McrApiClient');
            $table->foreign('McrCompanyConfigID')->references('McrCompanyConfigID')->on('McrCompanyConfig');
            $table->foreign('McrDocumentID')->references('McrDocumentID')->on('McrDocument')->onDelete('cascade');
            $table->unique(['McrApiClientID', 'McrCompanyConfigID', 'McrKey'], 'UX_McrIdempotency_ScopeKey');
            $table->index(['McrDocumentID', 'McrSunatSubmissionID'], 'IX_McrIdempotency_Operation');
            $table->index(['McrStatus', 'McrUpdatedAt'], 'IX_McrIdempotency_Cleanup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('McrIdempotency');
        Schema::dropIfExists('McrDocumentPayload');
        Schema::dropIfExists('McrDocumentReference');
        Schema::dropIfExists('McrDocumentLine');
        Schema::dropIfExists('McrDocument');
    }
};
