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
        // Channel categories (Discord "category" groups in the sidebar).
        Schema::create('community_channel_categories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->index(['community_id', 'position']);
        });

        // Discord-style channel metadata: categories, threads, slowmode, pin counters.
        Schema::table('community_channels', function (Blueprint $table): void {
            $table->unsignedBigInteger('category_id')->nullable()->index();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->unsignedBigInteger('parent_message_id')->nullable()->index();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->boolean('is_nsfw')->default(false);
            $table->unsignedInteger('slowmode_seconds')->default(0);
            $table->unsignedInteger('message_count')->default(0);
            $table->boolean('thread_archived')->default(false);
            $table->boolean('thread_locked')->default(false);
            $table->string('icon_emoji', 16)->nullable();
            $table->timestamp('last_message_at')->nullable()->index();
        });

        // Message metadata: mentions, pins, system messages and thread ownership.
        Schema::table('community_messages', function (Blueprint $table): void {
            $table->json('mention_user_ids')->nullable();
            $table->json('mention_role_ids')->nullable();
            $table->boolean('mention_everyone')->default(false);
            $table->boolean('is_pinned')->default(false);
            $table->timestamp('pinned_at')->nullable();
            $table->unsignedBigInteger('pinned_by')->nullable();
            $table->boolean('is_system')->default(false);
            $table->unsignedBigInteger('thread_id')->nullable()->index();
        });

        // Emoji reactions on chat messages (custom emoji supported via community_emoji).
        Schema::create('community_message_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_message_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 64);
            $table->timestamps();
            $table->unique(['community_message_id', 'user_id', 'emoji'], 'community_reaction_unique');
        });

        // Custom server emoji.
        Schema::create('community_emoji', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('image_url');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->unique(['community_id', 'name']);
        });

        // Per-user per-channel read state, unread + mention counters.
        Schema::create('community_channel_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->foreignId('community_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->unsignedInteger('mention_count')->default(0);
            $table->timestamps();
            $table->unique(['community_channel_id', 'user_id'], 'community_channel_read_unique');
        });

        // Member presence, nickname, timeout/moderation and notification prefs.
        Schema::table('community_members', function (Blueprint $table): void {
            $table->string('presence', 20)->default('offline')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('timeout_until')->nullable();
            $table->string('timeout_reason', 160)->nullable();
            $table->string('nickname', 40)->nullable();
            $table->string('status_emoji', 16)->nullable();
            $table->string('status_text', 140)->nullable();
            $table->unsignedInteger('message_count')->default(0);
            $table->boolean('is_muted')->default(false);
            $table->string('notify_level', 20)->default('all');
        });

        // Role appearance (Discord role color / hoist / mentionable).
        Schema::table('community_roles', function (Blueprint $table): void {
            $table->string('color', 7)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('hoist')->default(false);
            $table->boolean('mentionable')->default(false);
            $table->boolean('is_default')->default(false);
        });

        // Audit log context: which channel an action happened in + a readable target name.
        Schema::table('community_action_logs', function (Blueprint $table): void {
            $table->unsignedBigInteger('community_channel_id')->nullable()->index();
            $table->string('target_name', 120)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('community_action_logs', function (Blueprint $table): void {
            $table->dropIndex(['community_channel_id']);
            $table->dropColumn(['community_channel_id', 'target_name']);
        });

        Schema::table('community_roles', function (Blueprint $table): void {
            $table->dropColumn(['color', 'position', 'hoist', 'mentionable', 'is_default']);
        });

        Schema::table('community_members', function (Blueprint $table): void {
            $table->dropIndex(['presence']);
            $table->dropColumn(['presence', 'last_seen_at', 'timeout_until', 'timeout_reason', 'nickname', 'status_emoji', 'status_text', 'message_count', 'is_muted', 'notify_level']);
        });

        Schema::dropIfExists('community_channel_reads');
        Schema::dropIfExists('community_emoji');
        Schema::dropIfExists('community_message_reactions');

        Schema::table('community_messages', function (Blueprint $table): void {
            $table->dropIndex(['thread_id']);
            $table->dropColumn(['mention_user_ids', 'mention_role_ids', 'mention_everyone', 'is_pinned', 'pinned_at', 'pinned_by', 'is_system', 'thread_id']);
        });

        Schema::table('community_channels', function (Blueprint $table): void {
            $table->dropIndex(['category_id']);
            $table->dropIndex(['parent_id']);
            $table->dropIndex(['parent_message_id']);
            $table->dropIndex(['owner_id']);
            $table->dropIndex(['last_message_at']);
            $table->dropColumn(['category_id', 'parent_id', 'parent_message_id', 'owner_id', 'is_nsfw', 'slowmode_seconds', 'message_count', 'thread_archived', 'thread_locked', 'icon_emoji', 'last_message_at']);
        });

        Schema::dropIfExists('community_channel_categories');
    }
};
