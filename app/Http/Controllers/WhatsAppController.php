<?php

namespace App\Http\Controllers;

use App\Services\ConfigService;
use App\Services\UltramsgService;
use App\Services\GHLService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    public function __construct(
        private UltramsgService $ultramsg,
        private GHLService $ghl,
    ) {}

    /**
     * GET / or /health
     */
    public function healthCheck(): JsonResponse
    {
        return response()->json([
            'status'    => 'ok',
            'message'   => 'WhatsApp Bridge API is running',
            'endpoints' => ['/send', '/incoming', '/status', '/onboard', '/onboard/qr'],
        ]);
    }

    /**
     * POST /send
     *
     * This is what GHL workflows call instead of SMS when you map SMS steps → HTTP webhook (Option A).
     */
    public function send(Request $request): JsonResponse
    {
        try {
            $message     = $request->input('message');
            $phone       = $request->input('phone');
            $subAccountId= $request->input('subAccountId');
            $locationId  = $request->input('locationId');
            $mediaUrl    = $request->input('mediaUrl');
            $mediaType   = $request->input('mediaType', 'image'); // image, document, audio, video

            if (!$phone || !$subAccountId) {
                return response()->json([
                    'error'    => 'Missing required fields',
                    'required' => ['phone', 'subAccountId'],
                ], 400);
            }

            if (!$message && !$mediaUrl) {
                return response()->json([
                    'error'    => 'At least one of message or mediaUrl is required',
                    'required' => ['message or mediaUrl', 'phone', 'subAccountId'],
                ], 400);
            }

            $creds = ConfigService::getUltramsgCredentials($subAccountId);
            if (!$creds) {
                return response()->json([
                    'error'       => 'Ultramsg credentials not configured for this sub-account',
                    'subAccountId'=> $subAccountId,
                ], 401);
            }

            $referenceId = $subAccountId . '_' . time();

            // send via Ultramsg
            if ($mediaUrl) {
                switch (strtolower($mediaType)) {
                    case 'image':
                        $waResponse = $this->ultramsg->sendImage(
                            $creds['instanceId'],
                            $creds['apiToken'],
                            $phone,
                            $mediaUrl,
                            $message,
                            $referenceId
                        );
                        break;

                    case 'document':
                        $waResponse = $this->ultramsg->sendDocument(
                            $creds['instanceId'],
                            $creds['apiToken'],
                            $phone,
                            $mediaUrl,
                            null,
                            $referenceId
                        );
                        break;

                    case 'audio':
                        $waResponse = $this->ultramsg->sendAudio(
                            $creds['instanceId'],
                            $creds['apiToken'],
                            $phone,
                            $mediaUrl,
                            $referenceId
                        );
                        break;

                    case 'video':
                        $waResponse = $this->ultramsg->sendVideo(
                            $creds['instanceId'],
                            $creds['apiToken'],
                            $phone,
                            $mediaUrl,
                            $message,
                            $referenceId
                        );
                        break;

                    default:
                        return response()->json([
                            'error'   => 'Invalid mediaType',
                            'allowed' => ['image', 'document', 'audio', 'video'],
                        ], 400);
                }
            } else {
                $waResponse = $this->ultramsg->sendText(
                    $creds['instanceId'],
                    $creds['apiToken'],
                    $phone,
                    $message,
                    $referenceId
                );
            }

            $ultramsgMessageId = $waResponse['id'] ?? $waResponse['messageId'] ?? null;

            if ($ultramsgMessageId && $locationId) {
                try {
                    $ghlAPIKey = ConfigService::getGHLAPIKey($subAccountId);
                    if ($ghlAPIKey) {
                        $contactId  = $this->ghl->findOrCreateContactByPhone($ghlAPIKey, $phone, $locationId);
                        $ghlMessage = $this->ghl->createConversationMessage(
                            $ghlAPIKey,
                            $contactId,
                            $message,
                            $mediaUrl ? [['url' => $mediaUrl, 'type' => $mediaType]] : null,
                            'whatsapp',
                            $locationId
                        );
                        $ghlMessageId = $ghlMessage['message']['id'] ?? $ghlMessage['id'] ?? null;
                        if ($ghlMessageId && $ultramsgMessageId) {
                            ConfigService::storeMessageMapping($ultramsgMessageId, $ghlMessageId, $subAccountId);
                        }
                    }
                } catch (\Exception $e) {
                    Log::warning('Failed to create GHL message for mapping', [
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            Log::info('WhatsApp message sent', [
                'phone'            => $phone,
                'subAccountId'     => $subAccountId,
                'ultramsgMessageId'=> $ultramsgMessageId,
            ]);

            return response()->json([
                'success'      => true,
                'message'      => 'WhatsApp message sent',
                'data'         => $waResponse,
                'subAccountId' => $subAccountId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error sending WhatsApp message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Failed to send WhatsApp message',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /incoming
     * Ultramsg webhook → GHL conversation message + keyword routing (Option 1).
     */
    public function incoming(Request $request): JsonResponse
    {
        // STEP 1: Log raw webhook payload immediately
        $payload = $request->all();
        Log::info('Incoming WA Webhook Raw:', [
            'payload' => $payload,
            'headers' => $request->headers->all(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
        ]);

        try {
            $incoming = $this->ultramsg->parseIncomingMessage($payload);

            if (!$incoming) {
                Log::error('parseIncomingMessage returned null', [
                    'payload' => $payload,
                    'payload_keys' => array_keys($payload),
                ]);
                
                return response()->json([
                    'error'   => 'Invalid Ultramsg webhook payload',
                    'payload' => $payload,
                ], 400);
            }

            Log::info('Parsed incoming message successfully', [
                'phone' => $incoming->phone,
                'message' => $incoming->message,
                'instanceId' => $incoming->instanceId,
                'referenceId' => $incoming->referenceId,
            ]);

            $phone       = $incoming->phone;
            $text        = $incoming->message;
            $media       = $incoming->media;
            $instanceId  = $incoming->instanceId;
            $referenceId = $incoming->referenceId;

            $subAccountId = $referenceId ? preg_replace('/_\d+$/', '', $referenceId) : null;
            Log::info('Sub-account resolution attempt', [
                'referenceId' => $referenceId,
                'extracted_subAccountId' => $subAccountId,
                'instanceId' => $instanceId,
            ]);

            if (!$subAccountId) {
                $subAccountId = ConfigService::getSubAccountIdByInstance($instanceId);
                Log::info('Looking up subAccountId by instanceId', [
                    'instanceId' => $instanceId,
                    'found_subAccountId' => $subAccountId,
                ]);
            }

            if (!$subAccountId) {
                Log::error('Missing subAccountId for incoming WhatsApp message', [
                    'phone'       => $phone,
                    'instanceId'  => $instanceId,
                    'referenceId' => $referenceId,
                    'payload_keys' => array_keys($payload),
                ]);

                return response()->json([
                    'success' => false,
                    'error'   => 'Sub-account could not be resolved',
                    'instanceId' => $instanceId,
                    'referenceId' => $referenceId,
                ], 400);
            }

            Log::info('Sub-account resolved', ['subAccountId' => $subAccountId]);

            $ghlAPIKey = ConfigService::getGHLAPIKey($subAccountId);
            if (!$ghlAPIKey) {
                Log::error('GHL API key not configured', [
                    'subAccountId' => $subAccountId,
                ]);
                
                return response()->json([
                    'error'       => 'GHL API key not configured for this sub-account',
                    'subAccountId'=> $subAccountId,
                ], 401);
            }

            Log::info('GHL API key found', ['subAccountId' => $subAccountId]);

            $locationId = $request->input('locationId')
                ?? ConfigService::getGHLLocationId($subAccountId)
                ?? env('GHL_LOCATION_ID');

            if (!$locationId) {
                Log::error('LocationId not configured for incoming message', [
                    'subAccountId' => $subAccountId,
                ]);
                return response()->json([
                    'error'       => 'GHL locationId not configured for this sub-account',
                    'subAccountId'=> $subAccountId,
                ], 400);
            }

            // Find or create contact
            Log::info('Finding/creating contact', [
                'phone' => $phone,
                'locationId' => $locationId,
            ]);
            
            $contactId = $this->ghl->findOrCreateContactByPhone($ghlAPIKey, $phone, $locationId);
            
            Log::info('Contact found/created', [
                'contactId' => $contactId,
                'phone' => $phone,
            ]);

            // Keyword handling (Option 1)
            $normalized = strtoupper(trim((string) $text));

            if (in_array($normalized, ['STOP', 'UNSUBSCRIBE'], true)) {
                Log::info('Processing STOP keyword', ['contactId' => $contactId]);
                // Tag contact as unsubscribed from WhatsApp
                $this->ghl->addTags($ghlAPIKey, $contactId, ['whatsapp_unsubscribed']);
            } elseif (in_array($normalized, ['START', 'UNSTOP'], true)) {
                Log::info('Processing START keyword', ['contactId' => $contactId]);
                // Remove unsubscribed tag
                $this->ghl->removeTags($ghlAPIKey, $contactId, ['whatsapp_unsubscribed'], $locationId);
            }

            // Always log the inbound message in conversations
            Log::info('Creating GHL conversation message', [
                'contactId' => $contactId,
                'message' => $text,
                'hasMedia' => !empty($media),
            ]);
            
            $ghlMessageResponse = $this->ghl->createConversationMessage(
                $ghlAPIKey,
                $contactId,
                $text,
                $media,
                'WhatsApp',
                $locationId
            );
            
            Log::info('GHL conversation message created', [
                'ghlResponse' => $ghlMessageResponse,
            ]);

            $ultramsgMessageId = $incoming->messageId;
            $ghlMessageId      = $ghlMessageResponse['message']['id'] ?? $ghlMessageResponse['id'] ?? null;

            if ($ultramsgMessageId && $ghlMessageId) {
                ConfigService::storeMessageMapping($ultramsgMessageId, $ghlMessageId, $subAccountId);
            }

            Log::info('Incoming WhatsApp forwarded to GHL', [
                'phone'        => $phone,
                'contactId'    => $contactId,
                'subAccountId' => $subAccountId,
            ]);

            return response()->json([
                'success'   => true,
                'message'   => 'Message forwarded to GHL',
                'ghlResult' => $ghlMessageResponse,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error handling incoming WhatsApp message', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'payload' => $payload ?? null,
            ]);

            // Return 200 to prevent Ultramsg retries, but log the error clearly
            return response()->json([
                'success' => false,
                'error'   => 'Failed to forward message to GHL',
                'message' => $e->getMessage(),
                'logged' => true,
            ], 200);
        }
    }

    /**
     * POST /status
     * Ultramsg delivery/read status webhook → mirror into GHL where possible.
     */
    public function status(Request $request): JsonResponse
    {
        try {
            $payload = $request->all();
            $status  = $this->ultramsg->parseStatus($payload);

            if (!$status) {
                return response()->json([
                    'error'   => 'Invalid Ultramsg status payload',
                    'payload' => $payload,
                ], 400);
            }

            $instanceId  = $status->instanceId;
            $referenceId = $status->referenceId;

            $subAccountId = $referenceId ? preg_replace('/_\d+$/', '', $referenceId) : null;
            if (!$subAccountId) {
                $subAccountId = ConfigService::getSubAccountIdByInstance($instanceId);
            }

            if (!$subAccountId) {
                Log::warning('Status received but sub-account could not be resolved', [
                    'payload' => $payload,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Status received but sub-account unknown (logged only).',
                ]);
            }

            $ghlAPIKey = ConfigService::getGHLAPIKey($subAccountId);
            if (!$ghlAPIKey) {
                return response()->json([
                    'error'       => 'GHL API key not configured for this sub-account',
                    'subAccountId'=> $subAccountId,
                ], 401);
            }

            $ultramsgMessageId = $status->messageId;
            $ghlMessageId      = ConfigService::getGHLMessageId($ultramsgMessageId);

            if (!$ghlMessageId) {
                Log::warning('GHL message ID not found for Ultramsg message', [
                    'ultramsgMessageId' => $ultramsgMessageId,
                    'subAccountId'      => $subAccountId,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Status received but GHL message ID not found (logged only).',
                ]);
            }

            $updated = $this->ghl->updateMessageStatus(
                $ghlAPIKey,
                $ghlMessageId,
                $status->status
            );

            if ($updated) {
                Log::info('Message status updated in GHL', [
                    'ghlMessageId' => $ghlMessageId,
                    'status'       => $status->status,
                    'subAccountId' => $subAccountId,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Status updated in GHL',
                ]);
            }

            Log::warning('GHL did not accept status update, but status stored in logs only', [
                'status' => $status,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Status received (GHL update not supported or failed)',
            ]);
        } catch (\Throwable $e) {
            Log::error('Error processing WhatsApp status', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Failed to process status update',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /onboard
     */
    public function onboard(Request $request): JsonResponse
    {
        try {
            $subAccountId = $request->input('subAccountId');
            $instanceId   = $request->input('instanceId');
            $apiToken     = $request->input('apiToken');

            if (!$subAccountId || !$instanceId || !$apiToken) {
                return response()->json([
                    'error'    => 'Missing required fields',
                    'required' => ['subAccountId', 'instanceId', 'apiToken'],
                ], 400);
            }

            ConfigService::setUltramsgCredentials($subAccountId, $instanceId, $apiToken);

            Log::info('Ultramsg credentials stored for sub-account', [
                'subAccountId' => $subAccountId,
                'instanceId'   => $instanceId,
            ]);

            return response()->json([
                'success'      => true,
                'message'      => 'Ultramsg credentials stored for sub-account',
                'subAccountId' => $subAccountId,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error onboarding sub-account', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Failed to onboard sub-account',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /onboard/qr
     */
    public function getQRCode(Request $request): JsonResponse
    {
        try {
            $instanceId = $request->input('instanceId');
            $apiToken   = $request->input('apiToken');

            if (!$instanceId || !$apiToken) {
                return response()->json([
                    'error'    => 'Missing required fields',
                    'required' => ['instanceId', 'apiToken'],
                ], 400);
            }

            $qrData = $this->ultramsg->getQRCode($instanceId, $apiToken);

            return response()->json([
                'success' => true,
                'data'    => $qrData,
            ]);
        } catch (\Throwable $e) {
            Log::error('Error getting QR code', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error'   => 'Failed to get QR code',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
