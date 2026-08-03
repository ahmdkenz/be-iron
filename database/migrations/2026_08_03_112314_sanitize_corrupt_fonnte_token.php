<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('tb_users')
            ->whereNotNull('fonnte_token')
            ->select('id', 'fonnte_token')
            ->orderBy('id')
            ->get()
            ->each(function ($user) {
                try {
                    Crypt::decryptString($user->fonnte_token);
                } catch (DecryptException) {
                    DB::table('tb_users')->where('id', $user->id)->update(['fonnte_token' => null]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Data yang sudah dikonfirmasi korup tidak reversible secara berarti.
    }
};
