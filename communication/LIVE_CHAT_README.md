# Live Chat System Documentation

## Overview
The Live Chat System is a comprehensive real-time messaging platform integrated into the school management system. It allows all users to engage in live conversations across different chat rooms with modern features and administrative controls.

## Features

### Core Features
- **Real-time Messaging**: Instant message delivery and updates
- **Multiple Chat Rooms**: Support for different types of chat rooms (public, private, admin-only)
- **User Status Indicators**: Online, away, busy, and offline status
- **Typing Indicators**: See when other users are typing
- **Message Reactions**: React to messages with emojis
- **File Sharing**: Upload and share images and files
- **Message Search**: Search through chat history
- **User Blocking/Reporting**: Report inappropriate content and block users

### Advanced Features
- **Emoji Support**: Rich emoji picker with common emojis
- **Read Receipts**: Track message read status
- **Room Management**: Create and manage chat rooms
- **Moderation Tools**: Admin panel for managing conversations
- **Responsive Design**: Works on desktop and mobile devices
- **Dark Mode Support**: Automatic theme switching

## Database Schema

### Tables Created
1. **live_chat_rooms**: Chat room information
2. **live_chat_participants**: Room membership and roles
3. **live_chat_messages**: All chat messages
4. **live_chat_message_reactions**: Message reactions/emojis
5. **live_chat_user_status**: User online status and typing indicators
6. **live_chat_message_reads**: Read receipt tracking
7. **live_chat_blocked_users**: User blocking functionality
8. **live_chat_reports**: Content reporting system

## File Structure

```
communication/
├── live_chat.php              # Main chat interface
├── live_chat_api.php          # API endpoints for chat functionality
├── chat_admin.php             # Administrative panel
├── live_chat_schema.sql       # Database schema
├── setup_live_chat_db.php     # Database setup script
└── LIVE_CHAT_README.md        # This documentation
```

## Installation & Setup

### 1. Database Setup
Run the database setup script to create all necessary tables:
```
http://localhost/school_ms/communication/setup_live_chat_db.php
```

### 2. Default Chat Rooms
The system creates 4 default chat rooms:
- **General Discussion**: Main public chat room
- **Academic Support**: Help with academic questions
- **Announcements**: Official school announcements
- **Staff Room**: Private room for school staff

### 3. User Permissions
- **All Users**: Can access public chat rooms
- **Staff Members**: Can access staff-only rooms
- **Administrators**: Can access all rooms and admin panel

## Usage Guide

### For Users
1. **Accessing Chat**: Navigate to Communication > Live Chat
2. **Selecting Rooms**: Click on any chat room from the left sidebar
3. **Sending Messages**: Type in the message box and press Enter or click Send
4. **File Sharing**: Click the paperclip icon to upload files
5. **Reactions**: Hover over messages to see reaction options
6. **Search**: Click the search icon to search through messages

### For Administrators
1. **Admin Panel**: Access via `chat_admin.php`
2. **Creating Rooms**: Use the "Create Room" button
3. **Managing Reports**: Review and resolve user reports
4. **Room Statistics**: View usage analytics and statistics

## API Endpoints

### Message Operations
- `GET live_chat_api.php?action=get_messages&room_id=X` - Get messages
- `POST live_chat_api.php` with `action=send_message` - Send message
- `POST live_chat_api.php` with `action=react_to_message` - React to message

### User Operations
- `POST live_chat_api.php` with `action=update_status` - Update online status
- `POST live_chat_api.php` with `action=typing` - Send typing indicator
- `POST live_chat_api.php` with `action=join_room` - Join chat room

### File Operations
- `POST live_chat_api.php` with `action=upload_file` - Upload file

### Search & Reports
- `GET live_chat_api.php?action=search_messages` - Search messages
- `POST live_chat_api.php` with `action=submit_report` - Submit report

## Security Features

### Access Control
- Session-based authentication required
- Role-based room access permissions
- User blocking and reporting system

### Content Moderation
- Report system for inappropriate content
- Admin review and resolution workflow
- User muting and banning capabilities

### File Upload Security
- File type restrictions (images, PDFs, text files)
- File size limits (5MB maximum)
- Secure file storage in uploads directory

## Performance Optimizations

### Database Indexes
- Optimized queries with proper indexing
- Efficient message loading with pagination
- Real-time updates with minimal server load

### Frontend Optimizations
- Message polling every 2 seconds
- Lazy loading of chat history
- Efficient DOM updates

## Customization Options

### Room Types
- **Public**: Accessible to all users
- **Private**: Invitation-only rooms
- **Class**: Class-specific discussions
- **Department**: Department-specific rooms
- **Admin Only**: Administrative discussions

### Message Types
- **Text**: Regular text messages
- **Image**: Image files with preview
- **File**: Document attachments
- **System**: Automated system messages
- **Announcement**: Official announcements

## Troubleshooting

### Common Issues
1. **Messages not loading**: Check database connection and permissions
2. **File uploads failing**: Verify upload directory permissions
3. **Real-time updates not working**: Check JavaScript console for errors

### Debug Mode
Enable debug mode by adding error logging to the API endpoints for detailed error information.

## Future Enhancements

### Planned Features
- Voice and video calling integration
- Message encryption for sensitive conversations
- Advanced file sharing with preview
- Integration with notification system
- Mobile app support
- Message threading and replies

### Performance Improvements
- WebSocket implementation for true real-time updates
- Message caching for faster loading
- Advanced search with filters
- Bulk message operations

## Support

For technical support or feature requests, contact the system administrator or refer to the main school management system documentation.

## Version History

- **v1.0**: Initial release with core chat functionality
- **v1.1**: Added file sharing and reactions
- **v1.2**: Implemented admin panel and reporting system
- **v1.3**: Enhanced security and performance optimizations
