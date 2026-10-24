<?php

namespace App;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

final class Crm
{
    private const LETTERS = '/letters';

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.crm.url'))->acceptJson();
    }

    public function customer(int $id): array
    {
        return $this->http()->get('/customers/' . $id)->json();
    }

    public function notes(int $id): array
    {
        return Http::get(sprintf('/customers/%d/notes', $id))->json();
    }

    public function letter(array $letter): void
    {
        $this->http()->post(self::LETTERS, $letter);
    }

    public function forget(string $path): void
    {
        $this->http()->send('DELETE', $path);
    }

    public function forgetCustomer(int $id): void
    {
        $this->forget("/customers/{$id}");
    }
}
