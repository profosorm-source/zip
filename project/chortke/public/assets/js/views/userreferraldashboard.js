function copyReferralLink() {
    const input = document.getElementById('referralLink');
    input.select();
    document.execCommand('copy');
    alert('لینک کپی شد!');
}

// Real-time stats update (optional)
setInterval(() => {
    fetch('/api/user/referral/stats')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Update UI with new data
                console.log('Stats updated:', data.data);
            }
        });
}, 60000); // هر 1 دقیقه