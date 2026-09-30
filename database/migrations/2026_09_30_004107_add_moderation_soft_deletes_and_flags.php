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
        Schema::table('posts', function (Blueprint $table): void {
            $table->softDeletes();
            $table->text('removal_reason')->nullable()->after('deleted_at');
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->softDeletes();
            $table->boolean('is_hidden')->default(false)->after('deleted_at');
            $table->text('hidden_reason')->nullable()->after('is_hidden');
        });

        Schema::table('pools', function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::table('collections', function (Blueprint $table): void {
            $table->softDeletes();
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->boolean('is_hidden')->default(false)->after('is_read');
            $table->text('hidden_reason')->nullable()->after('is_hidden');
        });

        Schema::table('communities', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('member_count');
            $table->text('archive_reason')->nullable()->after('archived_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('communities', function (Blueprint $table): void {
            $table->dropColumn(['archived_at', 'archive_reason']);
        });

        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn(['is_hidden', 'hidden_reason']);
        });

        Schema::table('collections', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        Schema::table('pools', function (Blueprint $table): void {
            $table->dropSoftDeletes();
        });

        Schema::table('comments', function (Blueprint $table): void {
            $table->dropColumn(['is_hidden', 'hidden_reason']);
            $table->dropSoftDeletes();
        });

        Schema::table('posts', function (Blueprint $table): void {
            $table->dropColumn('removal_reason');
            $table->dropSoftDeletes();
        });
    }
};
