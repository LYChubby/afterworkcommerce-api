<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('order_id')
                ->unique()
                ->constrained('orders')
                ->restrictOnDelete();

            $table->string('provider', 30)->default('biteship');

            // ID order yang diberikan Biteship
            $table->string('provider_order_id')->nullable()->unique();

            // ID tracking internal Biteship
            $table->string('tracking_id')->nullable()->unique();

            // Nomor resi dari kurir
            $table->string('waybill_id')->nullable();

            $table->string('courier_code', 50);
            $table->string('courier_service_code', 50)->nullable();
            $table->string('courier_name')->nullable();

            // Status dari Biteship
            $table->string('status', 50)->default('creating');

            $table->string('tracking_url', 1000)->nullable();

            // Harga aktual dari Biteship, jika tersedia
            $table->unsignedBigInteger('actual_shipping_fee')->nullable();

            // Respons API Biteship
            $table->jsonb('provider_response')->nullable();

            $table->timestampTz('shipped_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
