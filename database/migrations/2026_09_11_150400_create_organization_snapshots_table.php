<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parse_run_id')->nullable()->constrained()->nullOnDelete();

            // One row per successful run: the aggregates as they were then.
            // This is what answers "the rating dropped — when, and from what?"
            $table->decimal('rating_value', 2, 1)->nullable();
            $table->unsignedInteger('ratings_count')->nullable();
            $table->unsignedInteger('reviews_count')->nullable();
            $table->unsignedInteger('reviews_stored')->default(0);

            $table->dateTime('captured_at');

            $table->index(['organization_id', 'captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_snapshots');
    }
};
