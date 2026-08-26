<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sii_dte_envelopes', function (Blueprint $table): void {
            $table->id();
            $table->rut('issuer');
            $table->rut('sender');
            $table->string('type', 20);
            $table->unsignedTinyInteger('document_type');
            $table->string('track_id')->nullable()->unique();
            $table->date('resolution_date');
            $table->unsignedInteger('resolution_number');
            $table->string('status')->default('pending')->index();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->json('repairs')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamps();
        });

        Schema::table('sii_dtes', function (Blueprint $table): void {
            $table
                ->foreign('sii_dte_envelope_id')
                ->references('id')
                ->on('sii_dte_envelopes')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sii_dtes', function (Blueprint $table): void {
            $table->dropForeign(['sii_dte_envelope_id']);
        });

        Schema::dropIfExists('sii_dte_envelopes');
    }
};
