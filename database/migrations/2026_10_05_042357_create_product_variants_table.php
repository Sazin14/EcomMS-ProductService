<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->string('sku', 100)->unique();
            $t->string('combination_hash', 32);   // md5 of the sorted value ids
            $t->decimal('purchase_price', 12, 2);
            $t->decimal('selling_price', 12, 2);
            $t->unsignedInteger('stock')->default(0);
            $t->string('status', 20)->default('active');   // active | inactive
            $t->timestamps();

            $t->unique(['product_id', 'combination_hash']);   // DB blocks duplicate combinations
            $t->index('selling_price');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
