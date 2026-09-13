<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('McrDocumentReference', function (Blueprint $table): void {
            $table->unsignedBigInteger('ReferencedMcrDocumentID')->nullable();
            $table->boolean('McrIsExternal')->nullable();
            $table->string('McrReferencedSeriesCode', 4)->nullable();
            $table->bigInteger('McrReferencedCorrelative')->nullable();
            $table->date('McrReferencedIssueDate')->nullable();
            $table->string('McrReferencedCurrencyCode', 3)->nullable();
            $table->string('McrReferencedCustomerDocumentType', 2)->nullable();
            $table->string('McrReferencedCustomerDocumentNumber', 15)->nullable();
            $table->foreign('ReferencedMcrDocumentID', 'FK_McrDocumentReference_ReferencedDocument')
                ->references('McrDocumentID')->on('McrDocument')->restrictOnDelete();
            $table->index('ReferencedMcrDocumentID', 'IX_McrDocumentReference_ReferencedDocument');
            $table->index(
                ['McrReferencedDocumentType', 'McrReferencedSeriesCode', 'McrReferencedCorrelative'],
                'IX_McrDocumentReference_ExternalNumber'
            );
        });
    }

    public function down(): void
    {
        Schema::table('McrDocumentReference', function (Blueprint $table): void {
            $table->dropIndex('IX_McrDocumentReference_ExternalNumber');
            $table->dropIndex('IX_McrDocumentReference_ReferencedDocument');
            $table->dropForeign('FK_McrDocumentReference_ReferencedDocument');
            $table->dropColumn([
                'ReferencedMcrDocumentID', 'McrIsExternal', 'McrReferencedSeriesCode',
                'McrReferencedCorrelative', 'McrReferencedIssueDate', 'McrReferencedCurrencyCode',
                'McrReferencedCustomerDocumentType', 'McrReferencedCustomerDocumentNumber',
            ]);
        });
    }
};
