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
        // Only check config file - no database lookup
        $config = config('whatsapp.ultramsg', []);
        
        // If subAccountId is provided, check for sub-account specific credentials first
        if ($subAccountId) {
            $subAccountConfig = $config['sub_accounts'][$subAccountId] ?? null;
            if ($subAccountConfig && !empty($subAccountConfig['instance_id']) && !empty($subAccountConfig['api_token'])) {
                return [
                    'instanceId' => $subAccountConfig['instance_id'],
                    'apiToken' => $subAccountConfig['api_token']
                ];
            }
        }

        // Fallback to default credentials from config
        $defaultInstanceId = $config['instance_id'] ?? null;
        $defaultApiToken = $config['api_token'] ?? null;

        if ($defaultInstanceId && $defaultApiToken) {
            return [
                'instanceId' => $defaultInstanceId,
                'apiToken' => $defaultApiToken
            ];
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
        // Get config
        $config = config('whatsapp.ghl', []);

        if (!$subAccountId) {
            // Return default API key from config
            return $config['api_key'] ?? null;
        }

        try {
            // Check for sub-account specific API key in config
            $subAccountConfig = $config['sub_accounts'][$subAccountId] ?? null;
            if ($subAccountConfig && !empty($subAccountConfig['api_key'])) {
                return $subAccountConfig['api_key'];
            }

            // Fallback to default API key from config
            return $config['api_key'] ?? null;
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
        // Get config
        $config = config('whatsapp.ghl', []);

        if (!$subAccountId) {
            // Return default location ID from config
            return $config['location_id'] ?? null;
        }

        try {
            // Check for sub-account specific location ID in config
            $subAccountConfig = $config['sub_accounts'][$subAccountId] ?? null;
            if ($subAccountConfig && !empty($subAccountConfig['location_id'])) {
                return $subAccountConfig['location_id'];
            }

            // Fallback to default location ID from config
            return $config['location_id'] ?? null;
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
        // Only check config file - no database lookup
        $config = config('whatsapp.instance_mappings', []);
        
        Log::info('getSubAccountIdByInstance called', [
            'instanceId' => $instanceId,
            'instanceId_type' => gettype($instanceId),
            'config_keys' => array_keys($config),
            'config_full' => $config,
        ]);
        
        // Normalize instanceId to string (in case it comes as number)
        $instanceId = (string) $instanceId;
        
        // Direct lookup
        if (isset($config[$instanceId])) {
            Log::info('Found subAccountId via direct lookup', [
                'instanceId' => $instanceId,
                'subAccountId' => $config[$instanceId],
            ]);
            return $config[$instanceId];
        }
        
        // Try with "instance" prefix (in case config has "instance149866" but webhook sends "149866")
        $instanceIdWithPrefix = 'instance' . $instanceId;
        if (isset($config[$instanceIdWithPrefix])) {
            Log::info('Found subAccountId via prefix lookup', [
                'instanceId' => $instanceId,
                'looked_up' => $instanceIdWithPrefix,
                'subAccountId' => $config[$instanceIdWithPrefix],
            ]);
            return $config[$instanceIdWithPrefix];
        }
        
        // Try without "instance" prefix (in case webhook sends "instance149866" but config has "149866")
        if (str_starts_with($instanceId, 'instance')) {
            $instanceIdWithoutPrefix = str_replace('instance', '', $instanceId);
            if (isset($config[$instanceIdWithoutPrefix])) {
                Log::info('Found subAccountId via prefix removal', [
                    'instanceId' => $instanceId,
                    'looked_up' => $instanceIdWithoutPrefix,
                    'subAccountId' => $config[$instanceIdWithoutPrefix],
                ]);
                return $config[$instanceIdWithoutPrefix];
            }
        }
        
        // Check if this instanceId matches the default instance_id in config
        $ultramsgConfig = config('whatsapp.ultramsg', []);
        $defaultInstanceId = $ultramsgConfig['instance_id'] ?? null;
        
        Log::info('Checking default instance_id match', [
            'instanceId' => $instanceId,
            'defaultInstanceId' => $defaultInstanceId,
        ]);
        
        if ($defaultInstanceId) {
            // Normalize both for comparison
            $normalizedDefault = str_replace('instance', '', (string) $defaultInstanceId);
            $normalizedIncoming = str_replace('instance', '', $instanceId);
            
            if ($normalizedDefault === $normalizedIncoming) {
                Log::info('Found subAccountId via default instance_id match', [
                    'instanceId' => $instanceId,
                    'defaultInstanceId' => $defaultInstanceId,
                    'subAccountId' => 'default',
                ]);
                return 'default';
            }
        }
        
        Log::warning('No subAccountId found for instanceId', [
            'instanceId' => $instanceId,
            'config_keys' => array_keys($config),
            'defaultInstanceId' => $defaultInstanceId,
        ]);
        
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

    /**
     * Get sub-account ID by GHL location ID
     * @param string $locationId - GHL location ID
     * @return string|null - Sub-account ID or null if not found
     */
    public static function getSubAccountIdByLocationId(string $locationId): ?string
    {
        // Check config file for location mappings
        $config = config('whatsapp.location_mappings', []);
        
        Log::info('getSubAccountIdByLocationId called', [
            'locationId' => $locationId,
            'locationId_type' => gettype($locationId),
            'config_keys' => array_keys($config),
            'config_full' => $config,
        ]);
        
        // Normalize locationId to string
        $locationId = (string) $locationId;
        
        // Direct lookup
        if (isset($config[$locationId])) {
            Log::info('Found subAccountId via locationId mapping', [
                'locationId' => $locationId,
                'subAccountId' => $config[$locationId],
            ]);
            return $config[$locationId];
        }
        
        // Check if this locationId matches the default location_id in config
        $ghlConfig = config('whatsapp.ghl', []);
        $defaultLocationId = $ghlConfig['location_id'] ?? null;
        
        Log::info('Checking default location_id match', [
            'locationId' => $locationId,
            'defaultLocationId' => $defaultLocationId,
        ]);
        
        if ($defaultLocationId && (string) $defaultLocationId === $locationId) {
            Log::info('Found subAccountId via default location_id match', [
                'locationId' => $locationId,
                'defaultLocationId' => $defaultLocationId,
                'subAccountId' => 'default',
            ]);
            return 'default';
        }
        
        Log::warning('No subAccountId found for locationId', [
            'locationId' => $locationId,
            'config_keys' => array_keys($config),
            'defaultLocationId' => $defaultLocationId,
        ]);
        
        return null;
    }
}
