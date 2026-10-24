<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/health')]
final class Health
{
    public function __invoke(): Response
    {
        return new Response();
    }
}
