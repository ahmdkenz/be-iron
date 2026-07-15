<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tb_ap_shz360_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('started_at');
            $table->dateTime('finished_at')->nullable();
            $table->string('status', 20)->default('running');
            $table->unsignedInteger('po_fetched')->default(0);
            $table->unsignedInteger('po_upserted')->default(0);
            $table->unsignedInteger('receipt_fetched')->default(0);
            $table->unsignedInteger('receipt_upserted')->default(0);
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tb_ap_shz360_sync_runs');
    }
};
