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
        // Tags table
        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();
            $table->string('type')->default('general'); // 'artist', 'character', 'series', 'general', 'meta'
            $table->unsignedInteger('posts_count')->default(0);
            $table->timestamps();
        });

        // Posts table
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('media_type')->default('image'); // 'image' or 'video'
            $table->unsignedInteger('media_count')->default(1);
            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('likes_count')->default(0);
            $table->boolean('is_nsfw')->default(false);
            $table->string('source_url')->nullable();
            $table->timestamps();
        });

        // Post Media (up to 40 for regular, up to 100 for pool chapter)
        Schema::create('post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('order')->default(0);
            $table->string('url');
            $table->string('thumbnail_url')->nullable();
            $table->unsignedInteger('width')->default(1200);
            $table->unsignedInteger('height')->default(800);
            $table->float('aspect_ratio')->default(1.5);
            $table->unsignedInteger('duration')->nullable(); // seconds (for video)
            $table->json('specific_tags')->nullable();
            $table->timestamps();
        });

        // Post Tag pivot
        Schema::create('post_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->unique(['post_id', 'tag_id']);
        });

        // User Tag Follows
        Schema::create('user_tag_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'tag_id']);
            $table->timestamps();
        });

        // User Tag Blacklist
        Schema::create('user_tag_blacklists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'tag_id']);
            $table->timestamps();
        });

        // Likes (public)
        Schema::create('likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'post_id']);
            $table->timestamps();
        });

        // Follows (Users)
        Schema::create('follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('follower_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('following_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['follower_id', 'following_id']);
            $table->timestamps();
        });

        // Collections
        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_private')->default(false);
            $table->string('cover_url')->nullable();
            $table->unsignedInteger('items_count')->default(0);
            $table->unsignedInteger('followers_count')->default(0);
            $table->timestamps();
        });

        Schema::create('collection_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
            $table->unique(['collection_id', 'post_id']);
        });

        Schema::create('collection_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'collection_id']);
            $table->timestamps();
        });

        // Pools (Series & Manga)
        Schema::create('pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // creator
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('cover_url')->nullable();
            $table->boolean('is_locked')->default(false);
            $table->unsignedInteger('chapters_count')->default(0);
            $table->unsignedInteger('followers_count')->default(0);
            $table->timestamps();
        });

        Schema::create('pool_chapters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->float('chapter_number')->default(1);
            $table->string('title');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        Schema::create('pool_follows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pool_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'pool_id']);
            $table->timestamps();
        });

        Schema::create('pool_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_chapter_id')->constrained('pool_chapters')->cascadeOnDelete();
            $table->unsignedInteger('last_page')->default(1);
            $table->unique(['user_id', 'pool_id']);
            $table->timestamps();
        });

        Schema::create('pool_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pool_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action'); // 'added_chapter', 'reordered', 'edited_metadata'
            $table->text('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Notifications
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // recipient
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete(); // trigger user
            $table->string('type'); // 'like', 'follow', 'pool_chapter', 'collection_update', 'comment'
            $table->string('notifiable_type')->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();
            $table->string('message');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // Messaging
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_one_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_two_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->unique(['user_one_id', 'user_two_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->text('text')->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->foreignId('shared_post_id')->nullable()->constrained('posts')->nullOnDelete();
            $table->json('reactions')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        // Comments
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comments');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('pool_history');
        Schema::dropIfExists('pool_progress');
        Schema::dropIfExists('pool_follows');
        Schema::dropIfExists('pool_chapters');
        Schema::dropIfExists('pools');
        Schema::dropIfExists('collection_follows');
        Schema::dropIfExists('collection_items');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('follows');
        Schema::dropIfExists('likes');
        Schema::dropIfExists('user_tag_blacklists');
        Schema::dropIfExists('user_tag_follows');
        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('post_media');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('tags');
    }
};
