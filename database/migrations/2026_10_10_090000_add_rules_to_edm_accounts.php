<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-organizer email-marketing rules (see App\Support\Edm\OrganizerRules).
 *
 * access  inherit (follow the global access mode) | enabled | disabled.
 * rules   only the limits and switches this organizer overrides; anything
 *         absent follows the global rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edm_accounts', function (Blueprint $table) {
            if (! Schema::hasColumn('edm_accounts', 'access')) {
                $table->string('access', 10)->default('inherit')->after('status');
            }
            if (! Schema::hasColumn('edm_accounts', 'rules')) {
                $table->json('rules')->nullable()->after('monthly_allowance');
            }
        });
    }

    public function down(): void
    {
        Schema::table('edm_accounts', function (Blueprint $table) {
            $table->dropColumn(['access', 'rules']);
        });
    }
};
