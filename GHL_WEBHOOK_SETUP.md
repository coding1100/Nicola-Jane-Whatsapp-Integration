# GHL Webhook Setup Guide

## Overview

This document explains how to configure GoHighLevel (GHL) to send webhooks to the WhatsApp bridge when SMS steps are triggered in workflows.

## Webhook Endpoints

You have two options:

### Option 1: Direct `/send` Endpoint (Recommended)

**URL:** `https://your-subdomain.com/api/send`  
**Method:** `POST`  
**Authentication:** None (public endpoint)

This is the recommended approach. The `/send` endpoint now accepts `locationId` and automatically resolves the sub-account.

### Option 2: `/ghl/webhook` Endpoint (For Complex Payloads)

**URL:** `https://your-subdomain.com/api/ghl/webhook`  
**Method:** `POST`  
**Authentication:** None (public endpoint)

Use this if your GHL webhook sends payloads in non-standard formats. This endpoint parses various GHL webhook formats and forwards to `/send`.

## Configuration Steps

### Step 1: Configure Location ID Mapping

Before using the webhook, you need to map GHL location IDs to sub-account IDs in the configuration file.

Edit `config/whatsapp.php` and add your location mappings:

```php
'location_mappings' => [
    'your_location_id_1' => 'sub_account_001',
    'your_location_id_2' => 'sub_account_002',
    // Add more mappings as needed
],
```

### Step 2: Set Up Webhook in GHL Workflow

1. **Navigate to your GHL workflow**
   - Go to your GHL account
   - Open the workflow that contains SMS steps
   - Edit the workflow

2. **Replace SMS step with Webhook action**
   - Remove or replace the SMS step
   - Add a "Webhook" or "Custom Webhook" action
   - Configure the webhook URL: `https://your-subdomain.com/api/send`

3. **Configure Webhook Payload**
   
   The webhook should send the following data:
   - `phone` - Recipient's phone number (required)
   - `message` - Message content (required if no mediaUrl)
   - `locationId` - GHL location ID (required - used to resolve sub-account)
   - `mediaUrl` (optional) - Media attachment URL
   - `mediaType` (optional) - Type of media: image, document, audio, video

   **Example GHL Webhook Configuration (Recommended):**
   ```json
   {
     "phone": "{{contact.phone}}",
     "message": "Hello {{contact.firstName}}, this is a test message!",
     "locationId": "{{location.id}}"
   }
   ```

   **With Media:**
   ```json
   {
     "phone": "{{contact.phone}}",
     "message": "Check out this image!",
     "locationId": "{{location.id}}",
     "mediaUrl": "https://example.com/image.jpg",
     "mediaType": "image"
   }
   ```

   **Note:** The `/send` endpoint will automatically resolve the `subAccountId` from the `locationId` using the mapping configured in `config/whatsapp.php`.

### Step 3: Test the Webhook

1. **Trigger the workflow** in GHL
2. **Check the logs** in `storage/logs/laravel.log` to see:
   - Raw webhook payload received
   - Parsed data
   - Sub-account resolution
   - WhatsApp message sending status

## Using `/ghl/webhook` Endpoint (Alternative)

If your GHL webhook sends payloads in non-standard formats (nested structures, different field names), you can use the `/ghl/webhook` endpoint which handles various GHL webhook formats.

**URL:** `https://your-subdomain.com/api/ghl/webhook`

This endpoint will parse the payload and forward it to `/send`. It accepts various payload formats:

### Phone Number
- `payload['phone']`
- `payload['contact']['phone']`
- `payload['contact']['phoneNumber']`
- `payload['data']['phone']`
- `payload['data']['contact']['phone']`
- `payload['recipient']['phone']`

### Message Content
- `payload['message']`
- `payload['text']`
- `payload['body']`
- `payload['content']`
- `payload['data']['message']`
- `payload['data']['text']`
- `payload['data']['body']`
- `payload['data']['content']`
- `payload['sms']['message']`
- `payload['sms']['text']`
- `payload['sms']['body']`

### Location ID
- `payload['locationId']`
- `payload['location']['id']`
- `payload['data']['locationId']`
- `payload['location_id']`

### Media Attachments
- `payload['media']`
- `payload['attachments']`
- `payload['data']['media']`
- `payload['data']['attachments']`
- `payload['files']`
- `payload['data']['files']`

## Logging

All incoming webhooks are logged with comprehensive details:

1. **Raw Payload Logging**
   - Full payload structure
   - Headers
   - Raw body content
   - Timestamp

2. **Parsing Logs**
   - Extracted phone number
   - Extracted message
   - Location ID
   - Media attachments
   - Sub-account resolution

3. **Processing Logs**
   - WhatsApp sending status
   - Success/failure details
   - Error messages (if any)

**Log Location:** `storage/logs/laravel.log`

## Response Format

### Success Response
```json
{
  "success": true,
  "message": "GHL webhook processed and WhatsApp message sent",
  "data": {
    "phone": "+1234567890",
    "subAccountId": "sub_account_001",
    "locationId": "location_id_123",
    "whatsapp_response": {
      "success": true,
      "message": "WhatsApp message sent",
      "data": {...}
    }
  }
}
```

### Error Response
```json
{
  "success": false,
  "error": "Error message here",
  "subAccountId": "sub_account_001",
  "locationId": "location_id_123"
}
```

## Troubleshooting

### Issue: "Invalid GHL webhook payload - missing required fields"

**Solution:**
- Check the logs to see what payload structure GHL is sending
- Verify that `phone` and `message` fields are included in the webhook payload
- Update the webhook configuration in GHL to include required fields

### Issue: "No sub-account found for locationId"

**Solution:**
- Add the locationId to `config/whatsapp.php` in the `location_mappings` array
- Format: `'location_id' => 'sub_account_id'`

### Issue: "Ultramsg credentials not configured for this sub-account"

**Solution:**
- Ensure Ultramsg credentials are configured for the sub-account
- Use the `/onboard` endpoint to set credentials
- Or configure in `config/whatsapp.php`

### Issue: Webhook received but WhatsApp message not sent

**Solution:**
- Check `storage/logs/laravel.log` for detailed error messages
- Verify Ultramsg credentials are correct
- Ensure phone number is in correct format (E.164)
- Check if WhatsApp number is connected and active

## Testing

### Test Webhook with cURL

**Using `/send` endpoint (Recommended):**
```bash
curl -X POST https://your-subdomain.com/api/send \
  -H "Content-Type: application/json" \
  -d '{
    "phone": "+1234567890",
    "message": "Test message from GHL",
    "locationId": "your_location_id"
  }'
```

**Using `/ghl/webhook` endpoint (for non-standard payloads):**
```bash
curl -X POST https://your-subdomain.com/api/ghl/webhook \
  -H "Content-Type: application/json" \
  -d '{
    "phone": "+1234567890",
    "message": "Test message from GHL",
    "locationId": "your_location_id"
  }'
```

### Test Webhook with PowerShell

**Using `/send` endpoint (Recommended):**
```powershell
$body = @{
    phone = "+1234567890"
    message = "Test message from GHL"
    locationId = "your_location_id"
} | ConvertTo-Json

Invoke-RestMethod -Uri "https://your-subdomain.com/api/send" `
    -Method Post `
    -Body $body `
    -ContentType "application/json"
```

## Security Notes

⚠️ **Important:**
- The webhook endpoint is public (no authentication)
- Consider implementing IP whitelisting if possible
- Monitor logs for suspicious activity
- GHL does not provide webhook signatures for verification

## Next Steps

After the first webhook is received:
1. Check `storage/logs/laravel.log` to see the actual payload structure
2. If the payload structure differs from expected, the parser will handle common variations
3. If needed, update the parser in `app/Services/GHLService.php` to handle your specific format

## Support

For issues or questions:
1. Check the logs first: `storage/logs/laravel.log`
2. Verify configuration in `config/whatsapp.php`
3. Test the endpoint manually using cURL or Postman

