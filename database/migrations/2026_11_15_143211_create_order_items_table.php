<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Relasi ke pesanan
            $table->foreignId('order_id')
                ->constrained('orders')
                ->cascadeOnDelete();

            // Referensi varian produk
            $table->foreignId('product_variant_id')
                ->nullable()
                ->constrained('product_variants')
                ->nullOnDelete();

            // Snapshot identitas produk
            $table->string('product_name', 200);
            $table->string('product_slug', 200);
            $table->string('sku', 100);

            // Snapshot varian
            $table->string('size_name', 50);
            $table->string('color_name', 100);
            $table->string('color_hex_code', 7)->nullable();

            // Snapshot harga
            $table->decimal('unit_price', 12, 2);
            $table->decimal('unit_discount', 12, 2)->default(0);

            // Jumlah pembelian
            $table->unsignedInteger('quantity');

            // Total harga item setelah diskon
            $table->decimal('line_total', 12, 2);

            $table->timestamps();

            $table->index(
                ['order_id', 'created_at'],
                'order_items_order_created_idx'
            );

            $table->index(
                'product_variant_id',
                'order_items_variant_idx'
            );
        });

        DB::statement('
            ALTER TABLE order_items
            ADD CONSTRAINT order_items_amounts_check
            CHECK (
                unit_price > 0
                AND unit_discount >= 0
                AND unit_discount <= unit_price
                AND quantity > 0
                AND line_total = (unit_price - unit_discount) * quantity
            )
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
