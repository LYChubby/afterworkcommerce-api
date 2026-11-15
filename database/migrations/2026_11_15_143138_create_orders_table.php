<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Identitas pesanan
            $table->string('order_number', 50)->unique();

            // Pemilik pesanan
            $table->foreignId('user_id')
                ->constrained('users')
                ->restrictOnDelete();

            // Gudang asal pengiriman
            $table->foreignId('warehouse_id')
                ->constrained('warehouses')
                ->restrictOnDelete();

            // Alamat profil yang digunakan (opsional)
            $table->foreignId('shipping_address_id')
                ->nullable()
                ->constrained('addresses')
                ->nullOnDelete();

            // Snapshot alamat saat checkout
            $table->jsonb('shipping_address_snapshot');

            // Status pesanan
            $table->string('status', 30)->default('pending');

            // Status pembayaran
            $table->string('payment_status', 30)->default('pending');

            // Ringkasan harga pesanan
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('item_discount_total', 12, 2)->default(0);
            $table->decimal('shipping_cost', 12, 2)->default(0);
            $table->decimal('grand_total', 12, 2)->default(0);

            // Mata uang
            $table->string('currency', 3)->default('IDR');

            // Batas waktu pembayaran (30 menit)
            $table->timestampTz('payment_deadline_at')->nullable();

            // Waktu perubahan status
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();

            // Catatan pelanggan
            $table->text('customer_note')->nullable();

            $table->timestamps();

            // Index untuk pencarian pesanan
            $table->index(
                ['user_id', 'created_at'],
                'orders_user_created_idx'
            );

            $table->index(
                ['status', 'created_at'],
                'orders_status_created_idx'
            );

            $table->index(
                ['payment_status', 'payment_deadline_at'],
                'orders_payment_deadline_idx'
            );
        });

        // Validasi nilai uang
        DB::statement("
            ALTER TABLE orders
            ADD CONSTRAINT orders_amounts_check
            CHECK (
                subtotal >= 0
                AND item_discount_total >= 0
                AND shipping_cost >= 0
                AND grand_total >= 0
                AND item_discount_total <= subtotal
                AND grand_total = subtotal - item_discount_total + shipping_cost
            )
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
