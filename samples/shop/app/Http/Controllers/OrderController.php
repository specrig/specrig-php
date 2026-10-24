<?php

namespace App\Http\Controllers;

use App\Announcer;
use App\Billing;
use App\Http\Problem;
use App\Jobs\InvoiceOrder;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class OrderController
{
    public function show(int $id, Billing $billing): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) {
            return Problem::of(404, 'order_not_found', "no order {$id}");
        }
        return new JsonResponse(['id' => $id, 'toPay' => $order->paid ? 0 : $billing->toPay($id)]);
    }

    public function store(Request $request, Announcer $announcer): JsonResponse
    {
        $placed = $request->validate(['id' => 'required|integer', 'amount' => 'required|integer|min:1']);
        Order::create($placed + ['paid' => false]);
        InvoiceOrder::dispatch($placed['id'], $placed['amount']);
        $announcer->placed($placed['id'], $placed['amount']);
        return new JsonResponse(['id' => $placed['id']], 201);
    }
}
