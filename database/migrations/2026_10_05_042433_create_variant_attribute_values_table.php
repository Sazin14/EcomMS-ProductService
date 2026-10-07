<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('variant_attribute_values', function (Blueprint $t) {
            $t->id();
            $t->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $t->foreignId('attribute_id')->constrained()->restrictOnDelete();
            $t->foreignId('attribute_value_id')->constrained()->restrictOnDelete();

            $t->unique(['variant_id', 'attribute_id']);   // one value per attribute per variant
            $t->index(['attribute_value_id', 'variant_id']);   // used by storefront filters
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('variant_attribute_values');
    }
};
