<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // The platform's review id. Together with organization_id it is the
            // uniqueness rule that makes re-parsing idempotent.
            $table->string('external_id', 128);

            $table->string('author_name');
            $table->string('author_avatar_url', 512)->nullable();
            $table->string('author_level')->nullable();

            // Nullable: a review can carry text without any stars.
            $table->unsignedTinyInteger('rating')->nullable();
            $table->text('text')->nullable();

            // The organisation's own reply — the product this test task models
            // is about answering reviews, so it would be odd to drop it.
            $table->text('business_comment_text')->nullable();
            $table->dateTime('business_comment_at')->nullable();

            // dateTime, а не timestamp: MySQL 5.7 отвергает TIMESTAMP NOT NULL
            // без DEFAULT, а отзывы старше 2038 года нам всё равно не грозят —
            // зато миграция одинаково проходит на 5.7, 8.x, SQLite и Postgres.
            $table->dateTime('published_at');

            // Fingerprint of the meaningful fields: lets a re-parse tell
            // "unchanged" from "edited" with one comparison.
            $table->char('content_hash', 64);

            $table->unsignedSmallInteger('photos_count')->default(0);
            $table->unsignedInteger('likes_count')->default(0);

            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->timestamps();

            $table->unique(['organization_id', 'external_id']);
            $table->index(['organization_id', 'published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
