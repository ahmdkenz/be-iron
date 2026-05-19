<?php

namespace App\Console\Commands;

use Google\Client as GoogleClient;
use Google\Service\Drive as GoogleDrive;
use Illuminate\Console\Command;

class GDriveAuthorizeCommand extends Command
{
    protected $signature   = 'gdrive:authorize';
    protected $description = 'Otorisasi Google Drive OAuth 2.0 dan dapatkan Refresh Token';

    public function handle(): void
    {
        $clientId     = config('services.google_drive.client_id');
        $clientSecret = config('services.google_drive.client_secret');
        $redirectUri  = 'http://localhost:9876';

        if (!$clientId || !$clientSecret) {
            $this->error('GOOGLE_CLIENT_ID dan GOOGLE_CLIENT_SECRET belum diisi di .env');
            return;
        }

        $client = new GoogleClient();
        $client->setClientId($clientId);
        $client->setClientSecret($clientSecret);
        $client->setRedirectUri($redirectUri);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->addScope(GoogleDrive::DRIVE);

        $authUrl = $client->createAuthUrl();

        $this->newLine();
        $this->info('╔══════════════════════════════════════════════════╗');
        $this->info('║   Google Drive OAuth 2.0 — Otorisasi Akun       ║');
        $this->info('╚══════════════════════════════════════════════════╝');
        $this->newLine();
        $this->line('LANGKAH 1 — Buka URL ini di browser:');
        $this->newLine();
        $this->warn($authUrl);
        $this->newLine();
        $this->line('LANGKAH 2 — Login dengan akun Google → klik <fg=green>Allow / Izinkan</>');
        $this->newLine();
        $this->line('Browser akan redirect ke <fg=cyan>http://localhost:9876</> — PHP akan');
        $this->line('menangkap callback secara otomatis. Tunggu...');
        $this->newLine();

        $code = $this->captureCodeViaSocket(9876, 120);

        if (!$code) {
            $this->error('Timeout atau gagal menangkap authorization code.');
            $this->line('Pastikan port 9876 tidak dipakai aplikasi lain, lalu jalankan ulang.');
            return;
        }

        $this->line('Authorization code diterima. Menukar dengan refresh token...');

        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            $this->error('Gagal: ' . ($token['error_description'] ?? $token['error']));
            if ($token['error'] === 'invalid_grant') {
                $this->warn('Tips: Code sudah kadaluarsa. Jalankan ulang command segera setelah klik Allow.');
            }
            return;
        }

        if (!isset($token['refresh_token'])) {
            $this->warn('Refresh token tidak ada dalam response.');
            $this->line('Akun sudah pernah diotorisasi sebelumnya. Cabut akses di:');
            $this->line('  https://myaccount.google.com/permissions');
            $this->line('Lalu jalankan command ini kembali.');
            return;
        }

        $this->newLine();
        $this->info('✓ BERHASIL mendapatkan Refresh Token!');
        $this->newLine();
        $this->line('Tambahkan baris ini ke file <fg=yellow>.env</> Anda:');
        $this->newLine();
        $this->warn('GOOGLE_REFRESH_TOKEN=' . $token['refresh_token']);
        $this->newLine();
        $this->line('Setelah mengisi .env, jalankan:');
        $this->line('  <fg=cyan>php artisan config:clear</>');
        $this->line('  <fg=cyan>php artisan queue:retry all</>');
        $this->line('  <fg=cyan>php artisan queue:work --tries=3</>');
        $this->newLine();
    }

    private function captureCodeViaSocket(int $port, int $timeout): ?string
    {
        $server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        if (!$server) {
            $this->error("Tidak bisa membuka socket di port {$port}: {$errstr} (errno {$errno})");
            return null;
        }

        $conn = stream_socket_accept($server, $timeout);
        if (!$conn) {
            fclose($server);
            $this->error('Tidak ada koneksi masuk dalam ' . $timeout . ' detik (timeout).');
            return null;
        }

        $request = '';
        while (!feof($conn) && !str_contains($request, "\r\n\r\n")) {
            $request .= fread($conn, 4096);
        }

        // Parse: "GET /?code=XXXX&scope=... HTTP/1.1"
        $code = null;
        if (preg_match('/GET \/?\\?([^ ]+) HTTP/', $request, $m)) {
            parse_str($m[1], $params);
            $code = $params['code'] ?? null;
        }

        if ($code) {
            $body = '<h2 style="font-family:sans-serif;color:green">Otorisasi berhasil! Silakan tutup tab ini.</h2>';
            $status = '200 OK';
        } else {
            $body = '<h2 style="font-family:sans-serif;color:red">Gagal menangkap code. Ulangi command.</h2>';
            $status = '400 Bad Request';
        }

        $len = strlen($body);
        fwrite($conn, "HTTP/1.1 {$status}\r\nContent-Type: text/html; charset=utf-8\r\nContent-Length: {$len}\r\nConnection: close\r\n\r\n{$body}");
        fclose($conn);
        fclose($server);

        return $code;
    }
}
