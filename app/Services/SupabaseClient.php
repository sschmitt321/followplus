<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SupabaseClient
{
    private string $baseUrl;
    private string $serviceKey;

    public function __construct()
    {
        $url = config('supabase.url');
        if (!$url) {
            throw new RuntimeException('SUPABASE_URL is not configured');
        }

        $this->baseUrl = rtrim($url, '/') . '/rest/v1/';
        $this->serviceKey = config('supabase.service_key', '');

        if (!$this->serviceKey) {
            throw new RuntimeException('SUPABASE_SERVICE_ROLE_KEY is not configured');
        }
    }

    /**
     * Make a request to Supabase REST API.
     *
     * @param string $method HTTP method (GET, POST, PUT, PATCH, DELETE)
     * @param string $path API path (e.g., 'messages', 'conversations')
     * @param array $query Query parameters
     * @param array|null $body Request body (will be JSON encoded)
     * @return array Decoded JSON response
     * @throws RuntimeException On non-2xx response
     */
    public function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->baseUrl . ltrim($path, '/');

        $headers = [
            'apikey' => $this->serviceKey,
            'Authorization' => 'Bearer ' . $this->serviceKey,
            'Content-Type' => 'application/json',
            'Prefer' => 'return=representation',
        ];

        $http = Http::withHeaders($headers);

        // Add query parameters if provided
        if (!empty($query)) {
            $http = $http->withQueryParameters($query);
        }

        // Make the request based on method
        $response = match (strtoupper($method)) {
            'GET' => $http->get($url),
            'POST' => $http->post($url, $body),
            'PUT' => $http->put($url, $body),
            'PATCH' => $http->patch($url, $body),
            'DELETE' => $http->delete($url),
            default => throw new RuntimeException("Unsupported HTTP method: {$method}"),
        };

        // Check for errors
        if (!$response->successful()) {
            $status = $response->status();
            $body = $response->body();
            $jsonBody = $response->json();
            
            // Try to extract more detailed error information
            $errorMessage = $body;
            if (is_array($jsonBody)) {
                $message = $jsonBody['message'] ?? $jsonBody['error'] ?? $body;
                $hint = $jsonBody['hint'] ?? null;
                $details = $jsonBody['details'] ?? null;
                
                $errorMessage = "Supabase API error [{$status}]: {$message}";
                if ($hint) {
                    $errorMessage .= "\nHint: {$hint}";
                }
                if ($details) {
                    $errorMessage .= "\nDetails: {$details}";
                }
            } else {
                $errorMessage = "Supabase API error [{$status}]: {$body}";
            }
            
            throw new RuntimeException($errorMessage, $status);
        }

        // Decode JSON response
        $data = $response->json();

        // If response is null or not an array, return empty array
        return is_array($data) ? $data : [];
    }
}

