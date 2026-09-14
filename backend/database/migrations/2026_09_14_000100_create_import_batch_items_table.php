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
        Schema::create('import_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_batch_id')->constrained('import_batches')->cascadeOnDelete();
            // 影片被删除（如批次撤销）后置空，保留 title/year 快照用于回看
            $table->foreignId('movie_id')->nullable()->constrained('movies')->nullOnDelete();
            $table->string('title');
            $table->integer('year')->nullable();
            $table->string('action', 20); // created | updated
            $table->timestamps();

            $table->index(['import_batch_id', 'action']);
            $table->index('movie_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('import_batch_items');
    }
};
