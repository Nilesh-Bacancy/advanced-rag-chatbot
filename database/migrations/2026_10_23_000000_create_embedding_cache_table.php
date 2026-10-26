<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('embedding_cache', function (Blueprint $table) {
            $table->id();
            // sha256 of the model name + text, so the same text is only ever
            // sent to the embeddings API once per model.
            $table->char('hash', 64)->unique();
            $table->string('model');
            // The text's embedding vector, stored as JSON.
            $table->json('embedding');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('embedding_cache');
    }
};
