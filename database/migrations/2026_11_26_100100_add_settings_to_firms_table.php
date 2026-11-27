<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Wording blocks: each is a list of paragraphs or lines. */
    private const LISTS = [
        'estimate_terms', 'bill_terms', 'engagement_terms', 'election_notice',
        'certificate_62a', 'certificate_68a', 'bank_details', 'mpesa_details',
    ];

    public function up(): void
    {
        Schema::table('firms', function (Blueprint $table) {
            $table->json('address_lines')->nullable()->after('address');
            $table->string('website')->nullable()->after('email');
            $table->string('logo_url', 2048)->nullable()->after('website');
            $table->string('logo_path')->nullable()->after('logo_url');

            foreach (self::LISTS as $column) {
                $table->json($column)->nullable();
            }

            $table->json('defaults')->nullable();
        });

        // The single-text address becomes a list of lines (the old column stays for the Inertia screens).
        DB::table('firms')->whereNotNull('address')->orderBy('id')->each(function (object $firm): void {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $firm->address) ?: [])));

            DB::table('firms')->where('id', $firm->id)->update(['address_lines' => json_encode($lines)]);
        });
    }

    public function down(): void
    {
        Schema::table('firms', function (Blueprint $table) {
            $table->dropColumn(['address_lines', 'website', 'logo_url', 'logo_path', 'defaults', ...self::LISTS]);
        });
    }
};