
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('product_id')
                ->constrained('products')
                ->cascadeOnDelete();

            $table->foreignId('size_id')
                ->constrained('sizes')
                ->restrictOnDelete();

            $table->foreignId('color_id')
                ->constrained('colors')
                ->restrictOnDelete();

            $table->string('sku', 100)->unique();

            $table->unsignedInteger('stock_on_hand')->default(0);

            $table->unsignedInteger('stock_reserved')->default(0);

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->unique(
                ['product_id', 'size_id', 'color_id'],
                'product_variant_combination_unique'
            );

            $table->index(['product_id', 'is_active']);
        });

        // Stok tidak boleh negatif.
        DB::statement('
            ALTER TABLE product_variants
            ADD CONSTRAINT product_variants_stock_check
            CHECK (
                stock_reserved <= stock_on_hand
            )
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
