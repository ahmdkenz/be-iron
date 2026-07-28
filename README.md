<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Menjalankan Queue Worker & Scheduler (Import Batch, Sinkron SHZ360)

Fitur import (Import Master Data, Import Rekening Koran, dsb.) berjalan sebagai background job (`QUEUE_CONNECTION=database`). Tanpa worker aktif, job hanya akan tertahan di status `queued`/`processing` dan tidak pernah selesai.

Import Rekening Koran (Rekonsiliasi Bank) dijalankan di queue bernama `bank-statement` (terpisah dari `default`) — worker **wajib** menyertakan `--queue=bank-statement,default`, kalau tidak job import rekening koran tidak akan pernah diambil.

Selain worker, project ini juga punya **Laravel Scheduler** (`bootstrap/app.php` → `withSchedule()`) untuk task terjadwal lain (mis. sinkron PO/Terima PO SHZ360 tiap 5 menit). Worker queue di atas **juga didaftarkan lewat scheduler yang sama** (`queue:work ... --stop-when-empty` tiap menit) — jadi di produksi cukup **satu** cron job (`schedule:run`) yang menjalankan semuanya, tidak perlu cron job terpisah untuk worker.

**Lokal (development):**
- Cara termudah: `composer run dev` — sudah menyalakan `queue:listen --queue=bank-statement,default` bersama server & vite (langsung, tanpa lewat scheduler).
- Atau manual di terminal terpisah: `php artisan queue:work database --queue=bank-statement,default --tries=1 --timeout=1800`
- Untuk mengecek/testing scheduler lokal: `php artisan schedule:list` (lihat semua task terjadwal) atau `php artisan schedule:work` (jalankan scheduler terus-menerus di lokal).

**Produksi (cPanel / shared hosting):**
Daemon jangka panjang (`queue:work` tanpa henti) biasanya tidak diizinkan di shared hosting, begitu juga scheduler Laravel butuh crontab asli untuk memicunya tiap menit. Karena queue worker sudah didaftarkan lewat scheduler (lihat `bootstrap/app.php`), **cukup satu Cron Job** di cPanel:
1. cPanel → Cron Jobs → tambah job baru, jadwal `* * * * *` (every minute).
2. Command (sesuaikan path PHP & path project):
   ```
   /usr/local/bin/php /home/<user>/<path-to>/be-iron/artisan schedule:run >> /home/<user>/<path-to>/be-iron/storage/logs/scheduler.log 2>&1
   ```
3. Baris ini otomatis menjalankan **semua** task terjadwal setiap kali due — sinkron SHZ360 (tiap 5 menit) maupun queue worker (tiap menit, berhenti sendiri saat antrian kosong lewat `--stop-when-empty`). Verifikasi task apa saja yang terdaftar dengan `php artisan schedule:list`.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
