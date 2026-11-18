<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $t) {
            $t->boolean('allow_preorder')->default(false)->after('status');
        });

        if (Schema::hasColumn('products', 'allow_preorder')) {
            DB::statement('UPDATE product_variants SET allow_preorder = products.allow_preorder
                           FROM products WHERE products.id = product_variants.product_id');

            Schema::table('products', function (Blueprint $t) {
                $t->dropColumn('allow_preorder');
            });
        }
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $t) {
            $t->dropColumn('allow_preorder');
        });
    }
};
