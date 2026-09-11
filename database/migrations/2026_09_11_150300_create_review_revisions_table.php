<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parse_run_id')->nullable()->constrained()->nullOnDelete();

            // {"text": {"old": "...", "new": "..."}} — only what actually moved.
            // Storing the diff instead of full snapshots keeps the table small:
            // reviews rarely change, and when they do it is usually one field.
            $table->json('changes');

            $table->timestamp('created_at');

            $table->index(['review_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_revisions');
    }
};
