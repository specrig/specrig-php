<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'reminders')]
class Reminder
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public ?int $id = null;

    public function __construct(
        #[ORM\Column(name: 'order_id')]
        public int $order,
        #[ORM\Column]
        public int $amount,
        #[ORM\Column(name: 'sent_at', type: Types::DATETIME_IMMUTABLE)]
        public \DateTimeImmutable $sentAt,
    ) {
    }
}
