<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_images', function (Blueprint $t) {
            $t->id();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->foreignId('attribute_value_id')->nullable()->constrained()->cascadeOnDelete();   // null = general image
            $t->string('url', 2048);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_primary')->default(false);
            $t->timestamps();

            $t->index(['product_id', 'attribute_value_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
    }
};
