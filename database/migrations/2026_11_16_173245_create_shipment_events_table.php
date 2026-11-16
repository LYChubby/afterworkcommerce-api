<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_events', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->foreignId('shipment_id')
                ->constrained('shipments')
                ->cascadeOnDelete();

            // Contoh: order.status, order.waybill_id
            $table->string('event_type', 50);

            // Contoh: confirmed, picked, in_transit, delivered
            $table->string('status', 50)->nullable();

            $table->text('description')->nullable();

            $table->string('location')->nullable();

            $table->timestampTz('occurred_at')->nullable();

            // Hash payload untuk mencegah event identik disimpan berulang
            $table->char('event_hash', 64)->nullable()->unique();

            // Payload asli webhook/API
            $table->jsonb('payload')->nullable();

            $table->timestamps();

            $table->index(['shipment_id', 'created_at']);
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_events');
    }
};
