# Credentials Setup Guide

## Overview

You need to configure **two types of credentials**:

1. **Ultramsg Credentials** - For sending/receiving WhatsApp messages
2. **GoHighLevel (GHL) Credentials** - For creating contacts and messages in GHL

---

## Method 1: Ultramsg Credentials (via API Endpoint)

### ✅ Recommended: Use `/onboard` endpoint

This stores credentials in the database per sub-account.

**Endpoint:** `POST /onboard`

**Request Body:**
```json
{
  "subAccountId": "sub_account_001",
  "instanceId": "your_ultramsg_instance_id",
  "apiToken": "your_ultramsg_api_token"
}
```

**Example using PowerShell:**
```powershell
$body = @{
    subAccountId = "sub_account_001"
    instanceId = "abc123xyz"
    apiToken = "your_token_here"
} | ConvertTo-Json

Invoke-RestMethod -Uri "http://localhost:8000/onboard" -Method Post -Body $body -ContentType "application/json"
```

**Example using cURL:**
```bash
curl -X POST http://localhost:8000/onboard \
  -H "Content-Type: application/json" \
  -d '{
    "subAccountId": "sub_account_001",
    "instanceId": "abc123xyz",
    "apiToken": "your_token_here"
  }'
```

**Success Response:**
```json
{
  "success": true,
  "message": "Ultramsg credentials stored for sub-account",
  "subAccountId": "sub_account_001"
}
```

---

## Method 2: GHL Credentials (via Environment Variables)

### Add to `.env` file:

```env
# Default GHL API Key (used if sub-account specific key not found)
GHL_API_KEY=your_ghl_api_key_here

# Default GHL Location ID (used if sub-account specific location not found)
GHL_LOCATION_ID=your_location_id_here
```

### Optional: Per Sub-Account GHL Credentials

If you have multiple sub-accounts with different GHL accounts:

```env
# For sub_account_001
GHL_API_KEY_sub_account_001=api_key_for_sub_001
GHL_LOCATION_ID_sub_account_001=location_id_for_sub_001

# For sub_account_002
GHL_API_KEY_sub_account_002=api_key_for_sub_002
GHL_LOCATION_ID_sub_account_002=location_id_for_sub_002
```

---

## Method 3: Fallback - Environment Variables for Ultramsg

If you don't use sub-accounts, you can set default Ultramsg credentials in `.env`:

```env
ULTRAMSG_INSTANCE_ID=your_instance_id
ULTRAMSG_API_TOKEN=your_api_token
```

**Note:** This is only used if no sub-account is specified or if the sub-account doesn't have credentials in the database.

---

## Complete Setup Example

### Step 1: Set Ultramsg Credentials (via API)

```powershell
# Replace with your actual values
$body = @{
    subAccountId = "main_account"
    instanceId = "instance_12345"
    apiToken = "token_abc123xyz"
} | ConvertTo-Json

Invoke-RestMethod -Uri "http://localhost:8000/onboard" -Method Post -Body $body -ContentType "application/json"
```

### Step 2: Set GHL Credentials (via .env)

Edit your `.env` file:

```env
GHL_API_KEY=ghl_api_key_from_your_account
GHL_LOCATION_ID=location_id_from_your_account
```

### Step 3: Verify Setup

Test the health check:
```powershell
Invoke-RestMethod -Uri "http://localhost:8000/" -Method Get
```

---

## How It Works

1. **When sending a message:**
   - System looks for Ultramsg credentials using `subAccountId`
   - If found in database → uses those
   - If not found → falls back to `ULTRAMSG_INSTANCE_ID` and `ULTRAMSG_API_TOKEN` from `.env`

2. **When receiving a message:**
   - System extracts `instanceId` from webhook
   - Looks up `subAccountId` from instance mapping
   - Gets GHL API key using `subAccountId`
   - If sub-account specific key exists → uses that
   - Otherwise → uses `GHL_API_KEY` from `.env`

---

## Troubleshooting

### Issue: "Ultramsg credentials not configured"
**Solution:** Run the `/onboard` endpoint with your credentials

### Issue: "GHL API key not configured"
**Solution:** Add `GHL_API_KEY` to your `.env` file

### Issue: "Sub-account could not be resolved"
**Solution:** 
1. Make sure you've run `/onboard` to store the instanceId → subAccountId mapping
2. Or include `referenceId` in your webhook payload that contains the subAccountId

### Issue: "LocationId not configured"
**Solution:** Add `GHL_LOCATION_ID` to your `.env` file

---

## Security Notes

⚠️ **Important:**
- Never commit `.env` file to version control
- Store API tokens securely
- Use environment variables in production
- Consider using a secrets manager for production deployments

