<?php

namespace App\Message;

final class Remind
{
    public function __construct(public readonly int $order, public readonly int $amount)
    {
    }
}
