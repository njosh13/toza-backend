<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->backfill('clients', 'client_number', 'CL-', 'created_at');
        $this->backfill('matters', 'reference', 'M-', 'created_at');

        Schema::table('clients', function (Blueprint $table) {
            $table->unique(['firm_id', 'client_number']);
        });

        Schema::table('matters', function (Blueprint $table) {
            $table->string('matter_type', 40)->default('general_business')->after('reference');
            $table->string('forum', 40)->default('non_contentious')->after('matter_type');
            $table->date('completed_on')->nullable()->after('opened_on');
            $table->date('schedule5_election_notice_on')->nullable()->after('completed_on');

            $table->unique(['firm_id', 'reference']);
            $table->index(['firm_id', 'matter_type']);
        });

        // Derive the new columns for matters that exist already from their court level.
        $types = [
            'high_court' => ['high_court_litigation', 'high_court'],
            'subordinate' => ['subordinate_court_litigation', 'magistrates_court'],
            'tribunal_lt' => ['landlord_tenant_tribunal', 'tribunal'],
            'tribunal_rr' => ['rent_restriction_tribunal', 'tribunal'],
            'tribunal_other' => ['other_tribunal', 'tribunal'],
        ];

        foreach ($types as $level => [$type, $forum]) {
            DB::table('matters')->where('court_level', $level)->update(['matter_type' => $type, 'forum' => $forum]);
        }
    }

    public function down(): void
    {
        Schema::table('matters', function (Blueprint $table) {
            $table->dropUnique(['firm_id', 'reference']);
            $table->dropIndex(['firm_id', 'matter_type']);
            $table->dropColumn(['matter_type', 'forum', 'completed_on', 'schedule5_election_notice_on']);
        });

        Schema::table('clients', function (Blueprint $table) {
            $table->dropUnique(['firm_id', 'client_number']);
        });
    }

    /** A blank value, or the second copy of a duplicate, gets a fresh number (CL-0001, M-0001...). */
    private function backfill(string $table, string $column, string $prefix, string $orderBy): void
    {
        $firmIds = DB::table($table)->distinct()->pluck('firm_id');

        foreach ($firmIds as $firmId) {
            $taken = DB::table($table)->where('firm_id', $firmId)->whereNotNull($column)->pluck($column)->all();
            $seen = array_flip(array_map('strval', $taken));
            $next = 1;

            $rows = DB::table($table)->where('firm_id', $firmId)->orderBy($orderBy)->orderBy('id')->get(['id', $column]);
            $used = [];

            foreach ($rows as $row) {
                $value = $row->{$column};

                if ($value !== null && $value !== '' && ! isset($used[$value])) {
                    $used[$value] = true;

                    continue;
                }

                do {
                    $candidate = sprintf('%s%04d', $prefix, $next++);
                } while (isset($seen[$candidate]) || isset($used[$candidate]));

                $used[$candidate] = true;
                DB::table($table)->where('id', $row->id)->update([$column => $candidate]);
            }
        }
    }
};