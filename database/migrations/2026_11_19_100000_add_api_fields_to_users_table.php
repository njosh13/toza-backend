<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * The API identifies users by an opaque ULID (`public_id`), never the
     * sequential integer key; and carries the contract's user flags.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->ulid('public_id')->nullable()->unique()->after('id');
            $table->boolean('active')->default(true)->after('password');
            $table->boolean('is_advocate')->default(false)->after('active');
            $table->timestamp('last_login_at')->nullable()->after('is_advocate');
            $table->string('avatar_url')->nullable()->after('last_login_at');
        });

        DB::table('users')->whereNull('public_id')->orderBy('id')->each(
            fn (object $user) => DB::table('users')->where('id', $user->id)->update(['public_id' => (string) Str::ulid()]),
        );
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['public_id']);
            $table->dropColumn(['public_id', 'active', 'is_advocate', 'last_login_at', 'avatar_url']);
        });
    }
};