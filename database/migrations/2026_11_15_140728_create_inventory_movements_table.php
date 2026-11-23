<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Gudang dan varian produk yang mengalami perubahan stok
            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            $table->foreignId('product_variant_id')
                ->constrained('product_variants')
                ->restrictOnDelete();

            // Contoh: stock_in, stock_out, reservation,
            // reservation_release, adjustment
            $table->string('movement_type', 30);

            // Jumlah unit yang terlibat dalam pergerakan
            $table->unsignedInteger('quantity');

            // Snapshot stok sebelum dan sesudah perubahan
            $table->unsignedInteger('stock_on_hand_before');
            $table->unsignedInteger('stock_on_hand_after');

            $table->unsignedInteger('stock_reserved_before');
            $table->unsignedInteger('stock_reserved_after');

            // Referensi ke transaksi terkait, misalnya order
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->text('notes')->nullable();

            // Admin atau user yang melakukan perubahan
            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();

            // Index untuk pencarian riwayat
            $table->index(
                ['product_variant_id', 'created_at'],
                'inventory_movements_variant_date_idx'
            );

            $table->index(
                ['warehouse_id', 'created_at'],
                'inventory_movements_warehouse_date_idx'
            );

            $table->index(
                ['reference_type', 'reference_id'],
                'inventory_movements_reference_idx'
            );
        });

        // Validasi tambahan khusus PostgreSQL
        DB::statement('
            ALTER TABLE inventory_movements
            ADD CONSTRAINT inventory_movements_quantity_check
            CHECK (quantity > 0)
        ');

        DB::statement('
            ALTER TABLE inventory_movements
            ADD CONSTRAINT inventory_movements_stock_check
            CHECK (
                stock_reserved_before <= stock_on_hand_before
                AND stock_reserved_after <= stock_on_hand_after
            )
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
