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

        Schema::create('McrCompanyConfig', function (Blueprint $table) use ($audit) {
            $table->increments('McrCompanyConfigID');
            $table->string('McrRuc', 11);
            $table->string('McrBusinessName', 250);
            $table->string('McrTradeName', 250)->nullable();
            $table->string('McrUbigeo', 6)->nullable();
            $table->string('McrDepartment', 100)->nullable();
            $table->string('McrProvince', 100)->nullable();
            $table->string('McrDistrict', 100)->nullable();
            $table->string('McrUrbanization', 150)->nullable();
            $table->string('McrAddress', 500)->nullable();
            $table->string('McrSolUser', 100)->nullable();
            $table->text('McrSolPassword')->nullable();
            $table->integer('McrCertificateFilID')->nullable();
            $table->string('McrCertificateName', 255)->nullable();
            $table->text('McrCertificatePassword')->nullable();
            $table->string('McrClientId', 255)->nullable();
            $table->text('McrClientSecret')->nullable();
            $table->string('McrEnvironment', 20)->default('beta');
            $table->boolean('McrIsActive')->default(true);
            $audit($table);
            $table->foreign('McrCertificateFilID')->references('FilID')->on('GenFile')->nullOnDelete();
            $table->unique(['McrRuc', 'McrEnvironment'], 'UX_McrCompanyConfig_RucEnvironment');
        });

        Schema::create('McrSeries', function (Blueprint $table) use ($audit) {
            $table->increments('McrSeriesID');
            $table->integer('McrCompanyConfigID');
            $table->string('McrDocumentType', 2);
            $table->string('McrSeriesCode', 4);
            $table->bigInteger('McrNextCorrelative')->default(1);
            $table->boolean('McrIsActive')->default(true);
            $audit($table);
            $table->foreign('McrCompanyConfigID')->references('McrCompanyConfigID')->on('McrCompanyConfig')->cascadeOnDelete();
            $table->unique(['McrCompanyConfigID', 'McrDocumentType', 'McrSeriesCode'], 'UX_McrSeries_CompanyTypeCode');
        });

        Schema::create('McrDocument', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrDocumentID');
            $table->integer('McrCompanyConfigID');
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
            $table->string('McrCustomerDocumentNumber', 15);
            $table->string('McrCustomerName', 250);
            $table->string('McrCustomerAddress', 500)->nullable();
            $table->decimal('McrTaxableAmount', 18, 6)->default(0);
            $table->decimal('McrExoneratedAmount', 18, 6)->default(0);
            $table->decimal('McrUnaffectedAmount', 18, 6)->default(0);
            $table->decimal('McrFreeAmount', 18, 6)->default(0);
            $table->decimal('McrDiscountAmount', 18, 6)->default(0);
            $table->decimal('McrTaxAmount', 18, 6)->default(0);
            $table->decimal('McrTotalAmount', 18, 6);
            $table->string('McrPaymentTerms', 20)->default('Contado');
            $table->string('McrStatus', 30)->default('draft');
            $table->uuid('McrIdempotencyKey')->nullable();
            $table->string('McrSunatTicket', 100)->nullable();
            $table->string('McrSunatCode', 20)->nullable();
            $table->text('McrSunatDescription')->nullable();
            $table->integer('McrXmlFilID')->nullable();
            $table->integer('McrCdrFilID')->nullable();
            $table->integer('McrPdfFilID')->nullable();
            $table->char('McrPayloadHash', 64)->nullable();
            $audit($table);
            $table->foreign('McrCompanyConfigID')->references('McrCompanyConfigID')->on('McrCompanyConfig');
            $table->foreign('McrSeriesID')->references('McrSeriesID')->on('McrSeries')->nullOnDelete();
            $table->foreign('McrXmlFilID')->references('FilID')->on('GenFile')->nullOnDelete();
            $table->foreign('McrCdrFilID')->references('FilID')->on('GenFile')->nullOnDelete();
            $table->foreign('McrPdfFilID')->references('FilID')->on('GenFile')->nullOnDelete();
            $table->unique(['McrCompanyConfigID', 'McrDocumentType', 'McrSeriesCode', 'McrCorrelative'], 'UX_McrDocument_Number');
            $table->unique('McrIdempotencyKey', 'UX_McrDocument_Idempotency');
            $table->index(
                ['McrSourceSystem', 'McrSourceModule', 'McrSourceEntity', 'McrSourceReference'],
                'IX_McrDocument_Source'
            );
        });

        Schema::create('McrDocumentLine', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrDocumentLineID');
            $table->unsignedBigInteger('McrDocumentID');
            $table->integer('McrLineNumber');
            $table->string('McrProductCode', 100)->nullable();
            $table->string('McrDescription', 500);
            $table->string('McrUnitCode', 3)->default('NIU');
            $table->decimal('McrQuantity', 18, 6);
            $table->decimal('McrUnitValue', 18, 6);
            $table->decimal('McrUnitPrice', 18, 6);
            $table->decimal('McrTaxBase', 18, 6)->default(0);
            $table->decimal('McrTaxRate', 8, 4)->default(18);
            $table->decimal('McrTaxAmount', 18, 6)->default(0);
            $table->string('McrTaxAffectationCode', 2)->default('10');
            $table->decimal('McrDiscountAmount', 18, 6)->default(0);
            $table->decimal('McrLineTotal', 18, 6);
            $audit($table);
            $table->foreign('McrDocumentID')->references('McrDocumentID')->on('McrDocument')->cascadeOnDelete();
            $table->unique(['McrDocumentID', 'McrLineNumber'], 'UX_McrDocumentLine_Number');
        });

        Schema::create('McrDocumentReference', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrDocumentReferenceID');
            $table->unsignedBigInteger('McrDocumentID');
            $table->string('McrReferenceType', 30);
            $table->string('McrReferencedDocumentType', 2);
            $table->string('McrReferencedNumber', 30);
            $table->string('McrReasonCode', 3)->nullable();
            $table->string('McrReason', 500)->nullable();
            $audit($table);
            $table->foreign('McrDocumentID')->references('McrDocumentID')->on('McrDocument')->cascadeOnDelete();
        });

        Schema::create('McrSunatSubmission', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrSunatSubmissionID');
            $table->unsignedBigInteger('McrDocumentID')->nullable();
            $table->string('McrOperation', 30);
            $table->string('McrTransport', 10);
            $table->integer('McrAttemptNumber')->default(1);
            $table->string('McrStatus', 30);
            $table->string('McrTicket', 100)->nullable();
            $table->timestampTz('McrSentAt')->nullable();
            $table->timestampTz('McrCompletedAt')->nullable();
            $table->integer('McrHttpStatus')->nullable();
            $table->text('McrError')->nullable();
            $table->jsonb('McrMetadata')->nullable();
            $audit($table);
            $table->foreign('McrDocumentID')->references('McrDocumentID')->on('McrDocument')->cascadeOnDelete();
            $table->index(['McrDocumentID', 'McrStatus'], 'IX_McrSunatSubmission_DocumentStatus');
        });

        Schema::create('McrSunatResponse', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrSunatResponseID');
            $table->unsignedBigInteger('McrSunatSubmissionID');
            $table->string('McrResponseCode', 20)->nullable();
            $table->text('McrDescription')->nullable();
            $table->jsonb('McrNotes')->nullable();
            $table->jsonb('McrRawResponse')->nullable();
            $table->integer('McrCdrFilID')->nullable();
            $audit($table);
            $table->foreign('McrSunatSubmissionID')->references('McrSunatSubmissionID')->on('McrSunatSubmission')->cascadeOnDelete();
            $table->foreign('McrCdrFilID')->references('FilID')->on('GenFile')->nullOnDelete();
        });

        Schema::create('McrDailySummary', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrDailySummaryID');
            $table->integer('McrCompanyConfigID');
            $table->date('McrReferenceDate');
            $table->date('McrIssueDate');
            $table->integer('McrCorrelative');
            $table->string('McrStatus', 30)->default('draft');
            $table->string('McrTicket', 100)->nullable();
            $table->integer('McrXmlFilID')->nullable();
            $table->integer('McrCdrFilID')->nullable();
            $audit($table);
            $table->foreign('McrCompanyConfigID')->references('McrCompanyConfigID')->on('McrCompanyConfig');
            $table->foreign('McrXmlFilID')->references('FilID')->on('GenFile')->nullOnDelete();
            $table->foreign('McrCdrFilID')->references('FilID')->on('GenFile')->nullOnDelete();
            $table->unique(['McrCompanyConfigID', 'McrIssueDate', 'McrCorrelative'], 'UX_McrDailySummary_Number');
        });

        Schema::create('McrDailySummaryDetail', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrDailySummaryDetailID');
            $table->unsignedBigInteger('McrDailySummaryID');
            $table->unsignedBigInteger('McrDocumentID');
            $table->string('McrConditionCode', 1)->default('1');
            $audit($table);
            $table->foreign('McrDailySummaryID')->references('McrDailySummaryID')->on('McrDailySummary')->cascadeOnDelete();
            $table->foreign('McrDocumentID')->references('McrDocumentID')->on('McrDocument');
            $table->unique(['McrDailySummaryID', 'McrDocumentID'], 'UX_McrDailySummaryDetail_Document');
        });

        Schema::create('McrVoidedDocument', function (Blueprint $table) use ($audit) {
            $table->bigIncrements('McrVoidedDocumentID');
            $table->integer('McrCompanyConfigID');
            $table->unsignedBigInteger('McrDocumentID');
            $table->date('McrReferenceDate');
            $table->date('McrIssueDate');
            $table->integer('McrCorrelative');
            $table->string('McrReason', 500);
            $table->string('McrStatus', 30)->default('draft');
            $table->string('McrTicket', 100)->nullable();
            $table->integer('McrXmlFilID')->nullable();
            $table->integer('McrCdrFilID')->nullable();
            $audit($table);
            $table->foreign('McrCompanyConfigID')->references('McrCompanyConfigID')->on('McrCompanyConfig');
            $table->foreign('McrDocumentID')->references('McrDocumentID')->on('McrDocument');
            $table->foreign('McrXmlFilID')->references('FilID')->on('GenFile')->nullOnDelete();
            $table->foreign('McrCdrFilID')->references('FilID')->on('GenFile')->nullOnDelete();
            $table->unique('McrDocumentID', 'UX_McrVoidedDocument_Document');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('McrVoidedDocument');
        Schema::dropIfExists('McrDailySummaryDetail');
        Schema::dropIfExists('McrDailySummary');
        Schema::dropIfExists('McrSunatResponse');
        Schema::dropIfExists('McrSunatSubmission');
        Schema::dropIfExists('McrDocumentReference');
        Schema::dropIfExists('McrDocumentLine');
        Schema::dropIfExists('McrDocument');
        Schema::dropIfExists('McrSeries');
        Schema::dropIfExists('McrCompanyConfig');
    }
};
