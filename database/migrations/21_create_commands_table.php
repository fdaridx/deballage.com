<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qwater_id')->constrained();
            $table->foreignId('payment_id')->nullable()->constrained();
            $table->foreignId('user_id')->constrained();
            $table->enum('state', ['init', 'enabled', 'disabled']);
            $table->timestamps();
            $table->timestamp('delivered_at')->nullable();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commands');
    }
};
