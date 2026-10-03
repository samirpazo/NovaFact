<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_operations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('client_id');
            $table->integer('company_id');
            $table->string('kind', 20);
            $table->string('protocol', 2);
            $table->date('issue_date');
            $table->date('reference_date');
            $table->unsignedInteger('correlative');
            $table->string('identifier', 100)->unique();
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->json('payload');
            $table->string('state', 30);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('ticket', 100)->nullable();
            $table->string('xml_path', 500)->nullable();
            $table->string('xml_hash', 64)->nullable();
            $table->string('cdr_path', 500)->nullable();
            $table->string('error', 250)->nullable();
            $table->timestampTz('remote_started_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestampsTz();
            $table->unique(['client_id', 'company_id', 'idempotency_key'], 'fiscal_operation_idempotency');
            $table->unique(['company_id', 'protocol', 'issue_date', 'correlative'], 'fiscal_operation_number');
            $table->index(['state', 'next_attempt_at']);
            $table->foreign('client_id')->references('McrApiClientID')->on('McrApiClient');
            $table->foreign('company_id')->references('McrCompanyConfigID')->on('McrCompanyConfig');
        });
        Schema::create('fiscal_operation_items', function (Blueprint $table) {
            $table->unsignedBigInteger('operation_id');
            $table->unsignedBigInteger('document_id');
            $table->primary(['operation_id', 'document_id']);
            $table->foreign('operation_id')->references('id')->on('fiscal_operations')->cascadeOnDelete();
            $table->foreign('document_id')->references('McrDocumentID')->on('McrDocument');
        });
        Schema::create('fiscal_operation_attempts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('operation_id');
            $table->string('action', 10);
            $table->string('state', 30);
            $table->string('code', 30)->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('completed_at')->nullable();
            $table->foreign('operation_id')->references('id')->on('fiscal_operations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_operation_attempts');
        Schema::dropIfExists('fiscal_operation_items');
        Schema::dropIfExists('fiscal_operations');
    }
};
