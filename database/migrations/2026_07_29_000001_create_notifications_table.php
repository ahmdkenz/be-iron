<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Tabel default Laravel Notifiable (App\Models\User sudah pakai trait Notifiable).
// Sengaja memakai nama & struktur standar (bukan prefix tb_) supaya tetap kompatibel
// langsung dengan $user->notifications()/unreadNotifications()/markAsRead() bawaan
// framework tanpa perlu override model/relasi.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
