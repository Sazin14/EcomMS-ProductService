<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('product_variants', 'allow_preorder')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $t) {
            $t->boolean('allow_preorder')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('product_variants', 'allow_preorder')) {
            return;
        }

        Schema::table('product_variants', function (Blueprint $t) {
            $t->dropColumn('allow_preorder');
        });
    }
};
