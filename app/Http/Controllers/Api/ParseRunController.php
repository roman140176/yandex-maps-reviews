<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParseRunResource;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Parse history: what ran, when, how far it got and why it stopped. */
final class ParseRunController extends Controller
{
    public function index(Request $request, Organization $organization): AnonymousResourceCollection
    {
        $this->authorize('view', $organization);

        return ParseRunResource::collection(
            $organization->parseRuns()->latest('id')->limit(20)->get(),
        );
    }
}
