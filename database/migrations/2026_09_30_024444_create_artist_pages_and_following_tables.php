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
        // 1. Artists catalog table
        Schema::create('artists', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('avatar_url', 1024)->nullable();
            $table->string('banner_url', 1024)->nullable();
            $table->text('bio')->nullable();
            $table->boolean('is_claimed')->default(false);
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('works_count')->default(0);
            $table->unsignedInteger('followers_count')->default(0);
            $table->json('featured_post_ids')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // 2. Artist Aliases
        Schema::create('artist_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('artist_id')->constrained('artists')->cascadeOnDelete();
            $table->string('alias');
            $table->string('slug')->index();
            $table->timestamps();

            $table->unique(['artist_id', 'alias']);
        });

        // 3. Artist External Links Directory
        Schema::create('artist_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('artist_id')->constrained('artists')->cascadeOnDelete();
            $table->string('platform')->default('website'); // website, x, pixiv, bluesky, patreon, fanbox, instagram, deviantart, tumblr, youtube, store, portfolio
            $table->string('url', 1024);
            $table->string('title')->nullable();
            $table->string('status')->default('unverified'); // verified, unverified, inactive, dead_link
            $table->foreignId('submitted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        // 4. Artist Page Claim Requests
        Schema::create('artist_claims', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('artist_id')->constrained('artists')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('verification_code');
            $table->string('verification_method')->default('bio_code'); // bio_code, website_meta, manual
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // 5. Community Edit Suggestions & Audit Log
        Schema::create('artist_edits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('artist_id')->constrained('artists')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('action'); // add_link, report_dead_link, add_alias, remove_alias, merge_request, split_request, claim_request, edit_info
            $table->json('details')->nullable();
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->timestamps();
        });

        // 6. Follow Artists Table
        Schema::create('artist_follows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('artist_id')->constrained('artists')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'artist_id']);
        });

        // 7. Add artist linkage to posts table
        Schema::table('posts', function (Blueprint $table): void {
            $table->foreignId('artist_id')->nullable()->after('artist_url')->constrained('artists')->nullOnDelete();
            $table->boolean('is_artist_upload')->default(false)->after('artist_id');
        });

        // 8. Add claimed_artist_id to users table
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('claimed_artist_id')->nullable()->after('reputation_score')->constrained('artists')->nullOnDelete();
        });

        // Seed initial artists from existing posts if artist_name is set
        $existingArtists = DB::table('posts')
            ->whereNotNull('artist_name')
            ->select('artist_name', 'artist_url')
            ->distinct()
            ->get();

        foreach ($existingArtists as $item) {
            $slug = Str::slug($item->artist_name);
            if (empty($slug)) {
                $slug = 'artist-'.Str::random(6);
            }

            // Ensure unique slug
            $originalSlug = $slug;
            $counter = 1;
            while (DB::table('artists')->where('slug', $slug)->exists()) {
                $slug = $originalSlug.'-'.$counter;
                $counter++;
            }

            $artistId = DB::table('artists')->insertGetId([
                'name' => $item->artist_name,
                'slug' => $slug,
                'avatar_url' => '/sfw/avatar/sample_07acb23be6e4bea15d091117693849b2.jpg',
                'bio' => 'Artist catalog auto-created from booru uploads.',
                'is_claimed' => false,
                'works_count' => 0,
                'followers_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('artist_aliases')->insert([
                'artist_id' => $artistId,
                'alias' => $item->artist_name,
                'slug' => $slug,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (! empty($item->artist_url)) {
                DB::table('artist_links')->insert([
                    'artist_id' => $artistId,
                    'platform' => 'website',
                    'url' => $item->artist_url,
                    'title' => 'Official Source',
                    'status' => 'unverified',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Update posts with artist_id
            DB::table('posts')
                ->where('artist_name', $item->artist_name)
                ->update(['artist_id' => $artistId]);
        }

        // Recalculate works_count for artists
        foreach (DB::table('artists')->get() as $artist) {
            $count = DB::table('posts')->where('artist_id', $artist->id)->whereNull('deleted_at')->count();
            DB::table('artists')->where('id', $artist->id)->update(['works_count' => $count]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropForeign(['claimed_artist_id']);
            $table->dropColumn(['claimed_artist_id']);
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropForeign(['artist_id']);
            $table->dropColumn(['artist_id', 'is_artist_upload']);
        });

        Schema::dropIfExists('artist_follows');
        Schema::dropIfExists('artist_edits');
        Schema::dropIfExists('artist_claims');
        Schema::dropIfExists('artist_links');
        Schema::dropIfExists('artist_aliases');
        Schema::dropIfExists('artists');
    }
};
