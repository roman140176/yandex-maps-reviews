<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // `source` is here from day one so adding 2GIS later does not mean
            // reshaping the table; `external_id` is the platform's own id.
            $table->string('source', 32)->default('yandex');
            $table->string('external_id', 64);
            $table->string('url');

            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->string('category')->nullable();

            // Ratings and reviews are counted separately on purpose: most people
            // rate without writing anything, so the two differ by an order of
            // magnitude and merging them would be a lie.
            $table->decimal('rating_value', 2, 1)->nullable();
            $table->unsignedInteger('ratings_count')->nullable();
            $table->unsignedInteger('reviews_count')->nullable();
            $table->unsignedInteger('reviews_stored')->default(0);

            $table->string('parse_status', 16)->nullable();
            $table->timestamp('last_parsed_at')->nullable();
            $table->timestamps();

            // One card per user: re-submitting the same link updates it.
            $table->unique(['user_id', 'source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
