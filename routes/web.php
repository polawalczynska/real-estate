<?php

declare(strict_types=1);

use App\Http\Controllers\HomeController;
use App\Http\Controllers\ListingController;
use App\Livewire\Listings\Index as ListingsIndex;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/listings', ListingsIndex::class)->name('listings.index');
Route::get('/listings/{id}', [ListingController::class, 'show'])->name('listings.show');
