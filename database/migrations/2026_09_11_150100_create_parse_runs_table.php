<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parse_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('status', 16)->index();
            $table->string('trigger', 16);

            // Progress, written after every page so the UI can show a real bar
            // instead of a spinner of unknown duration.
            $table->unsignedSmallInteger('pages_done')->default(0);
            $table->unsignedSmallInteger('pages_expected')->default(0);

            $table->unsignedInteger('reviews_seen')->default(0);
            $table->unsignedInteger('reviews_created')->default(0);
            $table->unsignedInteger('reviews_updated')->default(0);

            // A copy of the aggregates as of this run — cheap, and it makes the
            // run row self-contained when reading history.
            $table->decimal('rating_value', 2, 1)->nullable();
            $table->unsignedInteger('ratings_count')->nullable();
            $table->unsignedInteger('reviews_count')->nullable();

            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->json('warnings')->nullable();

            $table->unsignedTinyInteger('attempt')->default(1);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parse_runs');
    }
};
