<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('probe_entries', function (Blueprint $table) {
            $table->id();
            $table->string('type', 50);
            $table->longText('content');
            $table->text('tags')->nullable();
            $table->string('family_hash', 64)->nullable()->index();
            $table->timestamp('created_at')->useCurrent();

            $table->index('type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('probe_entries');
    }
};
