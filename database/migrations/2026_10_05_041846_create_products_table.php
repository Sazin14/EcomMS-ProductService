<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('brand_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();

            $table->enum('status', [
                'draft',
                'active',
                'inactive',
            ])->default('draft');

            $table->timestamps();

            $table->index([
                'category_id',
                'status',
            ]);

            $table->index([
                'brand_id',
                'status',
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};