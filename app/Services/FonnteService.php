<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class FonnteService
{
    protected string $token;
    protected string $baseUrl = 'https://api.fonnte.com';

    public function __construct()
    {
        $this->token = config('services.fonnte.token');
    }

    // Kirim pesan personal ke nomor WA
    public function send(string $phone, string $message, int $delay = 15): bool
    {
        try {
            $phone = $this->formatPhone($phone); 

            $response = Http::withHeaders([
                'Authorization' => $this->token,
            ])
            ->asForm()
            ->post("{$this->baseUrl}/send", [
                'target'      => $phone,
                'message'     => $message,
                'delay'       => $delay,
                'countryCode' => '62',
            ]);

            if ($response->successful() && $response->json('status')) {
                return true;
            }

            Log::warning('Fonnte gagal kirim pesan', [
                'phone'    => $phone,
                'response' => $response->json(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('Fonnte error: ' . $e->getMessage());
            return false;
        }
    }

    // Kirim pesan ke grup WA
    public function sendGroup(string $message): bool
    {
        $groupId = config('services.fonnte.group_id');

        if (!$groupId) {
            Log::warning('Fonnte group_id belum dikonfigurasi.');
            return false;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => $this->token,
            ])->post("{$this->baseUrl}/send", [
                'target'      => $groupId,
                'message'     => $message,
                'countryCode' => '62',
            ]);

            if ($response->successful() && $response->json('status')) {
                return true;
            }

            Log::warning('Fonnte gagal kirim pesan grup', [
                'response' => $response->json(),
            ]);

            return false;
        } catch (\Throwable $e) {
            Log::error('Fonnte group error: ' . $e->getMessage());
            return false;
        }
    }

    private function formatPhone(string $phone): string
    {
        $phone = preg_replace('/\D/', '', $phone);

        if (str_starts_with($phone, '0')) {
            $phone = '62' . substr($phone, 1);
        }

        return $phone;
    }
}