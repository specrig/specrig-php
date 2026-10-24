<?php

namespace App\Controller;

use Doctrine\DBAL\Exception\ConnectionException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;

final class Problem
{
    public static function of(int $status, string $type, string $title): JsonResponse
    {
        return new JsonResponse(['type' => $type, 'title' => $title, 'status' => $status], $status, ['Content-Type' => 'application/problem+json']);
    }

    #[AsEventListener]
    public function onException(ExceptionEvent $event): void
    {
        for ($e = $event->getThrowable(); $e !== null; $e = $e->getPrevious()) {
            if ($e instanceof ConnectionException) {
                $event->setResponse(self::of(503, 'database_unavailable', 'the reminders cannot reach their database'));
                return;
            }
        }
    }
}
