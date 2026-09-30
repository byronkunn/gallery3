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
        Schema::table('users', function (Blueprint $table): void {
            $table->integer('reputation_score')->default(0)->after('is_artist');
            $table->index('reputation_score');
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->boolean('is_original_creator')->default(true)->after('user_id');
            $table->string('artist_name')->nullable()->after('is_original_creator');
            $table->string('artist_url', 1024)->nullable()->after('artist_name');
        });

        // Seed initial reputation score for existing users based on activity:
        // +10 per post, +2 per received like, +50 for verified artists.
        foreach (DB::table('users')->get() as $u) {
            $postCount = DB::table('posts')->where('user_id', $u->id)->whereNull('deleted_at')->count();
            $likeCount = DB::table('likes')
                ->join('posts', 'likes.post_id', '=', 'posts.id')
                ->where('posts.user_id', $u->id)
                ->count();
            $artistBonus = $u->is_artist ? 50 : 0;

            $initialRep = ($postCount * 10) + ($likeCount * 2) + $artistBonus;
            DB::table('users')->where('id', $u->id)->update(['reputation_score' => $initialRep]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn(['is_original_creator', 'artist_name', 'artist_url']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['reputation_score']);
            $table->dropColumn(['reputation_score']);
        });
    }
};
