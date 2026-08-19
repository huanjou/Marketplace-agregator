<?php

use App\Livewire\PublicProductSearch;
use Illuminate\Support\Facades\Route;

// Public product search — no authentication required.
// The admin panel (sync logs, provider settings) stays behind Filament auth
// at /admin.
Route::get('/', PublicProductSearch::class)->name('home');
