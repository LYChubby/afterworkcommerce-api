<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_quotes', function (Blueprint $table) {
            $table->id();

            $table->uuid('uuid')->unique();
            $table->uuid('quote_group_uuid')->index();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('warehouse_id')
                ->constrained()
                ->restrictOnDelete();

            $table->jsonb('origin_snapshot');
            $table->jsonb('destination_snapshot');
            $table->jsonb('items_snapshot');

            $table->string('courier_code', 50);
            $table->string('courier_name');
            $table->string('courier_service_code', 100)->nullable();
            $table->string('courier_service_name')->nullable();
            $table->text('description')->nullable();
            $table->string('duration', 100)->nullable();
            $table->string('service_type', 50)->nullable();

            $table->unsignedBigInteger('shipping_fee');
            $table->char('currency', 3)->default('IDR');

            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();

            $table->foreignId('order_id')
                ->nullable()
                ->unique()
                ->constrained()
                ->nullOnDelete();

            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
            $table->index(['quote_group_uuid', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipping_quotes');
    }
};
