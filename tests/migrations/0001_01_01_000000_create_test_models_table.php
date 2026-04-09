<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_models', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('status')->default('active');
            $table->integer('category_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->dateTime('scheduled_at')->nullable();
            $table->json('tags')->nullable();
            $table->json('skills')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_logs');
        Schema::dropIfExists('test_categories');
        Schema::dropIfExists('test_models');
    }
};
