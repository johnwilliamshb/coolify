<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('team_user', 'can_create_projects')) {
            Schema::table('team_user', function (Blueprint $table) {
                $table->boolean('can_create_projects')->default(false);
            });
        }

        if (! Schema::hasColumn('team_invitations', 'can_create_projects')) {
            Schema::table('team_invitations', function (Blueprint $table) {
                $table->boolean('can_create_projects')->default(false);
            });
        }
    }

    public function down(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            $table->dropColumn('can_create_projects');
        });
        Schema::table('team_invitations', function (Blueprint $table) {
            $table->dropColumn('can_create_projects');
        });
    }
};
