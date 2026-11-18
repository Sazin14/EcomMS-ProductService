<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('product_attributes', 'is_variant')) {
            return;
        }

        Schema::table('product_attributes', function (Blueprint $t) {
            $t->boolean('is_variant')->default(true)->after('sort_order');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('product_attributes', 'is_variant')) {
            return;
        }

        Schema::table('product_attributes', function (Blueprint $t) {
            $t->dropColumn('is_variant');
        });
    }
};
