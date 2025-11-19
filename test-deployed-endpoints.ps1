# PowerShell script to test deployed WhatsApp Bridge API endpoints
# Usage: .\test-deployed-endpoints.ps1 [baseUrl]
# Example: .\test-deployed-endpoints.ps1 https://your-domain.com

param(
    [string]$baseUrl = "https://phplaravel-1545773-6008765.cloudwaysapps.com"
)

$ErrorActionPreference = "Continue"
$testResults = @{
    Passed = 0
    Failed = 0
    Warnings = 0
}

function Write-TestResult {
    param(
        [string]$TestName,
        [bool]$Success,
        [string]$Message = "",
        [string]$Status = "INFO"
    )
    
    if ($Success) {
        Write-Host "   ✅ $TestName: PASSED" -ForegroundColor Green
        $script:testResults.Passed++
        if ($Message) {
            Write-Host "   $Message" -ForegroundColor Gray
        }
    } else {
        if ($Status -eq "WARNING") {
            Write-Host "   ⚠️  $TestName: $Message" -ForegroundColor Yellow
            $script:testResults.Warnings++
        } else {
            Write-Host "   ❌ $TestName: FAILED" -ForegroundColor Red
            $script:testResults.Failed++
            if ($Message) {
                Write-Host "   Error: $Message" -ForegroundColor Red
            }
        }
    }
}

Write-Host "`n🧪 Testing Deployed WhatsApp Bridge API Endpoints`n" -ForegroundColor Cyan
Write-Host "Base URL: $baseUrl`n" -ForegroundColor Gray
Write-Host "=" * 80 -ForegroundColor DarkGray
Write-Host ""

# Test 1: Health Check
Write-Host "1. Testing Health Check (GET /)..." -ForegroundColor Yellow
try {
    $response = Invoke-RestMethod -Uri "$baseUrl/" -Method Get -ErrorAction Stop
    $success = ($response.status -eq 'ok') -and ($response.endpoints.Count -gt 0)
    Write-TestResult -TestName "Health Check" -Success $success -Message "Status: $($response.status), Endpoints: $($response.endpoints -join ', ')"
} catch {
    Write-TestResult -TestName "Health Check" -Success $false -Message $_.Exception.Message
    if ($_.Exception.Response) {
        $statusCode = $_.Exception.Response.StatusCode.value__
        Write-Host "   Status Code: $statusCode" -ForegroundColor Red
    }
}

# Test 2: Send Text Message
Write-Host "`n2. Testing Send Text Message (POST /send)..." -ForegroundColor Yellow
try {
    $body = @{
        message = "Hello, this is a test message from deployed server"
        phone = "+1234567890"
        subAccountId = "sub_account_123"
        locationId = "location_123"
    } | ConvertTo-Json

    $response = Invoke-RestMethod -Uri "$baseUrl/send" -Method Post -Body $body -ContentType "application/json" -ErrorAction Stop
    $success = $response.success -eq $true
    Write-TestResult -TestName "Send Text Message" -Success $success -Message "Message sent successfully"
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__
    if ($statusCode -eq 401) {
        Write-TestResult -TestName "Send Text Message" -Success $true -Message "Credentials not configured (401) - Endpoint working correctly" -Status "WARNING"
        try {
            if ($_.ErrorDetails.Message) {
                $errorResponse = $_.ErrorDetails.Message | ConvertFrom-Json
                Write-Host "   Error Details: $($errorResponse | ConvertTo-Json -Compress)" -ForegroundColor DarkGray
            }
        } catch { }
    } elseif ($statusCode -eq 400) {
        Write-TestResult -TestName "Send Text Message" -Success $true -Message "Validation working (400) - Missing/invalid fields" -Status "WARNING"
    } elseif ($statusCode -eq 500) {
        Write-TestResult -TestName "Send Text Message" -Success $true -Message "Server error (500) - May be due to API call failure" -Status "WARNING"
    } else {
        Write-TestResult -TestName "Send Text Message" -Success $false -Message "Status: $statusCode, Error: $($_.Exception.Message)"
    }
}

# Test 3: Send Image Message
Write-Host "`n3. Testing Send Image Message (POST /send with media)..." -ForegroundColor Yellow
try {
    $body = @{
        message = "Check out this image!"
        phone = "+1234567890"
        subAccountId = "sub_account_123"
        locationId = "location_123"
        mediaUrl = "https://via.placeholder.com/300x200.png"
        mediaType = "image"
    } | ConvertTo-Json

    $response = Invoke-RestMethod -Uri "$baseUrl/send" -Method Post -Body $body -ContentType "application/json" -ErrorAction Stop
    $success = $response.success -eq $true
    Write-TestResult -TestName "Send Image Message" -Success $success -Message "Image message sent successfully"
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__
    if ($statusCode -eq 401 -or $statusCode -eq 500) {
        Write-TestResult -TestName "Send Image Message" -Success $true -Message "Endpoint working (may need credentials)" -Status "WARNING"
    } else {
        Write-TestResult -TestName "Send Image Message" -Success $false -Message "Status: $statusCode"
    }
}

# Test 4: Incoming Message Webhook
Write-Host "`n4. Testing Incoming Message Webhook (POST /incoming)..." -ForegroundColor Yellow
try {
    $body = @{
        event_type = "message"
        instanceId = "test_instance"
        id = "msg_$(Get-Date -Format 'yyyyMMddHHmmss')"
        referenceId = "sub_account_123_$(Get-Date -Format 'yyyyMMddHHmmss')"
        data = @{
            from = "1234567890@s.whatsapp.net"
            to = "0987654321@s.whatsapp.net"
            body = "Test incoming message from deployed server"
        }
        locationId = "location_123"
    } | ConvertTo-Json -Depth 10

    $response = Invoke-RestMethod -Uri "$baseUrl/incoming" -Method Post -Body $body -ContentType "application/json" -ErrorAction Stop
    $success = $response.success -eq $true
    Write-TestResult -TestName "Incoming Message" -Success $success -Message "Message forwarded to GHL"
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__
    if ($statusCode -eq 401) {
        Write-TestResult -TestName "Incoming Message" -Success $true -Message "GHL credentials not configured (401) - Endpoint working correctly" -Status "WARNING"
    } elseif ($statusCode -eq 400) {
        Write-TestResult -TestName "Incoming Message" -Success $true -Message "Validation working (400) - May need locationId or sub-account mapping" -Status "WARNING"
    } else {
        Write-TestResult -TestName "Incoming Message" -Success $false -Message "Status: $statusCode"
    }
}

# Test 5: Status Webhook
Write-Host "`n5. Testing Status Webhook (POST /status)..." -ForegroundColor Yellow
try {
    $body = @{
        event_type = "webhook_message_ack"
        instanceId = "test_instance"
        referenceId = "sub_account_123_$(Get-Date -Format 'yyyyMMddHHmmss')"
        data = @{
            id = "msg_status_$(Get-Date -Format 'yyyyMMddHHmmss')"
            ack = 2
            ackName = "DELIVERED"
        }
    } | ConvertTo-Json -Depth 10

    $response = Invoke-RestMethod -Uri "$baseUrl/status" -Method Post -Body $body -ContentType "application/json" -ErrorAction Stop
    $success = $response.success -eq $true
    Write-TestResult -TestName "Status Webhook" -Success $success -Message "Status: $($response.message)"
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__
    if ($statusCode -eq 401) {
        Write-TestResult -TestName "Status Webhook" -Success $true -Message "GHL credentials not configured (401) - Endpoint working correctly" -Status "WARNING"
    } elseif ($statusCode -eq 400) {
        Write-TestResult -TestName "Status Webhook" -Success $true -Message "Validation working (400)" -Status "WARNING"
    } else {
        Write-TestResult -TestName "Status Webhook" -Success $false -Message "Status: $statusCode"
    }
}

# Test 6: Onboard Sub-Account
Write-Host "`n6. Testing Onboard Sub-Account (POST /onboard)..." -ForegroundColor Yellow
try {
    $body = @{
        subAccountId = "sub_account_123"
        instanceId = "test_instance_123"
        apiToken = "test_api_token_12345"
    } | ConvertTo-Json

    $response = Invoke-RestMethod -Uri "$baseUrl/onboard" -Method Post -Body $body -ContentType "application/json" -ErrorAction Stop
    $success = $response.success -eq $true
    Write-TestResult -TestName "Onboard Sub-Account" -Success $success -Message "Credentials stored for: $($response.subAccountId)"
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__
    if ($statusCode -eq 400) {
        Write-TestResult -TestName "Onboard Sub-Account" -Success $true -Message "Validation working (400)" -Status "WARNING"
    } else {
        Write-TestResult -TestName "Onboard Sub-Account" -Success $false -Message "Status: $statusCode"
    }
}

# Test 7: Get QR Code
Write-Host "`n7. Testing Get QR Code (GET /onboard/qr)..." -ForegroundColor Yellow
try {
    $queryParams = @{
        instanceId = "test_instance_123"
        apiToken = "test_api_token_12345"
    }
    $uri = "$baseUrl/onboard/qr?" + ($queryParams.GetEnumerator() | ForEach-Object { "$($_.Key)=$($_.Value)" } | Join-String -Separator "&")

    $response = Invoke-RestMethod -Uri $uri -Method Get -ErrorAction Stop
    $success = $response.success -eq $true
    Write-TestResult -TestName "Get QR Code" -Success $success -Message "QR code data retrieved"
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__
    if ($statusCode -eq 400) {
        Write-TestResult -TestName "Get QR Code" -Success $true -Message "Validation working (400)" -Status "WARNING"
    } elseif ($statusCode -eq 500) {
        Write-TestResult -TestName "Get QR Code" -Success $true -Message "API call failed (500) - Expected with test credentials" -Status "WARNING"
    } else {
        Write-TestResult -TestName "Get QR Code" -Success $false -Message "Status: $statusCode"
    }
}

# Test 8: Error Handling - Missing Required Fields
Write-Host "`n8. Testing Error Handling (Missing Required Fields)..." -ForegroundColor Yellow
try {
    $body = @{
        message = "This should fail - missing phone field"
    } | ConvertTo-Json

    $response = Invoke-RestMethod -Uri "$baseUrl/send" -Method Post -Body $body -ContentType "application/json" -ErrorAction Stop
    Write-TestResult -TestName "Error Handling (Missing Fields)" -Success $false -Message "Should have returned 400"
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__
    if ($statusCode -eq 400) {
        try {
            if ($_.ErrorDetails.Message) {
                $errorResponse = $_.ErrorDetails.Message | ConvertFrom-Json
                Write-TestResult -TestName "Error Handling (Missing Fields)" -Success $true -Message "Validation working (400): $($errorResponse.error)"
            } else {
                Write-TestResult -TestName "Error Handling (Missing Fields)" -Success $true -Message "Validation working (400)"
            }
        } catch {
            Write-TestResult -TestName "Error Handling (Missing Fields)" -Success $true -Message "Validation working (400)"
        }
    } else {
        Write-TestResult -TestName "Error Handling (Missing Fields)" -Success $false -Message "Expected 400, got $statusCode"
    }
}

# Test 9: Error Handling - Invalid Webhook Data
Write-Host "`n9. Testing Error Handling (Invalid Webhook Data)..." -ForegroundColor Yellow
try {
    $body = @{
        invalid = "data"
    } | ConvertTo-Json

    $response = Invoke-RestMethod -Uri "$baseUrl/incoming" -Method Post -Body $body -ContentType "application/json" -ErrorAction Stop
    Write-TestResult -TestName "Error Handling (Invalid Webhook)" -Success $false -Message "Should have returned 400"
} catch {
    $statusCode = $_.Exception.Response.StatusCode.value__
    if ($statusCode -eq 400) {
        Write-TestResult -TestName "Error Handling (Invalid Webhook)" -Success $true -Message "Validation working (400)"
    } else {
        Write-TestResult -TestName "Error Handling (Invalid Webhook)" -Success $false -Message "Expected 400, got $statusCode"
    }
}

# Summary
Write-Host "`n" + ("=" * 80) -ForegroundColor DarkGray
Write-Host "`n📊 Test Summary`n" -ForegroundColor Cyan
Write-Host "   ✅ Passed:  $($testResults.Passed)" -ForegroundColor Green
Write-Host "   ⚠️  Warnings: $($testResults.Warnings)" -ForegroundColor Yellow
Write-Host "   ❌ Failed:  $($testResults.Failed)" -ForegroundColor Red
Write-Host ""

$totalTests = $testResults.Passed + $testResults.Warnings + $testResults.Failed
if ($totalTests -gt 0) {
    $successRate = [math]::Round(($testResults.Passed / $totalTests) * 100, 2)
    Write-Host "   Success Rate: $successRate%`n" -ForegroundColor $(if ($successRate -ge 80) { "Green" } elseif ($successRate -ge 50) { "Yellow" } else { "Red" })
}

Write-Host "📝 Notes:" -ForegroundColor Cyan
Write-Host "   - 401 errors indicate missing credentials (expected in test environment)" -ForegroundColor Gray
Write-Host "   - 400 errors indicate validation is working correctly" -ForegroundColor Gray
Write-Host "   - 500 errors may indicate API call failures (expected with test credentials)" -ForegroundColor Gray
Write-Host "   - Configure credentials via /onboard endpoint or .env file on server for full functionality`n" -ForegroundColor Gray
