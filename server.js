// Load environment variables from .env file
require('dotenv').config();

const express = require('express');
const axios = require('axios');
const bodyParser = require('body-parser');
const cors = require('cors');
const { getUltramsgCredentials, getGHLAPIKey } = require('./config');

const app = express();
const PORT = process.env.PORT || 3000;

// Middleware
app.use(cors());
app.use(bodyParser.json());
app.use(bodyParser.urlencoded({ extended: true }));

// Health check endpoint
app.get('/', (req, res) => {
  res.json({ 
    status: 'ok', 
    message: 'WhatsApp Bridge API is running',
    endpoints: ['/send', '/incoming', '/status']
  });
});

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
app.post('/send', async (req, res) => {
  try {
    const { message, phone, subAccountId } = req.body;

    // Validate required fields
    if (!message || !phone) {
      return res.status(400).json({
        error: 'Missing required fields',
        required: ['message', 'phone']
      });
    }

    // Look up Ultramsg credentials based on sub-account ID
    const credentials = getUltramsgCredentials(subAccountId);
    if (!credentials) {
      return res.status(401).json({
        error: 'Ultramsg credentials not found for sub-account',
        message: 'Please configure ULTRAMSG_INSTANCE_ID and ULTRAMSG_API_TOKEN in .env file or config.js',
        subAccountId
      });
    }

    const { instanceId, apiToken } = credentials;

    // Send message via Ultramsg API
    // Ultramsg supports both query param token and Bearer token authentication
    // Using query param method as specified in requirements
    const ultramsgResponse = await axios.post(
      `https://api.ultramsg.com/${instanceId}/messages/chat`,
      {
        to: phone,
        body: message
      },
      {
        params: { token: apiToken },
        headers: {
          'Content-Type': 'application/json'
          // Alternative: 'Authorization': `Bearer ${apiToken}` if using Bearer token
        }
      }
    );

    console.log('Message sent successfully:', {
      phone,
      messageId: ultramsgResponse.data?.id,
      subAccountId
    });

    res.json({
      success: true,
      message: 'Message sent successfully',
      data: ultramsgResponse.data,
      phone,
      subAccountId
    });

  } catch (error) {
    console.error('Error sending message:', error.response?.data || error.message);
    res.status(error.response?.status || 500).json({
      error: 'Failed to send message',
      message: error.response?.data?.message || error.message,
      details: error.response?.data
    });
  }
});

/**
 * POST /incoming
 * Receives webhooks from Ultramsg and forwards to GHL
 * Expected payload from Ultramsg webhook format
 */
app.post('/incoming', async (req, res) => {
  try {
    const ultramsgData = req.body;

    // Extract message data from Ultramsg webhook format
    // Ultramsg webhook structure may vary, adjust based on actual format
    const messageData = extractMessageData(ultramsgData);
    
    if (!messageData) {
      return res.status(400).json({
        error: 'Invalid webhook data format'
      });
    }

    const { contactId, message, phone, subAccountId } = messageData;

    // Get GHL API key for the sub-account
    const ghlAPIKey = getGHLAPIKey(subAccountId);
    if (!ghlAPIKey) {
      return res.status(401).json({
        error: 'GHL API key not found for sub-account',
        message: 'Please configure GHL_API_KEY in .env file or config.js',
        subAccountId
      });
    }

    // Forward message to GHL API
    // Note: GHL API v1 is end-of-support but still functional
    // For new integrations, consider migrating to API v2 with OAuth 2.0
    // API v2 endpoint: https://services.leadconnectorhq.com/conversations/messages
    const ghlResponse = await axios.post(
      'https://rest.gohighlevel.com/v1/conversations/messages/',
      {
        type: 'whatsapp',
        contactId: contactId,
        message: message
      },
      {
        headers: {
          'Authorization': `Bearer ${ghlAPIKey}`,
          'Content-Type': 'application/json'
        }
        // Note: GHL API v2 requires OAuth 2.0 tokens instead of API keys
      }
    );

    console.log('Message forwarded to GHL:', {
      contactId,
      phone,
      subAccountId
    });

    // Acknowledge receipt to Ultramsg
    res.status(200).json({
      success: true,
      message: 'Message forwarded to GHL',
      data: ghlResponse.data
    });

  } catch (error) {
    console.error('Error forwarding message to GHL:', error.response?.data || error.message);
    
    // Provide more detailed error information
    const errorDetails = {
      error: 'Failed to forward message to GHL',
      message: error.response?.data?.message || error.message,
      statusCode: error.response?.status,
      ghlError: error.response?.data
    };
    
    // If 404, it's likely an invalid contactId
    if (error.response?.status === 404) {
      errorDetails.hint = 'Contact ID may not exist in GHL. Verify the contactId is correct.';
    }
    
    // Still acknowledge to Ultramsg to prevent retries
    res.status(200).json({
      success: false,
      ...errorDetails
    });
  }
});

/**
 * POST /status
 * Receives status updates from Ultramsg and updates GHL
 * Expected payload from Ultramsg status webhook
 */
app.post('/status', async (req, res) => {
  try {
    const statusData = req.body;

    // Extract status information from Ultramsg webhook
    const statusInfo = extractStatusData(statusData);
    
    if (!statusInfo) {
      return res.status(400).json({
        error: 'Invalid status data format'
      });
    }

    const { messageId, status, subAccountId } = statusInfo;

    // Get GHL API key for the sub-account
    const ghlAPIKey = getGHLAPIKey(subAccountId);
    if (!ghlAPIKey) {
      return res.status(401).json({
        error: 'GHL API key not found for sub-account',
        message: 'Please configure GHL_API_KEY in .env file or config.js',
        subAccountId
      });
    }

    // Update message status in GHL (if supported by GHL API)
    // Note: GHL API v1 may not support status updates via this endpoint
    // Status updates might need to be handled via webhooks or API v2
    // GHL API v2 endpoint: https://services.leadconnectorhq.com/conversations/messages/{messageId}
    try {
      const ghlResponse = await axios.put(
        `https://rest.gohighlevel.com/v1/conversations/messages/${messageId}`,
        {
          status: status // e.g., 'delivered', 'read', 'sent'
        },
        {
          headers: {
            'Authorization': `Bearer ${ghlAPIKey}`,
            'Content-Type': 'application/json'
          }
        }
      );

      console.log('Status updated in GHL:', {
        messageId,
        status,
        subAccountId
      });

      res.json({
        success: true,
        message: 'Status updated in GHL',
        data: ghlResponse.data
      });

    } catch (ghlError) {
      // If GHL doesn't support status updates, log but don't fail
      console.warn('GHL status update not supported or failed:', ghlError.response?.data || ghlError.message);
      res.json({
        success: true,
        message: 'Status received (GHL update may not be supported)',
        status
      });
    }

  } catch (error) {
    console.error('Error processing status update:', error.response?.data || error.message);
    res.status(500).json({
      error: 'Failed to process status update',
      message: error.message
    });
  }
});

/**
 * Helper function to extract message data from Ultramsg webhook
 * Adjust this based on actual Ultramsg webhook format
 */
function extractMessageData(ultramsgData) {
  try {
    // Example Ultramsg webhook structure (adjust based on actual format)
    // Common formats:
    // - ultramsgData.data?.body
    // - ultramsgData.message
    // - ultramsgData.text
    
    const message = ultramsgData.data?.body || 
                   ultramsgData.message || 
                   ultramsgData.text || 
                   ultramsgData.body;
    
    const phone = ultramsgData.data?.from || 
                 ultramsgData.from || 
                 ultramsgData.phone;
    
    // Extract sub-account ID from webhook (may be in headers or data)
    const subAccountId = ultramsgData.subAccountId || 
                        ultramsgData.data?.subAccountId ||
                        ultramsgData.instanceId; // fallback to instanceId
    
    // Contact ID mapping - you may need to look this up from phone number
    // For now, using phone as contactId (adjust based on your GHL setup)
    const contactId = ultramsgData.contactId || phone;

    if (!message || !phone) {
      return null;
    }

    return {
      message,
      phone,
      contactId,
      subAccountId
    };
  } catch (error) {
    console.error('Error extracting message data:', error);
    return null;
  }
}

/**
 * Helper function to extract status data from Ultramsg webhook
 * Adjust this based on actual Ultramsg status webhook format
 */
function extractStatusData(statusData) {
  try {
    // Example Ultramsg status webhook structure (adjust based on actual format)
    const messageId = statusData.data?.id || 
                     statusData.messageId || 
                     statusData.id;
    
    const status = statusData.data?.status || 
                  statusData.status || 
                  statusData.event; // e.g., 'delivered', 'read', 'sent'
    
    const subAccountId = statusData.subAccountId || 
                        statusData.data?.subAccountId ||
                        statusData.instanceId;

    if (!messageId || !status) {
      return null;
    }

    return {
      messageId,
      status,
      subAccountId
    };
  } catch (error) {
    console.error('Error extracting status data:', error);
    return null;
  }
}

// Error handling middleware
app.use((err, req, res, next) => {
  console.error('Unhandled error:', err);
  res.status(500).json({
    error: 'Internal server error',
    message: err.message
  });
});

// Start server
app.listen(PORT, () => {
  console.log(`🚀 WhatsApp Bridge API server running on port ${PORT}`);
  console.log(`📡 Endpoints available:`);
  console.log(`   POST /send - Send messages from GHL to WhatsApp`);
  console.log(`   POST /incoming - Receive messages from WhatsApp to GHL`);
  console.log(`   POST /status - Receive status updates from WhatsApp`);
  console.log(`\n📋 Environment Variables Status:`);
  console.log(`   ULTRAMSG_INSTANCE_ID: ${process.env.ULTRAMSG_INSTANCE_ID ? '✅ Set' : '❌ Not set'}`);
  console.log(`   ULTRAMSG_API_TOKEN: ${process.env.ULTRAMSG_API_TOKEN ? '✅ Set' : '❌ Not set'}`);
  console.log(`   GHL_API_KEY: ${process.env.GHL_API_KEY ? '✅ Set' : '❌ Not set'}`);
  if (!process.env.ULTRAMSG_INSTANCE_ID || !process.env.ULTRAMSG_API_TOKEN || !process.env.GHL_API_KEY) {
    console.log(`\n⚠️  Warning: Some environment variables are not set.`);
    console.log(`   Create a .env file in the project root with your credentials.`);
    console.log(`   See ENV_FILE_SETUP.md for details.`);
  }
});

module.exports = app;

