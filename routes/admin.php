<?php

use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->prefix('admin')->name('admin.')->group(function () {
    Route::livewire('users', 'pages::admin.users')->middleware('can:users.manage')->name('users');
    Route::livewire('audit', 'pages::admin.audit')->middleware('can:audit.view')->name('audit');
});
