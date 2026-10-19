<?php

use Illuminate\Support\Facades\Route;

// No public landing page: the app starts at the dashboard (guests go to login).
Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
require __DIR__.'/pallets.php';
require __DIR__.'/admin.php';
