<?php

declare(strict_types=1);

namespace App\Http\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/** The contract's envelopes (api-contract section 1). */
final class ApiResponse
{
    /** A single resource: `{ "data": {...} }`. */
    public static function item(JsonResource $resource, int $status = 200): JsonResponse
    {
        return response()->json(['data' => $resource->resolve(request())], $status);
    }

    /**
     * A paginated list: `{ data, meta {current_page, last_page, per_page,
     * total, ...extra}, links {first, last, prev, next} }`.
     *
     * @param  class-string<JsonResource>  $resourceClass
     * @param  array<string, mixed>  $extraMeta
     * @param  LengthAwarePaginator<int, mixed>  $paginator
     */
    public static function paginated(LengthAwarePaginator $paginator, string $resourceClass, array $extraMeta = []): JsonResponse
    {
        $paginator->withQueryString();

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn ($model) => (new $resourceClass($model))->resolve(request()))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                ...$extraMeta,
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    /** An unpaginated list (reference data): `{ "data": [...] }`. */
    public static function collection(iterable $items, string $resourceClass): JsonResponse
    {
        return response()->json([
            'data' => collect($items)
                ->map(fn ($model) => (new $resourceClass($model))->resolve(request()))
                ->values()
                ->all(),
        ]);
    }

    /** DELETE and similar: 204 with no body. */
    public static function noContent(): JsonResponse
    {
        return response()->json(null, 204);
    }
}