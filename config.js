// Load environment variables from .env file
require('dotenv').config();

/**
 * Configuration file for managing credentials
 * 
 * In production, store these in environment variables or a secure database
 * This is a simple in-memory storage for demonstration
 */

// Store Ultramsg credentials by sub-account ID
// Format: { subAccountId: { instanceId: 'xxx', apiToken: 'xxx' } }
const ultramsgCredentials = {};

// Store GHL API keys by sub-account ID
// Format: { subAccountId: 'api_key_here' }
const ghlAPIKeys = {};

/**
 * Get Ultramsg credentials for a sub-account
 * @param {string} subAccountId - The sub-account identifier
 * @returns {Object|null} - { instanceId, apiToken } or null if not found
 */
function getUltramsgCredentials(subAccountId) {
  // If subAccountId is provided, look it up
  if (subAccountId && ultramsgCredentials[subAccountId]) {
    return ultramsgCredentials[subAccountId];
  }

  // Fallback to default credentials if no sub-account specified
  // or use environment variables
  const defaultInstanceId = process.env.ULTRAMSG_INSTANCE_ID;
  const defaultApiToken = process.env.ULTRAMSG_API_TOKEN;

  if (defaultInstanceId && defaultApiToken) {
    return {
      instanceId: defaultInstanceId,
      apiToken: defaultApiToken
    };
  }

  // Return null if no credentials found
  return null;
}

/**
 * Get GHL API key for a sub-account
 * @param {string} subAccountId - The sub-account identifier
 * @returns {string|null} - API key or null if not found
 */
function getGHLAPIKey(subAccountId) {
  // If subAccountId is provided, look it up
  if (subAccountId && ghlAPIKeys[subAccountId]) {
    return ghlAPIKeys[subAccountId];
  }

  // Fallback to default API key from environment variable
  const defaultAPIKey = process.env.GHL_API_KEY;

  if (defaultAPIKey) {
    return defaultAPIKey;
  }

  // Return null if no API key found
  return null;
}

/**
 * Set Ultramsg credentials for a sub-account
 * @param {string} subAccountId - The sub-account identifier
 * @param {string} instanceId - Ultramsg instance ID
 * @param {string} apiToken - Ultramsg API token
 */
function setUltramsgCredentials(subAccountId, instanceId, apiToken) {
  ultramsgCredentials[subAccountId] = { instanceId, apiToken };
}

/**
 * Set GHL API key for a sub-account
 * @param {string} subAccountId - The sub-account identifier
 * @param {string} apiKey - GHL API key
 */
function setGHLAPIKey(subAccountId, apiKey) {
  ghlAPIKeys[subAccountId] = apiKey;
}

/**
 * Initialize credentials from environment variables
 * You can also load from a database or config file
 */
function initializeCredentials() {
  // Example: Load from environment variables
  // You can extend this to load from a database or config file
  
  const defaultInstanceId = process.env.ULTRAMSG_INSTANCE_ID;
  const defaultApiToken = process.env.ULTRAMSG_API_TOKEN;
  const defaultGHLKey = process.env.GHL_API_KEY;

  if (defaultInstanceId && defaultApiToken) {
    console.log('✅ Default Ultramsg credentials loaded from environment');
  }

  if (defaultGHLKey) {
    console.log('✅ Default GHL API key loaded from environment');
  }

  // Example: Add credentials programmatically
  // setUltramsgCredentials('sub_account_123', 'instance_123', 'token_123');
  // setGHLAPIKey('sub_account_123', 'ghl_api_key_123');
}

// Initialize on module load
initializeCredentials();

module.exports = {
  getUltramsgCredentials,
  getGHLAPIKey,
  setUltramsgCredentials,
  setGHLAPIKey,
  initializeCredentials
};

