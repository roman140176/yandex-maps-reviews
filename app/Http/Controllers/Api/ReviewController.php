<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewResource;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Paginated reviews of one card.
 *
 * Served from our own database, never by parsing on demand: a full re-parse is
 * twelve sequential requests to Yandex and about twelve seconds, which is not
 * something a "next page" click can afford — and doing it per click would burn
 * the source's tolerance for no reason.
 */
final class ReviewController extends Controller
{
    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        $this->authorize('view', $organization);

        $sort = $request->string('sort')->toString();

        $reviews = $organization->reviews()
            ->withCount('revisions')
            ->when(
                $sort === 'rating_asc',
                fn ($query) => $query->orderBy('rating')->orderByDesc('published_at'),
                fn ($query) => $query->orderByDesc('published_at')->orderByDesc('id'),
            )
            ->paginate(config('parsing.reviews_per_page'))
            ->withQueryString();

        return ReviewResource::collection($reviews);
    }
}
