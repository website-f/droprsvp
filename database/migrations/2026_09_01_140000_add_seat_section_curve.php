<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seat_sections', function (Blueprint $table) {
            $table->unsignedSmallInteger('curve')->default(0)->after('row_label_start'); // 0 = straight rows … 100 = strong arc
        });
    }

    public function down(): void
    {
        Schema::table('seat_sections', fn (Blueprint $t) => $t->dropColumn('curve'));
    }
};
