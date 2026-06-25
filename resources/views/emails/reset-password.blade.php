<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Reset Password</title>
  <style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4f6fb; margin: 0; padding: 0; }
    .container { max-width: 560px; margin: 40px auto; background: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 4px 24px rgba(0,0,0,0.08); }
    .header { background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); padding: 36px 40px; text-align: center; }
    .header .logo { color: #38bdf8; font-size: 28px; font-weight: 800; letter-spacing: 2px; }
    .header .logo span { color: #ffffff; }
    .body { padding: 40px; }
    h2 { margin: 0 0 12px; font-size: 22px; color: #0f172a; }
    p { color: #475569; font-size: 15px; line-height: 1.7; margin: 0 0 16px; }
    .btn { display: inline-block; margin: 24px 0; padding: 14px 36px; background: #ef4444; color: #ffffff; text-decoration: none; border-radius: 8px; font-weight: 700; font-size: 15px; }
    .note { font-size: 13px; color: #94a3b8; }
    .url-fallback { word-break: break-all; font-size: 12px; color: #94a3b8; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0; }
    .warning { background: #fef3c7; border: 1px solid #fcd34d; border-radius: 8px; padding: 14px 16px; margin: 20px 0; }
    .warning p { color: #92400e; font-size: 13px; margin: 0; }
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
      <h2>Reset Password Akun Anda</h2>
      <p>Halo, <strong>{{ $user->username }}</strong>.</p>
      <p>Kami menerima permintaan untuk mereset password akun Anda. Klik tombol di bawah untuk membuat password baru.</p>

      <div style="text-align:center;">
        <a href="{!! $resetUrl !!}" class="btn">Reset Password Saya</a>
      </div>

      <div class="warning">
        <p>⚠️ Link reset password ini akan kedaluwarsa dalam <strong>60 menit</strong>. Setelah reset berhasil, Anda akan diminta untuk login ulang di semua perangkat.</p>
      </div>

      <p class="note">Jika Anda tidak meminta reset password, abaikan email ini. Password Anda tidak akan berubah.</p>

      <p class="note">Jika tombol di atas tidak berfungsi, salin dan tempel link berikut ke browser Anda:</p>
      <div class="url-fallback">{!! $resetUrl !!}</div>
    </div>
    <div class="footer">
      <p>&copy; {{ date('Y') }} {{ config('app.name') }}. Semua hak dilindungi.</p>
    </div>
  </div>
</body>
</html>
