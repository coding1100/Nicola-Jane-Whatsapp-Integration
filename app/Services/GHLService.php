<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GHLService
{
    /**
     * Upsert contact by phone number using GHL API v2
     * POST /contacts/ (Upsert Contact)
     */
    public function findOrCreateContactByPhone(string $apiKey, string $phone, string $locationId): string
    {
        $phone = $this->normalizePhone($phone);

        $url = 'https://services.leadconnectorhq.com/contacts/';

        $payload = [
            'phone'      => $phone,
            'locationId' => $locationId,
        ];

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type'  => 'application/json',
            'Version'       => '2021-07-28',
        ])->post($url, $payload);

        if (!$response->successful()) {
            $errorBody = $response->body();
            $errorJson = $response->json();
            
            // Handle duplicate contact error - GHL returns contactId in meta field
            if ($response->status() === 400 && isset($errorJson['message']) && 
                str_contains($errorJson['message'], 'duplicated contacts') && 
                isset($errorJson['meta']['contactId'])) {
                
                $contactId = $errorJson['meta']['contactId'];
                
                Log::info('Contact already exists (duplicate), using existing contactId', [
                    'contactId' => $contactId,
                    'phone' => $phone,
                    'contactName' => $errorJson['meta']['contactName'] ?? null,
                ]);
                
                return $contactId;
            }
            
            Log::error('GHL findOrCreateContactByPhone failed', [
                'status' => $response->status(),
                'body'   => $errorBody,
                'json'   => $errorJson,
                'phone'  => $phone,
            ]);
            throw new \RuntimeException('GHL findOrCreateContactByPhone failed: ' . $errorBody);
        }

        $contactData = $response->json();
        $contactId   = $contactData['contact']['id'] ?? $contactData['id'] ?? null;

        if (!$contactId) {
            Log::error('GHL contact creation did not return contactId', [
                'response' => $contactData,
            ]);
            throw new \RuntimeException('GHL contact creation did not return contactId');
        }

        Log::info('Contact found/created successfully', [
            'contactId' => $contactId,
            'phone' => $phone,
        ]);

        return $contactId;
    }

    /**
     * Create a conversation message in GHL with optional attachments
     * POST /conversations/messages
     */
    public function createConversationMessage(
        string $apiKey,
        string $contactId,
        ?string $message,
        ?array $media,
        string $type = 'whatsapp',
        ?string $locationId = null
    ): array {
        $conversationId = null;
        $attachmentUrls = [];

        // Always get or create conversation first (required by GHL)
        if ($locationId) {
            $conversationId = $this->getOrCreateConversation($apiKey, $contactId, $locationId, $type);
            Log::info('Conversation obtained/created', [
                'conversationId' => $conversationId,
                'contactId' => $contactId,
            ]);
        }

        // Handle media attachments if present
        if (!empty($media) && $conversationId) {
            foreach ($media as $mediaItem) {
                $mediaUrl  = is_array($mediaItem) ? ($mediaItem['url'] ?? $mediaItem['media'] ?? null) : $mediaItem;
                $mediaType = is_array($mediaItem) ? ($mediaItem['type'] ?? 'image') : 'image';

                if ($mediaUrl) {
                    try {
                        $uploadedUrl = $this->uploadAttachment($apiKey, $conversationId, $locationId, $mediaUrl, $mediaType);
                        if ($uploadedUrl) {
                            $attachmentUrls[] = $uploadedUrl;
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to upload media attachment', [
                            'error'    => $e->getMessage(),
                            'mediaUrl' => $mediaUrl,
                        ]);
                    }
                }
            }
        }

        // Use /inbound endpoint for incoming messages (per GHL official docs)
        $url = 'https://services.leadconnectorhq.com/conversations/messages/inbound';

        // Build payload per GHL API documentation for /conversations/messages/inbound
        $payload = [];

        // Either conversationId OR contactId is required (per GHL docs)
        // Include both if available for better association
        if ($conversationId) {
            $payload['conversationId'] = $conversationId;
        }
        
        if ($contactId) {
            $payload['contactId'] = $contactId;
        }

        // Validate required fields
        if (empty($payload['conversationId']) && empty($payload['contactId'])) {
            throw new \RuntimeException('Either conversationId or contactId is required for GHL inbound message');
        }

        // Message body field - GHL inbound endpoint uses "body"
        if ($message) {
            $payload['body'] = $message;
        }

        if ($locationId) {
            $payload['locationId'] = $locationId;
        }

        // Channel type - GHL inbound endpoint requires uppercase enum value
        // Valid values: "WHATSAPP", "SMS", "EMAIL", "VOICE", etc.
        $payload['type'] = strtoupper($type); // e.g. "WHATSAPP"

        if (!empty($attachmentUrls)) {
            $payload['attachments'] = $attachmentUrls;
        }

        // Log payload before sending
        Log::info('Sending inbound message to GHL', [
            'url' => $url,
            'payload' => $payload,
            'hasConversationId' => !empty($conversationId),
            'hasContactId' => !empty($contactId),
            'hasBody' => !empty($message),
            'hasLocationId' => !empty($locationId),
        ]);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type'  => 'application/json',
            'Version'       => '2021-07-28',
        ])->post($url, $payload);

        // Log full response for debugging
        Log::info('GHL API response received', [
            'status' => $response->status(),
            'successful' => $response->successful(),
            'response_body' => $response->body(),
            'response_json' => $response->json(),
        ]);

        if (!$response->successful()) {
            $errorBody = $response->body();
            $errorJson = $response->json();
            
            Log::error('GHL createConversationMessage failed', [
                'status'  => $response->status(),
                'body'    => $errorBody,
                'json'    => $errorJson,
                'payload' => $payload,
                'url'     => $url,
            ]);
            
            throw new \RuntimeException('GHL createConversationMessage failed (HTTP ' . $response->status() . '): ' . $errorBody);
        }

        $responseData = $response->json();
        
        Log::info('GHL message created successfully', [
            'message_id' => $responseData['message']['id'] ?? $responseData['id'] ?? null,
            'conversation_id' => $responseData['conversationId'] ?? null,
        ]);

        return $responseData;
    }

    /**
     * Get or create a conversation for a contact
     */
    private function getOrCreateConversation(string $apiKey, string $contactId, string $locationId, string $type): string
    {
        $url = 'https://services.leadconnectorhq.com/conversations/';

        Log::info('Checking for existing conversation', [
            'contactId' => $contactId,
            'locationId' => $locationId,
        ]);

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type'  => 'application/json',
            'Version'       => '2021-07-28',
        ])->get($url, [
            'contactId'  => $contactId,
            'locationId' => $locationId,
        ]);

        if ($response->successful()) {
            $data          = $response->json();
            $conversations = $data['conversations'] ?? $data['data'] ?? [];
            
            Log::info('Existing conversations found', [
                'count' => count($conversations),
                'conversations' => $conversations,
            ]);
            
            if (!empty($conversations)) {
                $conversationId = $conversations[0]['id'] ?? $conversations[0]['conversationId'] ?? null;
                if ($conversationId) {
                    Log::info('Using existing conversation', ['conversationId' => $conversationId]);
                    return $conversationId;
                }
            }
        } else {
            Log::warning('Failed to fetch existing conversations', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
        }

        // Create new conversation
        Log::info('Creating new conversation', [
            'contactId' => $contactId,
            'locationId' => $locationId,
            'type' => $type,
        ]);

        $createUrl      = 'https://services.leadconnectorhq.com/conversations/';
        $createPayload = [
            'contactId'  => $contactId,
            'locationId' => $locationId,
            'type'       => strtolower($type),
        ];

        $createResponse = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type'  => 'application/json',
            'Version'       => '2021-07-28',
        ])->post($createUrl, $createPayload);

        if (!$createResponse->successful()) {
            Log::error('Failed to create conversation', [
                'status' => $createResponse->status(),
                'body' => $createResponse->body(),
                'payload' => $createPayload,
            ]);
            throw new \RuntimeException('Failed to create conversation (HTTP ' . $createResponse->status() . '): ' . $createResponse->body());
        }

        $conversationData = $createResponse->json();
        $conversationId   = $conversationData['conversation']['id'] ?? $conversationData['id'] ?? $conversationData['conversationId'] ?? null;

        if (!$conversationId) {
            Log::error('Conversation creation did not return id', [
                'response' => $conversationData,
            ]);
            throw new \RuntimeException('Conversation creation did not return id. Response: ' . json_encode($conversationData));
        }

        Log::info('New conversation created', ['conversationId' => $conversationId]);

        return $conversationId;
    }

    /**
     * Upload attachment to GHL
     * POST /conversations/messages/upload
     */
    private function uploadAttachment(string $apiKey, string $conversationId, string $locationId, string $fileUrl, string $fileType = 'image'): ?string
    {
        $url = 'https://services.leadconnectorhq.com/conversations/messages/upload';

        try {
            $fileContent = Http::get($fileUrl)->body();
            $tempFile    = tmpfile();
            $tempPath    = stream_get_meta_data($tempFile)['uri'];
            file_put_contents($tempPath, $fileContent);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Version'       => '2021-07-28',
            ])->attach('fileAttachment', file_get_contents($tempPath), basename($fileUrl))
              ->post($url, [
                  'conversationId' => $conversationId,
                  'locationId'     => $locationId,
              ]);

            fclose($tempFile);

            if ($response->successful()) {
                $data = $response->json();

                // Official docs show "uploadedFiles" with URLs :contentReference[oaicite:6]{index=6}
                if (isset($data['uploadedFiles']) && is_array($data['uploadedFiles'])) {
                    $first = $data['uploadedFiles'][0] ?? null;
                    if (is_string($first)) {
                        return $first;
                    }
                    if (is_array($first) && isset($first['url'])) {
                        return $first['url'];
                    }
                }

                return $data['url'] ?? $data['fileUrl'] ?? null;
            }
        } catch (\Exception $e) {
            Log::error('Error uploading attachment to GHL', [
                'error'   => $e->getMessage(),
                'fileUrl' => $fileUrl,
            ]);
        }

        return null;
    }

    /**
     * Update message status in GHL using API v2
     * PUT /conversations/messages/:messageId/status
     */
    public function updateMessageStatus(string $apiKey, string $messageId, string $status): bool
    {
        $url = "https://services.leadconnectorhq.com/conversations/messages/{$messageId}/status";

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type'  => 'application/json',
            'Version'       => '2021-07-28',
        ])->put($url, [
            'status' => $status,
        ]);

        if (!$response->successful()) {
            Log::warning('GHL message status update failed', [
                'status'     => $response->status(),
                'body'       => $response->body(),
                'messageId'  => $messageId,
                'statusValue'=> $status,
            ]);
            return false;
        }

        return true;
    }

    /**
     * Add tags to a contact (used for STOP, etc.)
     * POST /contacts/:contactId/tags :contentReference[oaicite:7]{index=7}
     */
    public function addTags(string $apiKey, string $contactId, array $tags): void
    {
        $url = "https://services.leadconnectorhq.com/contacts/{$contactId}/tags";

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type'  => 'application/json',
            'Version'       => '2021-07-28',
        ])->post($url, [
            'tags' => array_values(array_unique($tags)),
        ]);

        if (!$response->successful()) {
            Log::warning('Failed to add tags to contact', [
                'contactId' => $contactId,
                'tags'      => $tags,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);
        }
    }

    /**
     * Remove tags using bulk tags API.
     * POST /contacts/bulk/tags/update/remove :contentReference[oaicite:8]{index=8}
     */
    public function removeTags(string $apiKey, string $contactId, array $tags, string $locationId): void
    {
        $url = 'https://services.leadconnectorhq.com/contacts/bulk/tags/update/remove';

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type'  => 'application/json',
            'Version'       => '2021-07-28',
        ])->post($url, [
            'contacts'  => [$contactId],
            'tags'      => array_values(array_unique($tags)),
            'locationId'=> $locationId,
            'removeAllTags' => false,
        ]);

        if (!$response->successful()) {
            Log::warning('Failed to remove tags from contact', [
                'contactId' => $contactId,
                'tags'      => $tags,
                'status'    => $response->status(),
                'body'      => $response->body(),
            ]);
        }
    }

    /**
     * Normalize phone number to E.164 format
     */
    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^\d+]/', '', $phone);

        if (!str_starts_with($phone, '+')) {
            if (strlen($phone) === 10) {
                $phone = '+1' . $phone;
            } else {
                $phone = '+1' . $phone;
            }
        }

        return $phone;
    }
}
