<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Sync AP <- SHZ360 (tarik PO & Terima PO untuk staging). Token harus
    // sama persis dengan IRON_INTEGRATION_TOKEN di .env public_html (SHZ360).
    'shz360' => [
        'base_url' => env('SHZ360_BASE_URL'),
        'token' => env('SHZ360_INTEGRATION_TOKEN'),
        'timeout' => env('SHZ360_TIMEOUT', 15),
    ],

    // WhatsApp blast (Fonnte) - kirim invoice/rekap tagihan otomatis dari
    // halaman Invoice AR. Tidak ada token global di sini — tiap penerima
    // pakai token device Fonnte milik PIC AR yang menangani klien tsb
    // (tb_karyawan.fonnte_token), diisi ADMIN lewat form Data Karyawan.
    'fonnte' => [
        'base_url' => env('FONNTE_BASE_URL', 'https://api.fonnte.com'),
        'timeout' => env('FONNTE_TIMEOUT', 15),
        'delay_ms' => env('FONNTE_BLAST_DELAY_MS', 300),
    ],

    // Fitur Al-Qur'an + Murotal — proxy stateless ke API publik equran.id
    // (teks Arab, terjemahan, audio). Tidak ada data yang disimpan di DB
    // Iron; setiap request diteruskan live ke API ini.
    'equran' => [
        'base_url' => env('EQURAN_BASE_URL', 'https://equran.id/api/v2'),
        'timeout' => env('EQURAN_TIMEOUT', 15),
    ],

];
