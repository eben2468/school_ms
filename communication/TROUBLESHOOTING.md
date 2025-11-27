# Live Chat System Troubleshooting Guide

## Database Setup Issues

### Problem: Tables not created during setup
**Symptoms**: Error messages about missing tables when accessing live chat

**Solution**:
1. Run the database setup script: `http://localhost/school_ms/communication/setup_live_chat_db.php`
2. Check that all 8 tables are created successfully
3. If errors persist, check database permissions and connection

### Problem: Foreign key constraint errors
**Symptoms**: Errors about foreign key constraints during table creation

**Solution**:
1. Ensure the `users` table exists and has proper structure
2. Check that the database user has proper privileges
3. Run the setup script as a super admin user

## Live Chat Page Issues

### Problem: Chat rooms not loading
**Symptoms**: Empty chat room list or loading errors

**Solution**:
1. Verify database tables exist
2. Check user permissions and session
3. Ensure default rooms were created during setup

### Problem: Messages not sending
**Symptoms**: Messages don't appear after sending

**Solution**:
1. Check browser console for JavaScript errors
2. Verify API endpoints are accessible
3. Check database connection in `live_chat_api.php`

### Problem: File uploads failing
**Symptoms**: Error when trying to upload files

**Solution**:
1. Create uploads directory: `mkdir uploads/chat_files`
2. Set proper permissions: `chmod 755 uploads/chat_files`
3. Check file size and type restrictions

## API Issues

### Problem: API endpoints returning errors
**Symptoms**: 500 errors or JSON error responses

**Solution**:
1. Check PHP error logs
2. Verify database connection
3. Ensure user is properly authenticated

### Problem: Real-time updates not working
**Symptoms**: Messages don't appear automatically

**Solution**:
1. Check JavaScript console for errors
2. Verify polling interval is working
3. Check network connectivity

## Permission Issues

### Problem: Users can't access certain rooms
**Symptoms**: Access denied errors for specific rooms

**Solution**:
1. Check room type and user role compatibility
2. Verify user is added to room participants
3. Check user session and authentication

### Problem: Admin features not accessible
**Symptoms**: Can't access chat admin panel

**Solution**:
1. Ensure user has admin role (super_admin, school_admin, principal)
2. Check session variables
3. Verify admin panel permissions

## Performance Issues

### Problem: Slow message loading
**Symptoms**: Long delays when loading chat history

**Solution**:
1. Check database indexes are created
2. Optimize message queries
3. Consider implementing pagination

### Problem: High server load
**Symptoms**: Server becomes slow with many users

**Solution**:
1. Increase polling interval
2. Implement message caching
3. Consider WebSocket implementation

## Browser Compatibility

### Problem: Features not working in certain browsers
**Symptoms**: JavaScript errors or missing functionality

**Solution**:
1. Update to modern browser version
2. Enable JavaScript
3. Check browser console for specific errors

## Quick Fixes

### Reset Chat System
If you need to completely reset the chat system:

```sql
-- Drop all live chat tables
DROP TABLE IF EXISTS live_chat_reports;
DROP TABLE IF EXISTS live_chat_blocked_users;
DROP TABLE IF EXISTS live_chat_message_reads;
DROP TABLE IF EXISTS live_chat_user_status;
DROP TABLE IF EXISTS live_chat_message_reactions;
DROP TABLE IF EXISTS live_chat_messages;
DROP TABLE IF EXISTS live_chat_participants;
DROP TABLE IF EXISTS live_chat_rooms;
```

Then run the setup script again.

### Clear User Sessions
If users are experiencing login issues:

```sql
-- Clear user status
DELETE FROM live_chat_user_status;
```

### Reset Room Participants
If room access is broken:

```sql
-- Clear and rebuild participants
DELETE FROM live_chat_participants;
-- Then run the setup script to rebuild default participants
```

## Debugging Tips

### Enable Debug Mode
Add this to the top of `live_chat_api.php` for detailed error logging:

```php
error_reporting(E_ALL);
ini_set('display_errors', 1);
```

### Check Database Connection
Test database connectivity:

```php
try {
    $database = new Database();
    $db = $database->getConnection();
    echo "Database connection successful";
} catch (Exception $e) {
    echo "Database error: " . $e->getMessage();
}
```

### Monitor API Calls
Use browser developer tools to monitor API requests and responses.

## Getting Help

If issues persist:

1. Check the main documentation: `LIVE_CHAT_README.md`
2. Review error logs in your web server
3. Test with a fresh browser session
4. Verify all file permissions are correct
5. Contact system administrator

## Common Error Messages

- **"Table doesn't exist"**: Run database setup script
- **"Access denied"**: Check user permissions and session
- **"File upload failed"**: Check directory permissions
- **"Invalid action"**: Verify API endpoint parameters
- **"Database error"**: Check database connection and permissions

Remember to always backup your database before making any changes!
