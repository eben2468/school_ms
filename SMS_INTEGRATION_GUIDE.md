# SMS Integration Guide

## Overview
Complete SMS integration system with support for multiple SMS gateways including mNotify, Hubtel, Twilio, Termii, Wigal, Nalopay, and more.

## Features
- ✅ Multiple SMS gateway support
- ✅ Unified API for sending SMS
- ✅ Automatic SMS logging
- ✅ Test SMS functionality
- ✅ Notification triggers configuration
- ✅ Error handling and reporting

## Supported Gateways

### 1. mNotify (NotifySMS)
**Endpoint:** `https://api.mnotify.com/api/sms/quick`
**Configuration:**
- API Key: Get from mNotify dashboard
- Sender ID: Your approved sender name

**Features:**
- Quick SMS sending
- Group messaging support
- Scheduled SMS (optional)

### 2. Hubtel
**Endpoint:** `https://devp-sms.hubtel.com/v1/messages/send`
**Configuration:**
- Client ID: Your Hubtel client ID
- Client Secret: Your Hubtel client secret
- Sender ID: Your approved sender name

**Features:**
- Reliable delivery
- Delivery reports
- Developer-friendly API

### 3. Twilio
**Endpoint:** `https://api.twilio.com/2010-04-01/Accounts/{AccountSid}/Messages.json`
**Configuration:**
- Account SID: Your Twilio account SID
- Auth Token: Your Twilio auth token
- Phone Number: Your Twilio phone number

**Features:**
- Global coverage
- High reliability
- Advanced features

### 4. Termii
**Endpoint:** `https://api.ng.termii.com/api/sms/send`
**Configuration:**
- API Key: Your Termii API key
- Sender ID: Your approved sender name

**Features:**
- African market focus
- Competitive pricing
- Multiple channels

### 5. Wigal
**Endpoint:** `https://frog.wigal.com.gh/api/send`
**Configuration:**
- API Key: Your Wigal API key
- Sender ID: Your approved sender name

**Features:**
- Ghana-focused
- Local support
- Reliable delivery

### 6. Nalopay
**Endpoint:** `https://api.nalosolutions.com/sms/v1/text/single`
**Configuration:**
- API Key: Your Nalopay API key
- Sender ID: Your approved sender name

**Features:**
- Multi-service platform
- SMS and payment integration
- Ghana market

### 7. Nexmo/Vonage
**Endpoint:** `https://rest.nexmo.com/sms/json`
**Configuration:**
- API Key: Your Nexmo API key
- API Secret: Your Nexmo API secret
- Sender ID: Your approved sender name

**Features:**
- Global reach
- Enterprise-grade
- Comprehensive API

## Setup Instructions

### Step 1: Configure SMS Gateway
1. Go to **Settings → SMS Integration**
2. Select your SMS gateway provider
3. Enter your API credentials:
   - API Key / Account SID
   - API Secret / Auth Token (if required)
   - Sender ID / Phone Number
4. Click **Save Changes**

### Step 2: Configure Notification Triggers
Enable/disable SMS notifications for:
- Student Absence Alerts
- Fee Payment Reminders
- Exam Results Published
- Event Announcements
- Emergency Alerts

### Step 3: Test Your Configuration
1. Click **Go to Test SMS Page**
2. Enter a test phone number
3. Enter a test message
4. Click **Send Test SMS**
5. Verify delivery

### Step 4: Run Migration (if needed)
Visit: `http://your-domain/school_ms/migrate_sms_notifications.php`

This will create necessary database columns for SMS notification triggers.

## Usage Examples

### Send SMS to Single Recipient
```php
require_once 'includes/sms_helper.php';

$phone = '+233XXXXXXXXX';
$message = 'Hello from School Management System!';

$result = sendSMS($phone, $message);

if ($result['success']) {
    echo "SMS sent successfully!";
} else {
    echo "Failed: " . $result['message'];
}
```

### Send SMS to Multiple Recipients
```php
$phones = ['+233XXXXXXXXX', '+233YYYYYYYYY', '+233ZZZZZZZZZ'];
$message = 'Important school announcement!';

$result = sendSMS($phones, $message);
```

### Send SMS with Custom Sender ID
```php
$phone = '+233XXXXXXXXX';
$message = 'Your exam results are ready!';
$sender_id = 'SCHOOL';

$result = sendSMS($phone, $message, $sender_id);
```

### Check SMS Logs
```php
$logs_query = "SELECT * FROM sms_logs ORDER BY created_at DESC LIMIT 10";
$logs_stmt = $db->query($logs_query);
$logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($logs as $log) {
    echo "To: " . $log['recipients'] . "\n";
    echo "Status: " . $log['status'] . "\n";
    echo "Gateway: " . $log['gateway'] . "\n";
}
```

## Integration with School Features

### Absence Alerts
```php
// When marking student absent
if ($settings['sms_absence_alerts'] == '1') {
    $parent_phone = getParentPhone($student_id);
    $message = "Your child {$student_name} was marked absent today.";
    sendSMS($parent_phone, $message);
}
```

### Fee Payment Reminders
```php
// Send payment reminders
if ($settings['sms_payment_reminders'] == '1') {
    $parents = getPendingPaymentParents();
    foreach ($parents as $parent) {
        $message = "Reminder: School fees payment due for {$parent['student_name']}.";
        sendSMS($parent['phone'], $message);
    }
}
```

### Exam Results
```php
// When results are published
if ($settings['sms_exam_results'] == '1') {
    $parents = getParentsForClass($class_id);
    foreach ($parents as $parent) {
        $message = "Exam results for {$parent['student_name']} are now available.";
        sendSMS($parent['phone'], $message);
    }
}
```

### Emergency Alerts
```php
// Send emergency notification
if ($settings['sms_emergency_alerts'] == '1') {
    $all_parents = getAllParentPhones();
    $message = "URGENT: School will close early today due to weather.";
    sendSMS($all_parents, $message);
}
```

## Database Schema

### school_settings Table (SMS Columns)
```sql
sms_gateway VARCHAR(50) DEFAULT 'disabled'
sms_api_key VARCHAR(255) DEFAULT ''
sms_api_secret VARCHAR(255) DEFAULT ''
sms_sender_id VARCHAR(50) DEFAULT ''
sms_absence_alerts ENUM('0','1') DEFAULT '0'
sms_payment_reminders ENUM('0','1') DEFAULT '0'
sms_exam_results ENUM('0','1') DEFAULT '0'
sms_event_announcements ENUM('0','1') DEFAULT '0'
sms_emergency_alerts ENUM('0','1') DEFAULT '0'
```

### sms_logs Table
```sql
CREATE TABLE sms_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    recipients TEXT NOT NULL,
    message TEXT NOT NULL,
    gateway VARCHAR(50) NOT NULL,
    status VARCHAR(20) NOT NULL,
    response TEXT,
    sent_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Troubleshooting

### SMS Not Sending
1. Check gateway configuration in settings
2. Verify API credentials are correct
3. Ensure sender ID is approved by provider
4. Check SMS logs for error messages
5. Test with test SMS page

### Invalid Phone Numbers
- Ensure phone numbers include country code
- Format: +233XXXXXXXXX (Ghana)
- Remove spaces and special characters
- Validate before sending

### API Errors
- Check API key validity
- Verify account has sufficient credits
- Check provider's API status
- Review error response in logs

### Database Errors
- Run migration script
- Check database permissions
- Verify table structure
- Check error logs

## Best Practices

1. **Test First**: Always test with test SMS page before bulk sending
2. **Monitor Logs**: Regularly check SMS logs for failures
3. **Keep Messages Short**: Stay under 160 characters when possible
4. **Use Sender ID**: Register and use approved sender IDs
5. **Handle Errors**: Implement proper error handling
6. **Rate Limiting**: Be mindful of API rate limits
7. **Cost Management**: Monitor SMS usage and costs
8. **Data Privacy**: Protect phone numbers and message content

## Security Considerations

1. **API Keys**: Store securely, never expose in client-side code
2. **Phone Numbers**: Validate and sanitize before sending
3. **Message Content**: Sanitize user input in messages
4. **Access Control**: Restrict SMS sending to authorized users
5. **Logging**: Log all SMS activity for audit trail
6. **SSL/TLS**: Use HTTPS for all API communications

## Support

For issues or questions:
1. Check SMS logs for error details
2. Review provider's API documentation
3. Contact your SMS provider support
4. Check system error logs

## Files Reference

- `includes/sms_helper.php` - Core SMS functions
- `settings/tabs/sms.php` - SMS settings UI
- `settings/test_sms.php` - Test SMS page
- `settings/school.php` - Settings handler
- `migrate_sms_notifications.php` - Database migration

## Version History

**v1.0.0** - Initial release
- Multiple gateway support
- Notification triggers
- SMS logging
- Test functionality
