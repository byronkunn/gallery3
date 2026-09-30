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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->unique();
            $table->string('email')->unique();
            $table->string('avatar_url')->nullable();
            $table->string('banner_url')->nullable();
            $table->text('bio')->nullable();
            $table->string('website')->nullable();
            $table->boolean('is_artist')->default(false);
            $table->boolean('is_admin')->default(false);
            $table->string('commission_status')->nullable();
            $table->string('theme_mode')->default('dark'); // 'dark', 'light', 'oled'
            $table->string('theme_palette')->default('violet'); // 'violet', 'cyan', 'sakura', 'emerald', 'sunset', 'crimson'
            $table->boolean('blur_nsfw')->default(true);
            $table->boolean('hide_nsfw')->default(false);
            $table->string('font_size')->default('md'); // 'sm', 'md', 'lg'
            $table->boolean('reduced_motion')->default(false);
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
