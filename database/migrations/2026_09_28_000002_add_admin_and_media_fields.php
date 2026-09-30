<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'is_banned')) {
            Schema::table('users', function (Blueprint $table) {
                $table->boolean('is_banned')->default(false)->after('is_admin');
            });
        }

        if (! Schema::hasColumn('posts', 'is_featured')) {
            Schema::table('posts', function (Blueprint $table) {
                $table->boolean('is_featured')->default(false)->after('is_nsfw');
            });
        }

        if (! Schema::hasColumn('pools', 'is_featured')) {
            Schema::table('pools', function (Blueprint $table) {
                $table->boolean('is_featured')->default(false)->after('is_locked');
            });
        }

        if (! Schema::hasColumn('comments', 'is_flagged')) {
            Schema::table('comments', function (Blueprint $table) {
                $table->boolean('is_flagged')->default(false)->after('content');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_banned');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('is_featured');
        });

        Schema::table('pools', function (Blueprint $table) {
            $table->dropColumn('is_featured');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->dropColumn('is_flagged');
        });
    }
};
