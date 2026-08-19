const form = document.getElementById('message-form');
const messageInput = document.getElementById('message-input');
const messagesContainer = document.getElementById('messages-container');
const recipientId = document.querySelector('input[name="recipient_id"]').value;
let typingTimeout;

// Character counter
messageInput.addEventListener('input', (e) => {
    document.getElementById('char-count').textContent = e.target.value.length + ' / 5000';
    
    // Send typing indicator
    clearTimeout(typingTimeout);
    fetch('/messages/typing', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': document.querySelector('[name="_token"]').value
        },
        body: JSON.stringify({ recipient_id: recipientId, is_typing: true })
    });
    
    typingTimeout = setTimeout(() => {
        fetch('/messages/typing', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('[name="_token"]').value
            },
            body: JSON.stringify({ recipient_id: recipientId, is_typing: false })
        });
    }, 3000);
});

// Send message
form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const message = messageInput.value.trim();
    
    if (!message) return;
    
    try {
        const response = await fetch('/messages/send', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': document.querySelector('[name="_token"]').value
            },
            body: JSON.stringify({
                recipient_id: recipientId,
                message: message,
                is_encrypted: false
            })
        });
        
        if (response.ok) {
            messageInput.value = '';
            document.getElementById('char-count').textContent = '0 / 5000';
            // Reload messages
            loadMessages();
        }
    } catch (error) {
        console.error('Error sending message:', error);
    }
});

// Load messages periodically
function loadMessages() {
    // This would typically be a real-time update with WebSocket
    // For now, a simple implementation
    location.reload();
}

// Typing indicator listener
setInterval(() => {
    fetch(`/messages/typing/users?recipient_id=${recipientId}`)
        .then(r => r.json())
        .then(data => {
            const indicator = document.getElementById('typing-indicator');
            if (data.typing_users > 0) {
                indicator.textContent = 'در حال تایپ...';
            } else {
                indicator.textContent = '';
            }
        });
}, 1000);

// Auto-scroll to bottom
messagesContainer.scrollTop = messagesContainer.scrollHeight;