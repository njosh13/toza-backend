<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Legacy firm roles -> contract roles.
     *   owner, admin -> admin     (settings, team, audit; no billing)
     *   advocate     -> partner   (keeps everything an advocate could do)
     *   accounts, readonly -> accounts
     */
    private const MAP = [
        'owner' => 'admin',
        'admin' => 'admin',
        'advocate' => 'partner',
        'accounts' => 'accounts',
        'readonly' => 'accounts',
    ];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            DB::table('firm_user')->where('role', $old)->update(['role' => $new]);
        }

        Schema::table('firm_user', function (Blueprint $table) {
            $table->string('role')->default('associate')->change();
        });

        // The ownership scope needs to know who owns a matter and who authored a bill.
        Schema::table('matters', function (Blueprint $table) {
            $table->foreignId('responsible_advocate_id')->nullable()->after('client_id')->constrained('users')->nullOnDelete();
            $table->index(['firm_id', 'responsible_advocate_id']);
        });

        Schema::table('bills', function (Blueprint $table) {
            $table->foreignId('created_by')->nullable()->after('matter_id')->constrained('users')->nullOnDelete();
        });

        // Bills that existed before authorship was recorded: the issuer is the best known author.
        DB::table('bills')->whereNull('created_by')->whereNotNull('issued_by')->update(['created_by' => DB::raw('issued_by')]);
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });

        Schema::table('matters', function (Blueprint $table) {
            $table->dropIndex(['firm_id', 'responsible_advocate_id']);
            $table->dropConstrainedForeignId('responsible_advocate_id');
        });

        // Roles are not mapped back: admin/partner/associate/accounts are the only roles now.
        Schema::table('firm_user', function (Blueprint $table) {
            $table->string('role')->default('advocate')->change();
        });
    }
};