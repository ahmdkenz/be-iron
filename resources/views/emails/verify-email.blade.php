<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Verifikasi Email</title>
  <style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6fb; margin: 0; padding: 0; }
    .container { max-width: 560px; margin: 40px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); padding: 36px 40px; text-align: center; }
    .header .logo { color: #38bdf8; font-size: 28px; font-weight: 800; letter-spacing: 2px; }
    .header .logo span { color: #ffffff; }
    .body { padding: 40px; }
    h2 { margin: 0 0 12px; font-size: 22px; color: #0f172a; }
    p { color: #475569; font-size: 15px; line-height: 1.7; margin: 0 0 16px; }
    .btn { display: inline-block; margin: 24px 0; padding: 14px 36px; background: #38bdf8; color: #0f172a; text-decoration: none; border-radius: 8px; font-weight: 700; font-size: 15px; }
    .note { font-size: 13px; color: #94a3b8; }
    .url-fallback { word-break: break-all; font-size: 12px; color: #94a3b8; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0; }
    .footer { background: #f8fafc; padding: 20px 40px; text-align: center; }
    .footer p { font-size: 12px; color: #94a3b8; margin: 0; }
  </style>
</head>
<body>
  <div class="container">
    <div class="header">
      <div class="logo">I.R.O<span>.N</span></div>
    </div>
    <div class="body">
      <h2>Verifikasi Alamat Email Anda</h2>
      <p>Halo, <strong>{{ $user->username }}</strong>.</p>
      <p>Anda baru saja menambahkan atau mengubah alamat email pada akun Anda. Klik tombol di bawah untuk memverifikasi alamat email ini.</p>

      <div style="text-align:center;">
        <a href="{!! $verifyUrl !!}" class="btn">Verifikasi Email Saya</a>
      </div>

      <p class="note">Link verifikasi ini akan kedaluwarsa dalam <strong>60 menit</strong>. Jika Anda tidak merasa melakukan perubahan ini, abaikan email ini.</p>

      <p class="note">Jika tombol di atas tidak berfungsi, salin dan tempel link berikut ke browser Anda:</p>
      <div class="url-fallback">{!! $verifyUrl !!}</div>
    </div>
    <div class="footer">
      <p>&copy; {{ date('Y') }} {{ config('app.name') }}. Semua hak dilindungi.</p>
    </div>
  </div>
</body>
</html>
