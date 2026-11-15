<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->uuid()->unique();

            $table->foreignId('order_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('provider', 30)->default('midtrans');

            // ID order yang dikirim ke Midtrans.
            $table->string('provider_order_id', 50)->unique();

            // ID transaksi yang diberikan oleh Midtrans.
            $table->string('transaction_id')->nullable()->unique();

            // Snap token dan URL pembayaran.
            $table->text('snap_token')->nullable();
            $table->text('redirect_url')->nullable();

            // Nominal yang dikirim ke Midtrans.
            $table->decimal('gross_amount', 12, 2);

            // Status internal payment.
            $table->string('status', 30)->default('pending');

            // Informasi dari Midtrans.
            $table->string('transaction_status', 50)->nullable();
            $table->string('payment_type', 50)->nullable();
            $table->string('fraud_status', 30)->nullable();

            // Waktu pembayaran berhasil.
            $table->timestampTz('paid_at')->nullable();

            // Data notifikasi terakhir yang diterima.
            $table->jsonb('notification_payload')->nullable();

            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['transaction_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
