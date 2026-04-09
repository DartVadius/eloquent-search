<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('general');
            $table->timestamps();
        });

        Schema::create('test_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('test_model_id')->constrained('test_models')->cascadeOnDelete();
            $table->integer('status_id');
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_logs');
        Schema::dropIfExists('test_categories');
    }
};
