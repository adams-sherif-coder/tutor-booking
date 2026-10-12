<?php

namespace App\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

trait JsonResponseTrait
{
    public function json_response(array $data = [], ?string $message = null, int $status = 200, bool $ok = true): JsonResponse
    {
        return response()->json(['data' => $data, 'message' => $message ?? Response::$statusTexts[$status], 'status' => $status, 'ok' => $ok], $status);
    }

    protected function pagination(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }
}
