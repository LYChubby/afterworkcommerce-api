
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sizes', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('name', 30)->unique();

            $table->unsignedInteger('sort_order')->default(0);

            $table->boolean('is_active')->default(true)->index();

            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sizes');
    }
};
