<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/orders')]
final class OrderController
{
    #[Route('/{id<\d+>}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        return new JsonResponse([]);
    }

    #[Route('', name: 'place', methods: 'POST|PUT')]
    public function place(): JsonResponse
    {
        return new JsonResponse([]);
    }
}
