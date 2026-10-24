<?php

namespace App\Controller;

use App\Entity\Reminder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class ReminderController
{
    public function __construct(private readonly EntityManagerInterface $entities)
    {
    }

    #[Route('/reminders/{order<\d+>}', methods: ['GET'])]
    public function show(int $order): JsonResponse
    {
        $reminder = $this->entities->getRepository(Reminder::class)->findOneBy(['order' => $order], ['sentAt' => 'DESC']);
        if ($reminder === null) {
            return Problem::of(404, 'reminder_not_found', "order {$order} was never reminded of");
        }
        return new JsonResponse(['order' => $order, 'amount' => $reminder->amount, 'sentAt' => $reminder->sentAt->format(DATE_ATOM)]);
    }
}
