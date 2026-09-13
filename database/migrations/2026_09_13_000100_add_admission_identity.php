<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('McrApiClient', function (Blueprint $table) {
            $table->bigIncrements('McrApiClientID');
            $table->string('McrCode', 80)->unique();
            $table->string('McrName', 200);
            $table->boolean('McrIsActive')->default(true);
            $table->boolean('SecStatus')->default(true);
            $table->integer('CreateUserId')->default(0);
            $table->integer('UpdateUserId')->nullable();
            $table->timestampTz('CreateDate')->useCurrent();
            $table->timestampTz('UpdateDate')->nullable();
        });

        $legacyClientId = DB::table('McrApiClient')->insertGetId([
            'McrCode' => 'legacy',
            'McrName' => 'Migrated historical records',
            'McrIsActive' => true,
            'SecStatus' => true,
            'CreateUserId' => 0,
            'CreateDate' => now(),
        ], 'McrApiClientID');

        Schema::table('McrDocument', function (Blueprint $table) {
            $table->unsignedBigInteger('McrApiClientID')->nullable();
            $table->string('McrExternalReference', 150)->nullable();
            $table->char('McrRequestHash', 64)->nullable();
            $table->foreign('McrApiClientID')->references('McrApiClientID')->on('McrApiClient');
            $table->index('McrApiClientID', 'IX_McrDocument_Client');
            $table->index(['McrCompanyConfigID', 'McrStatus'], 'IX_McrDocument_CompanyStatus');
        });

        Schema::table('McrIdempotency', function (Blueprint $table) {
            $table->unsignedBigInteger('McrApiClientID')->nullable();
            $table->unsignedInteger('McrCompanyConfigID')->nullable();
            $table->unsignedBigInteger('McrDocumentID')->nullable();
            $table->unsignedBigInteger('McrSunatSubmissionID')->nullable();
            $table->foreign('McrApiClientID')->references('McrApiClientID')->on('McrApiClient');
            $table->foreign('McrCompanyConfigID')->references('McrCompanyConfigID')->on('McrCompanyConfig');
            $table->foreign('McrDocumentID')->references('McrDocumentID')->on('McrDocument');
            $table->foreign('McrSunatSubmissionID')->references('McrSunatSubmissionID')->on('McrSunatSubmission');
        });

        DB::table('McrDocument')->whereNull('McrApiClientID')->update(['McrApiClientID' => $legacyClientId]);
        DB::table('McrIdempotency')->whereNull('McrApiClientID')->update(['McrApiClientID' => $legacyClientId]);
        $firstCompanyId = DB::table('McrCompanyConfig')->orderBy('McrCompanyConfigID')->value('McrCompanyConfigID');
        if ($firstCompanyId !== null) {
            DB::table('McrIdempotency')->whereNull('McrCompanyConfigID')->update([
                'McrCompanyConfigID' => $firstCompanyId,
            ]);
        }

        DB::table('McrIdempotency')->whereNotNull('McrKey')->chunkById(500, function ($identities): void {
            foreach ($identities as $identity) {
                $document = DB::table('McrDocument')->where('McrIdempotencyKey', $identity->McrKey)->orderBy('McrDocumentID')->first();
                if (! $document) {
                    continue;
                }
                $submissionId = DB::table('McrSunatSubmission')->where('McrDocumentID', $document->McrDocumentID)
                    ->orderBy('McrSunatSubmissionID')->value('McrSunatSubmissionID');
                DB::table('McrIdempotency')->where('McrIdempotencyID', $identity->McrIdempotencyID)->update([
                    'McrCompanyConfigID' => $document->McrCompanyConfigID,
                    'McrDocumentID' => $document->McrDocumentID,
                    'McrSunatSubmissionID' => $submissionId,
                ]);
            }
        }, 'McrIdempotencyID');

        Schema::table('McrIdempotency', function (Blueprint $table) {
            $table->dropUnique('UX_McrIdempotency_Key');
            $table->unique(['McrApiClientID', 'McrCompanyConfigID', 'McrKey'], 'UX_McrIdempotency_ScopeKey');
            $table->index(['McrDocumentID', 'McrSunatSubmissionID'], 'IX_McrIdempotency_Operation');
        });
        Schema::table('McrDocument', function (Blueprint $table) {
            $table->dropUnique('UX_McrDocument_Idempotency');
        });

        DB::statement('CREATE UNIQUE INDEX "UX_McrDocument_ClientCompanyExternal" ON "McrDocument" ("McrApiClientID", "McrCompanyConfigID", "McrExternalReference") WHERE "McrExternalReference" IS NOT NULL');
    }

    public function down(): void
    {
        $scopedKeyCollisions = DB::table('McrIdempotency')->select('McrKey')
            ->groupBy('McrKey')->havingRaw('COUNT(*) > 1')->exists();
        $documentKeyCollisions = DB::table('McrDocument')->whereNotNull('McrIdempotencyKey')
            ->select('McrIdempotencyKey')->groupBy('McrIdempotencyKey')->havingRaw('COUNT(*) > 1')->exists();
        if ($scopedKeyCollisions || $documentKeyCollisions) {
            throw new RuntimeException('Cannot roll back scoped idempotency while cross-client/company keys overlap. No schema changes were applied.');
        }

        DB::statement('DROP INDEX IF EXISTS "UX_McrDocument_ClientCompanyExternal"');
        Schema::table('McrIdempotency', function (Blueprint $table) {
            $table->dropUnique('UX_McrIdempotency_ScopeKey');
            $table->dropIndex('IX_McrIdempotency_Operation');
            $table->dropForeign(['McrApiClientID']);
            $table->dropForeign(['McrCompanyConfigID']);
            $table->dropForeign(['McrDocumentID']);
            $table->dropForeign(['McrSunatSubmissionID']);
            $table->dropColumn(['McrApiClientID', 'McrCompanyConfigID', 'McrDocumentID', 'McrSunatSubmissionID']);
            $table->unique('McrKey', 'UX_McrIdempotency_Key');
        });
        Schema::table('McrDocument', function (Blueprint $table) {
            $table->dropIndex('IX_McrDocument_Client');
            $table->dropIndex('IX_McrDocument_CompanyStatus');
            $table->dropForeign(['McrApiClientID']);
            $table->dropColumn(['McrApiClientID', 'McrExternalReference', 'McrRequestHash']);
            $table->unique('McrIdempotencyKey', 'UX_McrDocument_Idempotency');
        });
        Schema::dropIfExists('McrApiClient');
    }
};
