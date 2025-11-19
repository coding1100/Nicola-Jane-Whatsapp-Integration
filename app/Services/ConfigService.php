<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Configuration service for managing credentials and mappings
 * Uses database for persistent storage
 */
class ConfigService
{
    /**
     * Get Ultramsg credentials for a sub-account
     * @param string|null $subAccountId - The sub-account identifier
     * @return array|null - ['instanceId' => string, 'apiToken' => string] or null if not found
     */
    public static function getUltramsgCredentials(?string $subAccountId = null): ?array
    {
        if (!$subAccountId) {
            // Fallback to environment variables if no sub-account specified
            $defaultInstanceId = env('ULTRAMSG_INSTANCE_ID');
            $defaultApiToken = env('ULTRAMSG_API_TOKEN');

            if ($defaultInstanceId && $defaultApiToken) {
                return [
                    'instanceId' => $defaultInstanceId,
                    'apiToken' => $defaultApiToken
                ];
            }
            return null;
        }

        try {
            $credential = DB::table('whatsapp_credentials')
                ->where('sub_account_id', $subAccountId)
                ->first();

            if ($credential) {
                return [
                    'instanceId' => $credential->instance_id,
                    'apiToken' => $credential->api_token
                ];
            }
        } catch (\Exception $e) {
            Log::error('Error fetching Ultramsg credentials from database', [
                'error' => $e->getMessage(),
                'subAccountId' => $subAccountId,
            ]);
        }

        return null;
    }

    /**
     * Get GHL API key for a sub-account
     * @param string|null $subAccountId - The sub-account identifier
     * @return string|null - API key or null if not found
     */
    public static function getGHLAPIKey(?string $subAccountId = null): ?string
    {
        if (!$subAccountId) {
            // Fallback to environment variable if no sub-account specified
            return env('GHL_API_KEY');
        }

        try {
            // For now, GHL API keys can be stored per sub-account in a separate table
            // or use environment variable with sub-account prefix
            // This is a placeholder - you may want to create a ghl_credentials table
            $apiKey = env("GHL_API_KEY_{$subAccountId}");
            if ($apiKey) {
                return $apiKey;
            }

            // Fallback to default
            return env('GHL_API_KEY');
        } catch (\Exception $e) {
            Log::error('Error fetching GHL API key', [
                'error' => $e->getMessage(),
                'subAccountId' => $subAccountId,
            ]);
        }

        return null;
    }

    /**
     * Get GHL location ID for a sub-account
     * @param string|null $subAccountId - The sub-account identifier
     * @return string|null - Location ID or null if not found
     */
    public static function getGHLLocationId(?string $subAccountId = null): ?string
    {
        if (!$subAccountId) {
            return env('GHL_LOCATION_ID');
        }

        try {
            // Try sub-account specific location ID
            $locationId = env("GHL_LOCATION_ID_{$subAccountId}");
            if ($locationId) {
                return $locationId;
            }

            // Fallback to default
            return env('GHL_LOCATION_ID');
        } catch (\Exception $e) {
            Log::error('Error fetching GHL location ID', [
                'error' => $e->getMessage(),
                'subAccountId' => $subAccountId,
            ]);
        }

        return null;
    }

    /**
     * Set Ultramsg credentials for a sub-account
     * @param string $subAccountId - The sub-account identifier
     * @param string $instanceId - Ultramsg instance ID
     * @param string $apiToken - Ultramsg API token
     */
    public static function setUltramsgCredentials(string $subAccountId, string $instanceId, string $apiToken): void
    {
        try {
            DB::table('whatsapp_credentials')->updateOrInsert(
                ['sub_account_id' => $subAccountId],
                [
                    'instance_id' => $instanceId,
                    'api_token' => $apiToken,
                    'updated_at' => now(),
                ]
            );

            // Also update instance mapping
            self::setInstanceMapping($instanceId, $subAccountId);
        } catch (\Exception $e) {
            Log::error('Error setting Ultramsg credentials', [
                'error' => $e->getMessage(),
                'subAccountId' => $subAccountId,
            ]);
            throw $e;
        }
    }

    /**
     * Set GHL API key for a sub-account
     * @param string $subAccountId - The sub-account identifier
     * @param string $apiKey - GHL API key
     */
    public static function setGHLAPIKey(string $subAccountId, string $apiKey): void
    {
        // For now, store in environment or create a separate table
        // This is a placeholder - you may want to create a ghl_credentials table
        Log::info('GHL API key set for sub-account', ['subAccountId' => $subAccountId]);
    }

    /**
     * Get sub-account ID by instance ID
     * @param string $instanceId - Ultramsg instance ID
     * @return string|null - Sub-account ID or null if not found
     */
    public static function getSubAccountIdByInstance(string $instanceId): ?string
    {
        try {
            $mapping = DB::table('whatsapp_instance_mappings')
                ->where('instance_id', $instanceId)
                ->first();

            if ($mapping) {
                return $mapping->sub_account_id;
            }

            // Fallback: check credentials table
            $credential = DB::table('whatsapp_credentials')
                ->where('instance_id', $instanceId)
                ->first();

            if ($credential) {
                return $credential->sub_account_id;
            }
        } catch (\Exception $e) {
            Log::error('Error fetching sub-account ID by instance', [
                'error' => $e->getMessage(),
                'instanceId' => $instanceId,
            ]);
        }

        return null;
    }

    /**
     * Set instance to sub-account mapping
     * @param string $instanceId - Ultramsg instance ID
     * @param string $subAccountId - Sub-account ID
     */
    public static function setInstanceMapping(string $instanceId, string $subAccountId): void
    {
        try {
            DB::table('whatsapp_instance_mappings')->updateOrInsert(
                ['instance_id' => $instanceId],
                [
                    'sub_account_id' => $subAccountId,
                    'updated_at' => now(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('Error setting instance mapping', [
                'error' => $e->getMessage(),
                'instanceId' => $instanceId,
                'subAccountId' => $subAccountId,
            ]);
        }
    }

    /**
     * Store message ID mapping
     * @param string $ultramsgMessageId - Ultramsg message ID
     * @param string $ghlMessageId - GHL message ID
     * @param string $subAccountId - Sub-account ID
     */
    public static function storeMessageMapping(string $ultramsgMessageId, string $ghlMessageId, string $subAccountId): void
    {
        try {
            DB::table('whatsapp_message_mappings')->updateOrInsert(
                ['ultramsg_message_id' => $ultramsgMessageId],
                [
                    'ghl_message_id' => $ghlMessageId,
                    'sub_account_id' => $subAccountId,
                    'updated_at' => now(),
                ]
            );
        } catch (\Exception $e) {
            Log::error('Error storing message mapping', [
                'error' => $e->getMessage(),
                'ultramsgMessageId' => $ultramsgMessageId,
                'ghlMessageId' => $ghlMessageId,
            ]);
        }
    }

    /**
     * Get GHL message ID by Ultramsg message ID
     * @param string $ultramsgMessageId - Ultramsg message ID
     * @return string|null - GHL message ID or null if not found
     */
    public static function getGHLMessageId(string $ultramsgMessageId): ?string
    {
        try {
            $mapping = DB::table('whatsapp_message_mappings')
                ->where('ultramsg_message_id', $ultramsgMessageId)
                ->first();

            return $mapping ? $mapping->ghl_message_id : null;
        } catch (\Exception $e) {
            Log::error('Error fetching GHL message ID', [
                'error' => $e->getMessage(),
                'ultramsgMessageId' => $ultramsgMessageId,
            ]);
            return null;
        }
    }

    /**
     * Get Ultramsg message ID by GHL message ID
     * @param string $ghlMessageId - GHL message ID
     * @return string|null - Ultramsg message ID or null if not found
     */
    public static function getUltramsgMessageId(string $ghlMessageId): ?string
    {
        try {
            $mapping = DB::table('whatsapp_message_mappings')
                ->where('ghl_message_id', $ghlMessageId)
                ->first();

            return $mapping ? $mapping->ultramsg_message_id : null;
        } catch (\Exception $e) {
            Log::error('Error fetching Ultramsg message ID', [
                'error' => $e->getMessage(),
                'ghlMessageId' => $ghlMessageId,
            ]);
            return null;
        }
    }
}
