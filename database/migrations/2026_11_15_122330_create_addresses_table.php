<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('addresses', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('label', 50)->nullable();
            $table->string('recipient_name');
            $table->string('phone', 30);

            $table->text('address_line');
            $table->string('province');
            $table->string('city');
            $table->string('district')->nullable();
            $table->string('subdistrict')->nullable();
            $table->string('postal_code', 10);

            $table->string('country', 100)->default('Indonesia');
            $table->text('notes')->nullable();

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['user_id', 'is_default']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addresses');
    }
};
