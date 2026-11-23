
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('category_id')
                ->constrained('categories')
                ->restrictOnDelete();

            $table->string('name', 150);
            $table->string('slug', 180)->unique();

            $table->text('description')->nullable();

            $table->decimal('price', 12, 2);
            $table->decimal('discount_price', 12, 2)->nullable();

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->index(['category_id', 'is_active']);
        });

        // Harga harus lebih dari 0.
        // Harga diskon harus lebih dari 0 dan lebih rendah dari harga normal.
        DB::statement('
            ALTER TABLE products
            ADD CONSTRAINT products_price_check
            CHECK (price > 0)
        ');

        DB::statement('
            ALTER TABLE products
            ADD CONSTRAINT products_discount_price_check
            CHECK (
                discount_price IS NULL
                OR (
                    discount_price > 0
                    AND discount_price < price
                )
            )
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
