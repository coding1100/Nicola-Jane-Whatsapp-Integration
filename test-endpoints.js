/**
 * Simple Node.js script to test endpoints locally
 * Run with: node test-endpoints.js
 * 
 * Make sure your server is running first: npm start
 */

const axios = require('axios');

const BASE_URL = process.env.TEST_BASE_URL || 'http://localhost:3000';
const TEST_PHONE = process.env.TEST_PHONE || '+1234567890';
const SUB_ACCOUNT_ID = process.env.TEST_SUB_ACCOUNT_ID || 'sub_account_123';

// Colors for console output
const colors = {
  reset: '\x1b[0m',
  green: '\x1b[32m',
  red: '\x1b[31m',
  yellow: '\x1b[33m',
  blue: '\x1b[34m',
};

function log(message, color = 'reset') {
  console.log(`${colors[color]}${message}${colors.reset}`);
}

function getDetailedError(error) {
  if (error.code === 'ECONNREFUSED') {
    return 'Connection refused - Server is not running. Start it with: npm start';
  } else if (error.code === 'ENOTFOUND') {
    return `Host not found - Check your base URL: ${BASE_URL}`;
  } else if (error.code === 'ETIMEDOUT') {
    return 'Connection timeout - Server may be slow or unreachable';
  } else if (error.response) {
    return `HTTP ${error.response.status}: ${JSON.stringify(error.response.data)}`;
  } else {
    return error.message || 'Unknown error';
  }
}

async function testHealthCheck() {
  log('\n📡 Testing Health Check...', 'blue');
  try {
    const response = await axios.get(`${BASE_URL}/`, {
      timeout: 5000
    });
    log('✅ Health Check: PASSED', 'green');
    log(`   Status: ${response.status}`, 'green');
    log(`   Response: ${JSON.stringify(response.data, null, 2)}`, 'green');
    return true;
  } catch (error) {
    log('❌ Health Check: FAILED', 'red');
    const errorMsg = getDetailedError(error);
    log(`   Error: ${errorMsg}`, 'red');
    if (error.code === 'ECONNREFUSED') {
      log('   💡 Tip: Make sure the server is running with: npm start', 'yellow');
    }
    return false;
  }
}

async function testSendMessage() {
  log('\n📤 Testing Send Message...', 'blue');
  try {
    const payload = {
      message: 'Test message from automated test script',
      phone: TEST_PHONE,
      subAccountId: SUB_ACCOUNT_ID
    };
    
    const response = await axios.post(`${BASE_URL}/send`, payload, {
      timeout: 10000
    });
    log('✅ Send Message: PASSED', 'green');
    log(`   Status: ${response.status}`, 'green');
    log(`   Response: ${JSON.stringify(response.data, null, 2)}`, 'green');
    return true;
  } catch (error) {
    log('❌ Send Message: FAILED', 'red');
    const errorMsg = getDetailedError(error);
    log(`   Error: ${errorMsg}`, 'red');
    if (error.response) {
      if (error.response.status === 401) {
        log('   ⚠️  Credentials not found (401 Unauthorized) - This is expected if .env is not configured', 'yellow');
        log('   💡 Set ULTRAMSG_INSTANCE_ID and ULTRAMSG_API_TOKEN in .env', 'yellow');
        log('   ✅ Endpoint is working correctly - just needs credentials', 'green');
      }
    } else if (error.code === 'ECONNREFUSED') {
      log('   💡 Tip: Make sure the server is running with: npm start', 'yellow');
    }
    return false;
  }
}

async function testIncomingMessage() {
  log('\n📥 Testing Incoming Message...', 'blue');
  try {
    const payload = {
      data: {
        from: TEST_PHONE,
        body: 'Test incoming message from automated test',
        id: 'test_msg_123',
        timestamp: Date.now().toString()
      },
      instanceId: 'test_instance',
      subAccountId: SUB_ACCOUNT_ID,
      contactId: 'test_contact_id'
    };
    
    const response = await axios.post(`${BASE_URL}/incoming`, payload, {
      timeout: 10000
    });
    log('✅ Incoming Message: PASSED', 'green');
    log(`   Status: ${response.status}`, 'green');
    log(`   Response: ${JSON.stringify(response.data, null, 2)}`, 'green');
    return true;
  } catch (error) {
    log('❌ Incoming Message: FAILED', 'red');
    const errorMsg = getDetailedError(error);
    log(`   Error: ${errorMsg}`, 'red');
    if (error.response) {
      if (error.response.status === 401) {
        log('   ⚠️  GHL API key not found (401 Unauthorized) - This is expected if .env is not configured', 'yellow');
        log('   💡 Set GHL_API_KEY in .env', 'yellow');
        log('   ✅ Endpoint is working correctly - just needs credentials', 'green');
      }
    } else if (error.code === 'ECONNREFUSED') {
      log('   💡 Tip: Make sure the server is running with: npm start', 'yellow');
    }
    return false;
  }
}

async function testStatusUpdate() {
  log('\n📊 Testing Status Update...', 'blue');
  try {
    const payload = {
      data: {
        id: 'test_msg_123',
        status: 'delivered'
      },
      messageId: 'test_msg_123',
      status: 'delivered',
      subAccountId: SUB_ACCOUNT_ID,
      instanceId: 'test_instance'
    };
    
    const response = await axios.post(`${BASE_URL}/status`, payload, {
      timeout: 10000
    });
    log('✅ Status Update: PASSED', 'green');
    log(`   Status: ${response.status}`, 'green');
    log(`   Response: ${JSON.stringify(response.data, null, 2)}`, 'green');
    return true;
  } catch (error) {
    log('❌ Status Update: FAILED', 'red');
    const errorMsg = getDetailedError(error);
    log(`   Error: ${errorMsg}`, 'red');
    if (error.code === 'ECONNREFUSED') {
      log('   💡 Tip: Make sure the server is running with: npm start', 'yellow');
    }
    return false;
  }
}

async function testErrorHandling() {
  log('\n⚠️  Testing Error Handling (Missing Fields)...', 'blue');
  try {
    const payload = {
      message: 'This should fail - missing phone field'
    };
    
    await axios.post(`${BASE_URL}/send`, payload, {
      timeout: 10000
    });
    log('❌ Error Handling: FAILED (Should have returned 400)', 'red');
    return false;
  } catch (error) {
    if (error.response && error.response.status === 400) {
      log('✅ Error Handling: PASSED', 'green');
      log(`   Status: ${error.response.status}`, 'green');
      log(`   Response: ${JSON.stringify(error.response.data, null, 2)}`, 'green');
      return true;
    } else if (error.code === 'ECONNREFUSED') {
      log('❌ Error Handling: FAILED (Server not running)', 'red');
      log(`   Error: ${getDetailedError(error)}`, 'red');
      log('   💡 Tip: Make sure the server is running with: npm start', 'yellow');
      return false;
    } else {
      log('❌ Error Handling: FAILED (Unexpected error)', 'red');
      log(`   Error: ${getDetailedError(error)}`, 'red');
      return false;
    }
  }
}

async function runAllTests() {
  log('\n🧪 Starting Endpoint Tests...', 'blue');
  log(`📍 Base URL: ${BASE_URL}`, 'blue');
  log(`📱 Test Phone: ${TEST_PHONE}`, 'blue');
  log(`🏢 Sub Account ID: ${SUB_ACCOUNT_ID}`, 'blue');
  log('\n⚠️  Note: Make sure your server is running with "npm start"', 'yellow');
  log('⚠️  Note: Environment variables in .env are optional for basic endpoint testing', 'yellow');
  
  const results = {
    healthCheck: await testHealthCheck(),
    sendMessage: await testSendMessage(),
    incomingMessage: await testIncomingMessage(),
    statusUpdate: await testStatusUpdate(),
    errorHandling: await testErrorHandling()
  };
  
  log('\n📊 Test Results Summary:', 'blue');
  log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'blue');
  
  Object.entries(results).forEach(([test, passed]) => {
    const status = passed ? '✅ PASSED' : '❌ FAILED';
    const color = passed ? 'green' : 'red';
    log(`   ${test.padEnd(20)} ${status}`, color);
  });
  
  const passedCount = Object.values(results).filter(Boolean).length;
  const totalCount = Object.keys(results).length;
  
  log('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━', 'blue');
  log(`\n   Total: ${passedCount}/${totalCount} tests passed`, 
      passedCount === totalCount ? 'green' : 'yellow');
  
  if (passedCount === totalCount) {
    log('\n🎉 All tests passed!', 'green');
  } else {
    log('\n⚠️  Some tests failed. Check the errors above.', 'yellow');
    log('   Note: Some failures may be expected if credentials are not configured.', 'yellow');
  }
  
  process.exit(passedCount === totalCount ? 0 : 1);
}

// Run tests
runAllTests().catch(error => {
  log(`\n💥 Fatal error: ${error.message}`, 'red');
  process.exit(1);
});

