<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brand_category')) {
            return;
        }

        Schema::create('brand_category', function (Blueprint $t) {
            $t->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $t->foreignId('category_id')->constrained()->cascadeOnDelete();

            $t->primary(['brand_id', 'category_id']);
            $t->index('category_id');
        });

        DB::statement(
            'insert into brand_category (brand_id, category_id)
             select distinct brand_id, category_id from products where brand_id is not null'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_category');
    }
};
