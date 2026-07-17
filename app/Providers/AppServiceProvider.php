<?php

namespace App\Providers;

use App\Domain\Finance\Invoice\Observers\InvoiceObserver;
use App\Domain\Finance\PembayaranAr\Observers\PembayaranArObserver;
use App\Domain\Finance\TagihanAp\Observers\TagihanApObserver;
use App\Domain\Finance\VendorAp\Observers\VendorApObserver;
use App\Models\Invoice;
use App\Models\PembayaranAr;
use App\Models\TagihanAp;
use App\Models\VendorAp;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Invoice::observe(InvoiceObserver::class);
        PembayaranAr::observe(PembayaranArObserver::class);
        TagihanAp::observe(TagihanApObserver::class);
        VendorAp::observe(VendorApObserver::class);
    }
}
