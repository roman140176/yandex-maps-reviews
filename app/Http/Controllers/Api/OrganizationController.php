<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\Organization;
use App\Services\Organizations\OrganizationConnector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Connected organisation cards.
 *
 * The controller does no parsing itself — it validates, delegates to the
 * connector, and reads from the database. A card's data is fetched by a queued
 * job, so "create" returns immediately with a card in `queued` state.
 */
final class OrganizationController extends Controller
{
    public function __construct(private readonly OrganizationConnector $connector) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $organizations = $request->user()
            ->organizations()
            ->with('latestParseRun')
            ->latest('id')
            ->get();

        return OrganizationResource::collection($organizations);
    }

    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $organization = $this->connector->connect($request->user(), $request->url());

        return OrganizationResource::make($organization->load('latestParseRun'))
            ->response()
            ->setStatusCode($organization->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Request $request, Organization $organization): OrganizationResource
    {
        $this->authorize('view', $organization);

        return OrganizationResource::make($organization->load('latestParseRun'));
    }

    public function refresh(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('update', $organization);

        $queued = $this->connector->refresh($organization);

        return response()->json([
            'queued' => $queued,
            'message' => $queued
                ? 'Обновление запущено.'
                : 'Обновление уже выполняется.',
            'organization' => OrganizationResource::make($organization->fresh()->load('latestParseRun')),
        ], $queued ? 202 : 200);
    }

    public function destroy(Request $request, Organization $organization): JsonResponse
    {
        $this->authorize('delete', $organization);

        $organization->delete();

        return response()->json(['message' => 'Карточка отключена.']);
    }
}
