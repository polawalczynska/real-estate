<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Listing;
use Illuminate\View\View;

final class HomeController extends Controller
{
    public function index(): View
    {
        $featuredListings = Listing::query()
            ->select(Listing::CARD_COLUMNS)
            ->with('media')
            ->available()
            ->orderByDesc('quality_score')
            ->orderByDesc('created_at')
            ->limit(3)
            ->get();

        return view('welcome', [
            'featuredListings' => $featuredListings,
        ]);
    }
}
