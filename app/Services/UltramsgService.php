<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UltramsgService
{
    /**
     * Send text message via Ultramsg
     * Uses x-www-form-urlencoded as per Ultramsg docs.
     */
    public function sendText(string $instanceId, string $apiToken, string $phone, string $message, ?string $referenceId = null): array
    {
        $url = "https://api.ultramsg.com/{$instanceId}/messages/chat";

        $payload = [
            'token' => $apiToken,
            'to'    => $phone,
            'body'  => $message,
        ];

        if ($referenceId) {
            $payload['referenceId'] = $referenceId;
        }

        $response = Http::asForm()->post($url, $payload);

        if (!$response->successful()) {
            Log::error('Ultramsg sendText failed', [
                'status'  => $response->status(),
                'body'    => $response->body(),
                'payload' => $payload,
            ]);
            throw new \RuntimeException('Ultramsg sendText failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Send image via Ultramsg
     */
    public function sendImage(string $instanceId, string $apiToken, string $phone, string $imageUrl, ?string $caption = null, ?string $referenceId = null): array
    {
        $url = "https://api.ultramsg.com/{$instanceId}/messages/image";

        $payload = [
            'token' => $apiToken,
            'to'    => $phone,
            'image' => $imageUrl,
        ];

        if ($caption) {
            $payload['caption'] = $caption;
        }

        if ($referenceId) {
            $payload['referenceId'] = $referenceId;
        }

        $response = Http::asForm()->post($url, $payload);

        if (!$response->successful()) {
            Log::error('Ultramsg sendImage failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Ultramsg sendImage failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Send document via Ultramsg
     */
    public function sendDocument(string $instanceId, string $apiToken, string $phone, string $documentUrl, ?string $filename = null, ?string $referenceId = null): array
    {
        $url = "https://api.ultramsg.com/{$instanceId}/messages/document";

        $payload = [
            'token'    => $apiToken,
            'to'       => $phone,
            'document' => $documentUrl,
        ];

        if ($filename) {
            $payload['filename'] = $filename;
        }

        if ($referenceId) {
            $payload['referenceId'] = $referenceId;
        }

        $response = Http::asForm()->post($url, $payload);

        if (!$response->successful()) {
            Log::error('Ultramsg sendDocument failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Ultramsg sendDocument failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Send audio via Ultramsg
     */
    public function sendAudio(string $instanceId, string $apiToken, string $phone, string $audioUrl, ?string $referenceId = null): array
    {
        $url = "https://api.ultramsg.com/{$instanceId}/messages/audio";

        $payload = [
            'token' => $apiToken,
            'to'    => $phone,
            'audio' => $audioUrl,
        ];

        if ($referenceId) {
            $payload['referenceId'] = $referenceId;
        }

        $response = Http::asForm()->post($url, $payload);

        if (!$response->successful()) {
            Log::error('Ultramsg sendAudio failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Ultramsg sendAudio failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Send video via Ultramsg
     */
    public function sendVideo(string $instanceId, string $apiToken, string $phone, string $videoUrl, ?string $caption = null, ?string $referenceId = null): array
    {
        $url = "https://api.ultramsg.com/{$instanceId}/messages/video";

        $payload = [
            'token' => $apiToken,
            'to'    => $phone,
            'video' => $videoUrl,
        ];

        if ($caption) {
            $payload['caption'] = $caption;
        }

        if ($referenceId) {
            $payload['referenceId'] = $referenceId;
        }

        $response = Http::asForm()->post($url, $payload);

        if (!$response->successful()) {
            Log::error('Ultramsg sendVideo failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Ultramsg sendVideo failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Parse incoming message webhook from Ultramsg
     * This is intentionally defensive – you will adjust keys once you see real payloads.
     */
    public function parseIncomingMessage(array $payload): ?object
    {
        try {
            Log::info('parseIncomingMessage called', [
                'payload_keys' => array_keys($payload),
                'has_data_key' => isset($payload['data']),
            ]);

            $instanceId  = $payload['instanceId'] ?? null;
            // Check multiple locations for referenceId (Ultramsg may put it in different places)
            $referenceId = $payload['referenceId'] 
                ?? $payload['data']['referenceId'] 
                ?? $payload['data']['reference_id']
                ?? $payload['reference_id']
                ?? null;
            $data        = $payload['data'] ?? $payload; // fallback

            Log::info('Extracting fields from payload', [
                'instanceId' => $instanceId,
                'referenceId' => $referenceId,
                'data_keys' => is_array($data) ? array_keys($data) : 'not_array',
                'payload_keys' => array_keys($payload),
            ]);

            $phone  = $data['from'] ?? $data['sender'] ?? $payload['from'] ?? $payload['phone'] ?? null;
            $body   = $data['body'] ?? $data['text'] ?? $payload['body'] ?? null;
            $media  = $data['media'] ?? $payload['media'] ?? null;
            $msgId  = $data['id'] ?? $payload['id'] ?? null;

            Log::info('Extracted raw values', [
                'phone_raw' => $phone,
                'body_raw' => $body,
                'media_raw' => $media,
                'msgId_raw' => $msgId,
            ]);

            // JID -> number (1234567890@s.whatsapp.net)
            if ($phone) {
                $phone = preg_replace('/@.*$/', '', $phone);
                if (!str_starts_with($phone, '+')) {
                    if (strlen($phone) === 10) {
                        $phone = '+1' . $phone;
                    } else {
                        $phone = '+' . ltrim($phone, '+');
                    }
                }
            }

            // Normalise media to array of ['url' => ..., 'type' => ...]
            $mediaArray = [];
            if ($media) {
                if (!is_array($media)) {
                    $mediaArray = [['url' => (string) $media, 'type' => 'media']];
                } elseif (array_is_list($media)) {
                    foreach ($media as $item) {
                        if (is_array($item)) {
                            $mediaArray[] = [
                                'url'  => $item['url'] ?? $item['media'] ?? null,
                                'type' => $item['type'] ?? 'media',
                            ];
                        } else {
                            $mediaArray[] = ['url' => (string) $item, 'type' => 'media'];
                        }
                    }
                } else {
                    $mediaArray[] = [
                        'url'  => $media['url'] ?? $media['media'] ?? null,
                        'type' => $media['type'] ?? 'media',
                    ];
                }

                // Drop empties
                $mediaArray = array_values(array_filter($mediaArray, fn ($m) => !empty($m['url'])));
            }

            // Log body value before validation
            Log::info('Body value before validation check', [
                'body' => $body,
                'body_type' => gettype($body),
                'body_is_null' => is_null($body),
                'body_is_string' => is_string($body),
                'body_empty' => empty($body),
                'body_length' => is_string($body) ? strlen($body) : 0,
                'body_trimmed' => is_string($body) ? trim($body) : 'N/A',
                'body_trimmed_length' => is_string($body) ? strlen(trim($body)) : 0,
                'body_truthy' => (bool)$body,
            ]);

            if (!$phone || (!$body && empty($mediaArray))) {
                Log::warning('parseIncomingMessage validation failed', [
                    'has_phone' => !empty($phone),
                    'has_body' => !empty($body),
                    'has_media' => !empty($mediaArray),
                    'phone' => $phone,
                    'body' => $body,
                    'body_type' => gettype($body),
                    'body_empty' => empty($body),
                ]);
                return null;
            }

            // Log final parsed object values
            $parsedObject = (object) [
                'phone'       => $phone,
                'message'     => $body,
                'media'       => $mediaArray,
                'instanceId'  => $instanceId,
                'referenceId' => $referenceId,
                'messageId'   => $msgId,
            ];
            
            Log::info('parseIncomingMessage returning parsed object', [
                'phone' => $parsedObject->phone,
                'message' => $parsedObject->message,
                'message_type' => gettype($parsedObject->message),
                'message_empty' => empty($parsedObject->message),
                'message_length' => is_string($parsedObject->message) ? strlen($parsedObject->message) : 0,
                'media_count' => count($parsedObject->media),
                'instanceId' => $parsedObject->instanceId,
                'referenceId' => $parsedObject->referenceId,
                'messageId' => $parsedObject->messageId,
            ]);
            
            return $parsedObject;
        } catch (\Exception $e) {
            Log::error('Error parsing Ultramsg incoming message', [
                'error'   => $e->getMessage(),
                'payload' => $payload,
            ]);
            return null;
        }
    }

    /**
     * Parse status webhook from Ultramsg
     * We support ack (int) and ackName (string) in a tolerant way.
     */
    public function parseStatus(array $payload): ?object
    {
        try {
            $instanceId  = $payload['instanceId'] ?? null;
            $referenceId = $payload['referenceId'] ?? null;
            $data        = $payload['data'] ?? $payload;

            $messageId = $data['id'] ?? $payload['id'] ?? null;
            $ack       = $data['ack'] ?? null;
            $ackName   = $data['ackName'] ?? null;

            $status = null;

            // If ackName is provided (e.g. SERVER/DEVICE/READ/PLAYED-like semantics)
            if (is_string($ackName)) {
                $status = match (strtoupper($ackName)) {
                    'PENDING'  => 'pending',
                    'SERVER', 'SENT' => 'sent',
                    'DEVICE', 'DELIVERED' => 'delivered',
                    'READ'    => 'read',
                    'PLAYED'  => 'played',
                    default   => strtolower($ackName),
                };
            } elseif (is_numeric($ack)) {
                $ackInt = (int) $ack;
                $status = match ($ackInt) {
                    -1      => 'error',
                    0       => 'pending',
                    1       => 'sent',
                    2       => 'delivered',
                    3       => 'read',
                    4       => 'played',
                    default => (string) $ackInt,
                };
            } elseif (is_string($ack)) {
                $status = strtolower($ack);
            }

            if (!$messageId || $status === null) {
                return null;
            }

            return (object) [
                'messageId'   => $messageId,
                'status'      => $status,
                'ack'         => $ack,
                'instanceId'  => $instanceId,
                'referenceId' => $referenceId,
            ];
        } catch (\Exception $e) {
            Log::error('Error parsing Ultramsg status', [
                'error'   => $e->getMessage(),
                'payload' => $payload,
            ]);
            return null;
        }
    }

    /**
     * Get QR code for instance pairing
     */
    public function getQRCode(string $instanceId, string $apiToken): array
    {
        $url = "https://api.ultramsg.com/{$instanceId}/instance/qrCode?token=" . urlencode($apiToken);

        $response = Http::get($url);

        if (!$response->successful()) {
            Log::error('Ultramsg getQRCode failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \RuntimeException('Ultramsg getQRCode failed: ' . $response->body());
        }

        return $response->json();
    }
}
