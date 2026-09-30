<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('collections', function (Blueprint $table): void {
            $table->string('visibility', 20)->default('public')->after('description');
        });

        // Migrate existing is_private booleans to visibility string values
        DB::table('collections')->where('is_private', true)->update(['visibility' => 'private']);

        Schema::create('user_muted_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'tag_id']);
        });

        Schema::create('user_disliked_posts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'post_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_disliked_posts');
        Schema::dropIfExists('user_muted_tags');

        Schema::table('collections', function (Blueprint $table): void {
            $table->dropColumn('visibility');
        });
    }
};
