<?php

namespace App\Http;

use Illuminate\Http\JsonResponse;

final class Problem
{
    public static function of(int $status, string $type, string $title): JsonResponse
    {
        return new JsonResponse(['type' => $type, 'title' => $title, 'status' => $status], $status, ['Content-Type' => 'application/problem+json']);
    }
}
