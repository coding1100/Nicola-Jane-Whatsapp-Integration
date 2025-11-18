<?php

namespace App\Services;

/**
 * Configuration service for managing credentials
 * 
 * In production, store these in environment variables or a secure database
 * This is a simple in-memory storage for demonstration
 */
class ConfigService
{
    // Store Ultramsg credentials by sub-account ID
    // Format: [subAccountId => ['instanceId' => 'xxx', 'apiToken' => 'xxx']]
    private static array $ultramsgCredentials = [];

    // Store GHL API keys by sub-account ID
    // Format: [subAccountId => 'api_key_here']
    private static array $ghlAPIKeys = [];

    /**
     * Get Ultramsg credentials for a sub-account
     * @param string|null $subAccountId - The sub-account identifier
     * @return array|null - ['instanceId' => string, 'apiToken' => string] or null if not found
     */
    public static function getUltramsgCredentials(?string $subAccountId = null): ?array
    {
        // If subAccountId is provided, look it up
        if ($subAccountId && isset(self::$ultramsgCredentials[$subAccountId])) {
            return self::$ultramsgCredentials[$subAccountId];
        }

        // Fallback to default credentials if no sub-account specified
        // or use environment variables
        $defaultInstanceId = env('ULTRAMSG_INSTANCE_ID');
        $defaultApiToken = env('ULTRAMSG_API_TOKEN');

        if ($defaultInstanceId && $defaultApiToken) {
            return [
                'instanceId' => $defaultInstanceId,
                'apiToken' => $defaultApiToken
            ];
        }

        // Return null if no credentials found
        return null;
    }

    /**
     * Get GHL API key for a sub-account
     * @param string|null $subAccountId - The sub-account identifier
     * @return string|null - API key or null if not found
     */
    public static function getGHLAPIKey(?string $subAccountId = null): ?string
    {
        // If subAccountId is provided, look it up
        if ($subAccountId && isset(self::$ghlAPIKeys[$subAccountId])) {
            return self::$ghlAPIKeys[$subAccountId];
        }

        // Fallback to default API key from environment variable
        $defaultAPIKey = env('GHL_API_KEY');

        if ($defaultAPIKey) {
            return $defaultAPIKey;
        }

        // Return null if no API key found
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
        self::$ultramsgCredentials[$subAccountId] = [
            'instanceId' => $instanceId,
            'apiToken' => $apiToken
        ];
    }

    /**
     * Set GHL API key for a sub-account
     * @param string $subAccountId - The sub-account identifier
     * @param string $apiKey - GHL API key
     */
    public static function setGHLAPIKey(string $subAccountId, string $apiKey): void
    {
        self::$ghlAPIKeys[$subAccountId] = $apiKey;
    }

    /**
     * Initialize credentials from environment variables
     * You can also load from a database or config file
     */
    public static function initializeCredentials(): void
    {
        $defaultInstanceId = env('ULTRAMSG_INSTANCE_ID');
        $defaultApiToken = env('ULTRAMSG_API_TOKEN');
        $defaultGHLKey = env('GHL_API_KEY');

        if ($defaultInstanceId && $defaultApiToken) {
            \Log::info('✅ Default Ultramsg credentials loaded from environment');
        }

        if ($defaultGHLKey) {
            \Log::info('✅ Default GHL API key loaded from environment');
        }
    }
}

