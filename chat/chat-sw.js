// Chat Service Worker for offline message persistence
const CACHE_NAME = 'school-chat-v1';
const CHAT_CACHE = 'chat-messages-v1';

// Cache chat-related resources
const urlsToCache = [
    '/school_ms/chat/get_messages.php',
    '/school_ms/chat/get_conversations.php',
    '/school_ms/chat/send_message.php'
];

// Install event - cache resources
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then(cache => {
                console.log('Chat service worker: Caching chat resources');
                return cache.addAll(urlsToCache);
            })
    );
});

// Fetch event - handle network requests
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    
    // Handle chat API requests
    if (url.pathname.includes('/chat/')) {
        event.respondWith(handleChatRequest(event.request));
    }
});

// Handle chat-specific requests with offline fallback
async function handleChatRequest(request) {
    const url = new URL(request.url);
    
    try {
        // Try network first
        const networkResponse = await fetch(request);
        
        // If successful, cache the response for GET requests
        if (networkResponse.ok && request.method === 'GET') {
            const cache = await caches.open(CHAT_CACHE);
            cache.put(request, networkResponse.clone());
        }
        
        return networkResponse;
    } catch (error) {
        console.log('Chat service worker: Network failed, trying cache');
        
        // If network fails, try cache for GET requests
        if (request.method === 'GET') {
            const cachedResponse = await caches.match(request);
            if (cachedResponse) {
                return cachedResponse;
            }
        }
        
        // If both network and cache fail, return offline response
        return new Response(
            JSON.stringify({
                success: false,
                message: 'Offline - messages will sync when connection is restored',
                offline: true
            }),
            {
                status: 200,
                headers: { 'Content-Type': 'application/json' }
            }
        );
    }
}

// Background sync for offline messages
self.addEventListener('sync', event => {
    if (event.tag === 'chat-sync') {
        event.waitUntil(syncOfflineMessages());
    }
});

// Sync offline messages when connection is restored
async function syncOfflineMessages() {
    try {
        // Get offline messages from IndexedDB
        const offlineMessages = await getOfflineMessages();
        
        for (const message of offlineMessages) {
            try {
                const response = await fetch('/school_ms/chat/send_message.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify(message)
                });
                
                if (response.ok) {
                    // Remove from offline storage
                    await removeOfflineMessage(message.id);
                }
            } catch (error) {
                console.error('Failed to sync message:', error);
            }
        }
    } catch (error) {
        console.error('Error syncing offline messages:', error);
    }
}

// IndexedDB helpers for offline message storage
async function getOfflineMessages() {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('ChatOfflineDB', 1);
        
        request.onerror = () => reject(request.error);
        
        request.onsuccess = () => {
            const db = request.result;
            const transaction = db.transaction(['messages'], 'readonly');
            const store = transaction.objectStore('messages');
            const getRequest = store.getAll();
            
            getRequest.onsuccess = () => resolve(getRequest.result);
            getRequest.onerror = () => reject(getRequest.error);
        };
        
        request.onupgradeneeded = () => {
            const db = request.result;
            if (!db.objectStoreNames.contains('messages')) {
                db.createObjectStore('messages', { keyPath: 'id' });
            }
        };
    });
}

async function removeOfflineMessage(messageId) {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open('ChatOfflineDB', 1);
        
        request.onsuccess = () => {
            const db = request.result;
            const transaction = db.transaction(['messages'], 'readwrite');
            const store = transaction.objectStore('messages');
            const deleteRequest = store.delete(messageId);
            
            deleteRequest.onsuccess = () => resolve();
            deleteRequest.onerror = () => reject(deleteRequest.error);
        };
    });
}

// Message to main thread
self.addEventListener('message', event => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
});

console.log('Chat service worker loaded successfully');
