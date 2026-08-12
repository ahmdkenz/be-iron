<?php

use App\Domain\Quran\Surah\Controllers\SurahController;
use Illuminate\Support\Facades\Route;

Route::get('surah', [SurahController::class, 'index']);
Route::get('surah/{nomor}', [SurahController::class, 'show'])->whereNumber('nomor');
