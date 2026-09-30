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
        Schema::create('communities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->string('icon_url')->nullable();
            $table->string('banner_url')->nullable();
            $table->string('visibility', 20)->default('public');
            $table->json('topics')->nullable();
            $table->json('onboarding_questions')->nullable();
            $table->text('rules')->nullable();
            $table->unsignedInteger('member_count')->default(0);
            $table->timestamps();
            $table->index(['visibility', 'member_count']);
        });

        Schema::create('community_roles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->json('permissions');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
            $table->unique(['community_id', 'name']);
        });

        Schema::create('community_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('community_role_id')->nullable()->constrained('community_roles')->nullOnDelete();
            $table->string('status', 20)->default('active');
            $table->json('onboarding_answers')->nullable();
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['community_id', 'user_id']);
            $table->index(['community_id', 'status']);
        });

        Schema::create('community_channels', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('slug', 100);
            $table->string('type', 20)->default('text');
            $table->text('description')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_read_only')->default(false);
            $table->timestamps();
            $table->unique(['community_id', 'slug']);
            $table->index(['community_id', 'position']);
        });

        Schema::create('community_invites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('code', 48)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamps();
        });

        Schema::create('community_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('attachment_url')->nullable();
            $table->string('attachment_type', 20)->nullable();
            $table->foreignId('reply_to_id')->nullable()->constrained('community_messages')->nullOnDelete();
            $table->timestamp('edited_at')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();
            $table->index(['community_channel_id', 'created_at']);
        });

        Schema::create('community_forum_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('body')->nullable();
            $table->string('image_url')->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamps();
            $table->index(['community_channel_id', 'status', 'updated_at']);
        });

        Schema::create('community_forum_replies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_forum_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_hidden')->default(false);
            $table->timestamps();
            $table->index(['community_forum_post_id', 'created_at']);
        });

        Schema::create('community_forum_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('slug', 50);
            $table->timestamps();
            $table->unique(['community_id', 'slug']);
        });

        Schema::create('community_forum_post_tag', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_forum_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('community_forum_tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['community_forum_post_id', 'community_forum_tag_id'], 'forum_post_tag_unique');
        });

        Schema::create('community_polls', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('question', 200);
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();
        });

        Schema::create('community_poll_options', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_poll_id')->constrained()->cascadeOnDelete();
            $table->string('label', 120);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('community_poll_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_poll_id')->constrained()->cascadeOnDelete();
            $table->foreignId('community_poll_option_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['community_poll_id', 'user_id']);
            $table->index('community_poll_option_id');
        });

        Schema::create('community_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_channel_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->string('event_type', 20)->default('discussion');
            $table->timestamps();
            $table->index(['community_channel_id', 'starts_at']);
        });

        Schema::create('community_event_rsvps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20)->default('interested');
            $table->timestamps();
            $table->unique(['community_event_id', 'user_id']);
        });

        Schema::create('community_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_type', 30);
            $table->unsignedBigInteger('target_id');
            $table->string('reason', 80);
            $table->text('details')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['community_id', 'status', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });

        Schema::create('community_action_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('community_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->json('details')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['community_id', 'created_at']);
        });

        Schema::create('post_tag_proposals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tag_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tag_name', 80);
            $table->string('action', 10);
            $table->string('status', 20)->default('pending');
            $table->string('reason', 120)->nullable();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index(['post_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_tag_proposals');
        Schema::dropIfExists('community_action_logs');
        Schema::dropIfExists('community_reports');
        Schema::dropIfExists('community_event_rsvps');
        Schema::dropIfExists('community_events');
        Schema::dropIfExists('community_poll_votes');
        Schema::dropIfExists('community_poll_options');
        Schema::dropIfExists('community_polls');
        Schema::dropIfExists('community_forum_post_tag');
        Schema::dropIfExists('community_forum_tags');
        Schema::dropIfExists('community_forum_replies');
        Schema::dropIfExists('community_forum_posts');
        Schema::dropIfExists('community_messages');
        Schema::dropIfExists('community_invites');
        Schema::dropIfExists('community_channels');
        Schema::dropIfExists('community_members');
        Schema::dropIfExists('community_roles');
        Schema::dropIfExists('communities');
    }
};
