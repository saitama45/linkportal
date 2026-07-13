<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom / unlimited line-item columns are template-driven, so they can't each
 * be a fixed SQL column. The standard five stay as real columns (fast, simple
 * reporting); any additional custom column lands here as {key: value} JSON so
 * it's still queryable via JSON_VALUE(extra, '$.<key>') on the shared DB.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('portal_intake_line_items', 'extra')) {
            Schema::table('portal_intake_line_items', function (Blueprint $table) {
                $table->json('extra')->nullable()->after('line_total');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('portal_intake_line_items', 'extra')) {
            Schema::table('portal_intake_line_items', function (Blueprint $table) {
                $table->dropColumn('extra');
            });
        }
    }
};
