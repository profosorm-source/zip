document.getElementById('search-conversations').addEventListener('input', function(e) {
    const searchTerm = e.target.value.toLowerCase();
    document.querySelectorAll('a[href^="/messages/"]').forEach(el => {
        const userName = el.querySelector('h3').textContent.toLowerCase();
        el.style.display = userName.includes(searchTerm) ? 'block' : 'none';
    });
});

function openNewMessageModal() {
    document.getElementById('new-message-modal').classList.remove('hidden');
}

function closeNewMessageModal() {
    document.getElementById('new-message-modal').classList.add('hidden');
}

document.getElementById('new-message-form').addEventListener('submit', async function(e) {
    e.preventDefault();

    const recipientIdentifier = document.getElementById('new-recipient').value.trim();
    const message = document.getElementById('new-message-text').value.trim();
    const token = document.querySelector('[name="_token"]').value;

    if (!recipientIdentifier || !message) {
        return;
    }

    // Simple routing by identifier: assume numeric ID or username
    let recipientId = recipientIdentifier;
    if (isNaN(recipientId)) {
        recipientId = 0;
    }

    const payload = {
        recipient_id: recipientId,
        message,
        is_encrypted: false
    };

    const response = await fetch('/messages/send', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': token
        },
        body: JSON.stringify(payload)
    });

    if (response.ok) {
        closeNewMessageModal();
        window.location.reload();
    }
});

// Real-time unread count update
setInterval(() => {
    fetch('/messages/unread/count')
        .then(r => r.json())
        .then(data => {
            document.querySelector('.badge-info').textContent = data.count + ' خوانده نشده';
        });
}, 5000);