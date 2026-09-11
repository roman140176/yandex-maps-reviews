<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewRevisionResource;
use App\Models\Organization;
use App\Models\ReviewRevision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * "Was → became" across parses: edited reviews, changed ratings, replies that
 * appeared. This is what the stored history is for — without it a re-parse
 * would overwrite the past and nobody could tell what moved.
 */
final class ReviewChangeController extends Controller
{
    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        $this->authorize('view', $organization);

        $revisions = ReviewRevision::query()
            ->with('review:id,author_name,external_id')
            ->whereIn('review_id', $organization->reviews()->select('id'))
            ->latest('id')
            ->paginate(25);

        return ReviewRevisionResource::collection($revisions);
    }
}
