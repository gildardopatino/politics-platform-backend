<?php

namespace App\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Standard API response envelope.
 *
 * Success (single):    { "data": <resource>, "message"?: string }
 * Success (paginated): { "data": [...], "meta": { total, current_page, last_page, per_page, from, to } }
 * Error:               { "message": string, "errors"?: object }
 *
 * Use this in API controllers instead of hand-building response()->json(...)
 * so the frontend always sees the same shape.
 */
trait ApiResponse
{
    /**
     * Single resource / arbitrary payload.
     */
    protected function respondData(mixed $data, ?string $message = null, int $status = 200): JsonResponse
    {
        $payload = ['data' => $data];

        if ($message !== null) {
            $payload['message'] = $message;
        }

        return response()->json($payload, $status);
    }

    /**
     * Paginated collection. Optionally wrap items in a JsonResource class.
     *
     * @param  class-string<JsonResource>|null  $resource
     */
    protected function respondPaginated(LengthAwarePaginator $paginator, ?string $resource = null): JsonResponse
    {
        $items = $resource
            ? $resource::collection($paginator->getCollection())
            : $paginator->items();

        return response()->json([
            'data' => $items,
            'meta' => [
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    /**
     * Message-only success (e.g. delete).
     */
    protected function respondMessage(string $message, int $status = 200): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }

    /**
     * Error response.
     */
    protected function respondError(string $message, int $status = 400, ?array $errors = null): JsonResponse
    {
        $payload = ['message' => $message];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /**
     * Resolve a safe per_page value from the request (default 15, hard cap 100).
     */
    protected function resolvePerPage(int $default = 15, int $max = 100): int
    {
        $perPage = (int) request('per_page', $default);

        if ($perPage < 1) {
            $perPage = $default;
        }

        return min($perPage, $max);
    }
}
