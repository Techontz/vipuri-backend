<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-language translation strings.
 *
 * The source system kept one JSON file per language under `resources/lang`.
 * VIPURI stores the same key/value map on the language row instead: the API
 * serves it to the storefront, and it survives a deploy that replaces the
 * application directory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->json('translations')->nullable()->after('image');
        });
    }

    public function down(): void
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->dropColumn('translations');
        });
    }
};
