<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_attributes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('attribute_id')
                ->constrained('attributes')
                ->cascadeOnDelete();

            $table->boolean('is_required')->default(false);

            $table->boolean('is_variant')->default(true);

            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->unique([
                'category_id',
                'attribute_id',
            ]);

            $table->index('category_id');
            $table->index('attribute_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_attributes');
    }
};