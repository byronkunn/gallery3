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
        Schema::table('tags', function (Blueprint $table) {
            $table->string('short_description')->nullable()->after('type');
            $table->text('wiki_summary')->nullable()->after('short_description');
            $table->text('wiki_usage')->nullable()->after('wiki_summary');
            $table->text('wiki_do_not_use')->nullable()->after('wiki_usage');
            $table->text('wiki_examples')->nullable()->after('wiki_do_not_use');
            $table->text('wiki_notes')->nullable()->after('wiki_examples');
            $table->boolean('is_locked')->default(false)->after('wiki_notes');
            $table->string('lock_level', 30)->default('unlocked')->after('is_locked');
        });

        Schema::create('tag_implications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->foreignId('implied_tag_id')->constrained('tags')->cascadeOnDelete();
            $table->string('status', 20)->default('approved'); // approved, pending
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tag_id', 'implied_tag_id']);
        });

        Schema::create('tag_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->foreignId('related_tag_id')->constrained('tags')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['tag_id', 'related_tag_id']);
        });

        Schema::create('tag_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50); // created, wiki_edit, alias_added, implication_added, category_changed, renamed, merged, locked
            $table->json('old_wiki')->nullable();
            $table->json('new_wiki')->nullable();
            $table->string('edit_summary')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tag_histories');
        Schema::dropIfExists('tag_relations');
        Schema::dropIfExists('tag_implications');

        Schema::table('tags', function (Blueprint $table) {
            $table->dropColumn([
                'short_description',
                'wiki_summary',
                'wiki_usage',
                'wiki_do_not_use',
                'wiki_examples',
                'wiki_notes',
                'is_locked',
                'lock_level',
            ]);
        });
    }
};
