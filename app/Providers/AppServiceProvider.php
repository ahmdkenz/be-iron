<?php

namespace App\Providers;

use App\Domain\Finance\Invoice\Observers\InvoiceObserver;
use App\Domain\Finance\PembayaranAr\Observers\PembayaranArObserver;
use App\Models\Invoice;
use App\Models\PembayaranAr;
use App\Support\Helpers\GoogleDriveService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GoogleDriveService::class, fn () => new GoogleDriveService());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Invoice::observe(InvoiceObserver::class);
        PembayaranAr::observe(PembayaranArObserver::class);
    }
}
