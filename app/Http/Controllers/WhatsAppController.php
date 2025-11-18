<?php

namespace App\Http\Controllers;

use App\Services\ConfigService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    /**
     * Health check endpoint
     */
    public function healthCheck(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'message' => 'WhatsApp Bridge API is running',
            'endpoints' => ['/send', '/incoming', '/status']
        ]);
    }

    /**
     * POST /send
     * Receives messages from GHL and sends via Ultramsg
     * Expected payload from GHL:
     * {
     *   "message": "Hello, this is a test message",
     *   "phone": "+1234567890",
     *   "subAccountId": "sub_account_123"
     * }
     */
    public function send(Request $request): JsonResponse
    {
        try {
            $message = $request->input('message');
            $phone = $request->input('phone');
            $subAccountId = $request->input('subAccountId');

            // Validate required fields
            if (!$message || !$phone) {
                return response()->json([
                    'error' => 'Missing required fields',
                    'required' => ['message', 'phone']
                ], 400);
            }

            // Look up Ultramsg credentials based on sub-account ID
            $credentials = ConfigService::getUltramsgCredentials($subAccountId);
            if (!$credentials) {
                return response()->json([
                    'error' => 'Ultramsg credentials not found for sub-account',
                    'message' => 'Please configure ULTRAMSG_INSTANCE_ID and ULTRAMSG_API_TOKEN in .env file or config.js',
                    'subAccountId' => $subAccountId
                ], 401);
            }

            $instanceId = $credentials['instanceId'];
            $apiToken = $credentials['apiToken'];

            // Send message via Ultramsg API
            // Ultramsg supports both query param token and Bearer token authentication
            // Using query param method as specified in requirements
            $url = "https://api.ultramsg.com/{$instanceId}/messages/chat?token=" . urlencode($apiToken);
            $ultramsgResponse = Http::withHeaders([
                'Content-Type' => 'application/json'
            ])->post($url, [
                'to' => $phone,
                'body' => $message
            ]);

            if ($ultramsgResponse->successful()) {
                $responseData = $ultramsgResponse->json();

                Log::info('Message sent successfully:', [
                    'phone' => $phone,
                    'messageId' => $responseData['id'] ?? null,
                    'subAccountId' => $subAccountId
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Message sent successfully',
                    'data' => $responseData,
                    'phone' => $phone,
                    'subAccountId' => $subAccountId
                ]);
            } else {
                $errorData = $ultramsgResponse->json();
                $statusCode = $ultramsgResponse->status();

                Log::error('Error sending message:', [
                    'status' => $statusCode,
                    'error' => $errorData
                ]);

                return response()->json([
                    'error' => 'Failed to send message',
                    'message' => $errorData['message'] ?? 'Unknown error',
                    'details' => $errorData
                ], $statusCode);
            }

        } catch (\Exception $error) {
            Log::error('Error sending message:', [
                'message' => $error->getMessage(),
                'trace' => $error->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to send message',
                'message' => $error->getMessage()
            ], 500);
        }
    }

    /**
     * POST /incoming
     * Receives webhooks from Ultramsg and forwards to GHL
     * Expected payload from Ultramsg webhook format
     */
    public function incoming(Request $request): JsonResponse
    {
        try {
            $ultramsgData = $request->all();

            // Extract message data from Ultramsg webhook format
            $messageData = $this->extractMessageData($ultramsgData);

            if (!$messageData) {
                return response()->json([
                    'error' => 'Invalid webhook data format'
                ], 400);
            }

            $contactId = $messageData['contactId'];
            $message = $messageData['message'];
            $phone = $messageData['phone'];
            $subAccountId = $messageData['subAccountId'];

            // Get GHL API key for the sub-account
            $ghlAPIKey = ConfigService::getGHLAPIKey($subAccountId);
            if (!$ghlAPIKey) {
                return response()->json([
                    'error' => 'GHL API key not found for sub-account',
                    'message' => 'Please configure GHL_API_KEY in .env file or config.js',
                    'subAccountId' => $subAccountId
                ], 401);
            }

            // Forward message to GHL API
            // Note: GHL API v1 is end-of-support but still functional
            // For new integrations, consider migrating to API v2 with OAuth 2.0
            // API v2 endpoint: https://services.leadconnectorhq.com/conversations/messages
            $ghlResponse = Http::withHeaders([
                'Authorization' => "Bearer {$ghlAPIKey}",
                'Content-Type' => 'application/json'
            ])->post('https://rest.gohighlevel.com/v1/conversations/messages/', [
                'type' => 'whatsapp',
                'contactId' => $contactId,
                'message' => $message
            ]);

            if ($ghlResponse->successful()) {
                $responseData = $ghlResponse->json();

                Log::info('Message forwarded to GHL:', [
                    'contactId' => $contactId,
                    'phone' => $phone,
                    'subAccountId' => $subAccountId
                ]);

                // Acknowledge receipt to Ultramsg
                return response()->json([
                    'success' => true,
                    'message' => 'Message forwarded to GHL',
                    'data' => $responseData
                ]);
            } else {
                $errorData = $ghlResponse->json();
                $statusCode = $ghlResponse->status();

                Log::error('Error forwarding message to GHL:', [
                    'status' => $statusCode,
                    'error' => $errorData
                ]);

                // Provide more detailed error information
                $errorDetails = [
                    'error' => 'Failed to forward message to GHL',
                    'message' => $errorData['message'] ?? 'Unknown error',
                    'statusCode' => $statusCode,
                    'ghlError' => $errorData
                ];

                // If 404, it's likely an invalid contactId
                if ($statusCode === 404) {
                    $errorDetails['hint'] = 'Contact ID may not exist in GHL. Verify the contactId is correct.';
                }

                // Still acknowledge to Ultramsg to prevent retries
                return response()->json([
                    'success' => false,
                    ...$errorDetails
                ]);
            }

        } catch (\Exception $error) {
            Log::error('Error forwarding message to GHL:', [
                'message' => $error->getMessage(),
                'trace' => $error->getTraceAsString()
            ]);

            // Still acknowledge to Ultramsg to prevent retries
            return response()->json([
                'success' => false,
                'error' => 'Failed to forward message to GHL',
                'message' => $error->getMessage()
            ]);
        }
    }

    /**
     * POST /status
     * Receives status updates from Ultramsg and updates GHL
     * Expected payload from Ultramsg status webhook
     */
    public function status(Request $request): JsonResponse
    {
        try {
            $statusData = $request->all();

            // Extract status information from Ultramsg webhook
            $statusInfo = $this->extractStatusData($statusData);

            if (!$statusInfo) {
                return response()->json([
                    'error' => 'Invalid status data format'
                ], 400);
            }

            $messageId = $statusInfo['messageId'];
            $status = $statusInfo['status'];
            $subAccountId = $statusInfo['subAccountId'];

            // Get GHL API key for the sub-account
            $ghlAPIKey = ConfigService::getGHLAPIKey($subAccountId);
            if (!$ghlAPIKey) {
                return response()->json([
                    'error' => 'GHL API key not found for sub-account',
                    'message' => 'Please configure GHL_API_KEY in .env file or config.js',
                    'subAccountId' => $subAccountId
                ], 401);
            }

            // Update message status in GHL (if supported by GHL API)
            // Note: GHL API v1 may not support status updates via this endpoint
            // Status updates might need to be handled via webhooks or API v2
            // GHL API v2 endpoint: https://services.leadconnectorhq.com/conversations/messages/{messageId}
            try {
                $ghlResponse = Http::withHeaders([
                    'Authorization' => "Bearer {$ghlAPIKey}",
                    'Content-Type' => 'application/json'
                ])->put("https://rest.gohighlevel.com/v1/conversations/messages/{$messageId}", [
                    'status' => $status // e.g., 'delivered', 'read', 'sent'
                ]);

                if ($ghlResponse->successful()) {
                    $responseData = $ghlResponse->json();

                    Log::info('Status updated in GHL:', [
                        'messageId' => $messageId,
                        'status' => $status,
                        'subAccountId' => $subAccountId
                    ]);

                    return response()->json([
                        'success' => true,
                        'message' => 'Status updated in GHL',
                        'data' => $responseData
                    ]);
                } else {
                    throw new \Exception('GHL API returned error: ' . $ghlResponse->status());
                }

            } catch (\Exception $ghlError) {
                // If GHL doesn't support status updates, log but don't fail
                Log::warning('GHL status update not supported or failed:', [
                    'message' => $ghlError->getMessage()
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Status received (GHL update may not be supported)',
                    'status' => $status
                ]);
            }

        } catch (\Exception $error) {
            Log::error('Error processing status update:', [
                'message' => $error->getMessage(),
                'trace' => $error->getTraceAsString()
            ]);

            return response()->json([
                'error' => 'Failed to process status update',
                'message' => $error->getMessage()
            ], 500);
        }
    }

    /**
     * Helper function to extract message data from Ultramsg webhook
     * Adjust this based on actual Ultramsg webhook format
     */
    private function extractMessageData(array $ultramsgData): ?array
    {
        try {
            // Example Ultramsg webhook structure (adjust based on actual format)
            // Common formats:
            // - ultramsgData['data']['body']
            // - ultramsgData['message']
            // - ultramsgData['text']

            $message = $ultramsgData['data']['body'] ??
                      ($ultramsgData['message'] ??
                      ($ultramsgData['text'] ??
                      ($ultramsgData['body'] ?? null)));

            $phone = $ultramsgData['data']['from'] ??
                    ($ultramsgData['from'] ??
                    ($ultramsgData['phone'] ?? null));

            // Extract sub-account ID from webhook (may be in headers or data)
            $subAccountId = $ultramsgData['subAccountId'] ??
                          ($ultramsgData['data']['subAccountId'] ??
                          ($ultramsgData['instanceId'] ?? null)); // fallback to instanceId

            // Contact ID mapping - you may need to look this up from phone number
            // For now, using phone as contactId (adjust based on your GHL setup)
            $contactId = $ultramsgData['contactId'] ?? $phone;

            if (!$message || !$phone) {
                return null;
            }

            return [
                'message' => $message,
                'phone' => $phone,
                'contactId' => $contactId,
                'subAccountId' => $subAccountId
            ];
        } catch (\Exception $error) {
            Log::error('Error extracting message data:', [
                'message' => $error->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Helper function to extract status data from Ultramsg webhook
     * Adjust this based on actual Ultramsg status webhook format
     */
    private function extractStatusData(array $statusData): ?array
    {
        try {
            // Example Ultramsg status webhook structure (adjust based on actual format)
            $messageId = $statusData['data']['id'] ??
                        ($statusData['messageId'] ??
                        ($statusData['id'] ?? null));

            $status = $statusData['data']['status'] ??
                     ($statusData['status'] ??
                     ($statusData['event'] ?? null)); // e.g., 'delivered', 'read', 'sent'

            $subAccountId = $statusData['subAccountId'] ??
                           ($statusData['data']['subAccountId'] ??
                           ($statusData['instanceId'] ?? null));

            if (!$messageId || !$status) {
                return null;
            }

            return [
                'messageId' => $messageId,
                'status' => $status,
                'subAccountId' => $subAccountId
            ];
        } catch (\Exception $error) {
            Log::error('Error extracting status data:', [
                'message' => $error->getMessage()
            ]);
            return null;
        }
    }
}
