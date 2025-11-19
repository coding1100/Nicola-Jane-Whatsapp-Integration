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
            Log::error('GHL findOrCreateContactByPhone failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
                'phone'  => $phone,
            ]);
            throw new \RuntimeException('GHL findOrCreateContactByPhone failed: ' . $response->body());
        }

        $contactData = $response->json();
        $contactId   = $contactData['contact']['id'] ?? $contactData['id'] ?? null;

        if (!$contactId) {
            throw new \RuntimeException('GHL contact creation did not return contactId');
        }

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

        if (!empty($media) && $locationId) {
            $conversationId = $this->getOrCreateConversation($apiKey, $contactId, $locationId, $type);

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

        $url = 'https://services.leadconnectorhq.com/conversations/messages/';

        $payload = [
            'contactId' => $contactId,
            // best-effort – actual schema is “body” in webhooks,
            // but for sending they accept “message/body” depending on channel.
        ];

        if ($message) {
            $payload['message'] = $message;
        }

        if ($locationId) {
            $payload['locationId'] = $locationId;
        }

        // Channel / messageType – this may be interpreted as WhatsApp channel when configured
        $payload['messageType'] = strtoupper($type); // e.g. WHATSAPP
        // Some installs also accept 'channel' or 'type', but we avoid guessing too much.

        if (!empty($attachmentUrls)) {
            // Most implementations accept simple URL array; if they later require objects, you adjust here.
            $payload['attachments'] = $attachmentUrls;
        }

        $response = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type'  => 'application/json',
            'Version'       => '2021-07-28',
        ])->post($url, $payload);

        if (!$response->successful()) {
            Log::error('GHL createConversationMessage failed', [
                'status'  => $response->status(),
                'body'    => $response->body(),
                'payload' => $payload,
            ]);
            throw new \RuntimeException('GHL createConversationMessage failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Get or create a conversation for a contact
     */
    private function getOrCreateConversation(string $apiKey, string $contactId, string $locationId, string $type): string
    {
        $url = 'https://services.leadconnectorhq.com/conversations/';

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
            if (!empty($conversations)) {
                return $conversations[0]['id'] ?? $conversations[0]['conversationId'] ?? null;
            }
        }

        $createUrl      = 'https://services.leadconnectorhq.com/conversations/';
        $createResponse = Http::withHeaders([
            'Authorization' => "Bearer {$apiKey}",
            'Content-Type'  => 'application/json',
            'Version'       => '2021-07-28',
        ])->post($createUrl, [
            'contactId'  => $contactId,
            'locationId' => $locationId,
            'type'       => $type,
        ]);

        if (!$createResponse->successful()) {
            throw new \RuntimeException('Failed to create conversation: ' . $createResponse->body());
        }

        $conversationData = $createResponse->json();
        $conversationId   = $conversationData['conversation']['id'] ?? $conversationData['id'] ?? null;

        if (!$conversationId) {
            throw new \RuntimeException('Conversation creation did not return id');
        }

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
