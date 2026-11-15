<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Pesanan pemilik reservasi
            $table->foreignId('order_id')
                ->constrained('orders')
                ->restrictOnDelete();

            // Item pesanan yang dicadangkan stoknya
            $table->foreignId('order_item_id')
                ->constrained('order_items')
                ->restrictOnDelete();

            // Varian produk yang stoknya dicadangkan
            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            // Jumlah stok yang dicadangkan
            $table->unsignedInteger('quantity');

            // Status reservasi
            $table->string('status', 30)->default('active');

            // Batas waktu reservasi
            $table->timestampTz('expires_at');

            // Waktu perubahan status reservasi
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('released_at')->nullable();

            $table->timestamps();

            // Index untuk proses pencarian reservasi kedaluwarsa
            $table->index(
                ['status', 'expires_at'],
                'inventory_reservations_expiry_idx'
            );

            $table->index(
                ['product_variant_id', 'status'],
                'inventory_reservations_variant_status_idx'
            );

            $table->index(
                ['order_id', 'status'],
                'inventory_reservations_order_status_idx'
            );
        });

        DB::statement("
            ALTER TABLE inventory_reservations
            ADD CONSTRAINT inventory_reservations_quantity_check
            CHECK (quantity > 0)
        ");

        DB::statement("
            ALTER TABLE inventory_reservations
            ADD CONSTRAINT inventory_reservations_status_check
            CHECK (
                status IN ('active', 'confirmed', 'released', 'expired')
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservations');
    }
};
