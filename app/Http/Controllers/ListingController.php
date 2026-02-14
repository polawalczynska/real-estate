<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Listing;
use Illuminate\View\View;

final class ListingController extends Controller
{
    public function show(string $id): View
    {
        $listing = Listing::findOrFail($id);

        return view('listings.show', [
            'listing' => $listing,
        ]);
    }
}
