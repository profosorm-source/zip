// Countdown Timer (مثال: 2 ساعت)
        let countDownDate = new Date().getTime() + (2 * 60 * 60 * 1000);
        
        let x = setInterval(function() {
            let now = new Date().getTime();
            let distance = countDownDate - now;
            
            let hours = Math.floor((distance % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            let minutes = Math.floor((distance % (1000 * 60 * 60)) / (1000 * 60));
            let seconds = Math.floor((distance % (1000 * 60)) / 1000);
            
            document.getElementById("hours").innerText = String(hours).padStart(2, '0');
            document.getElementById("minutes").innerText = String(minutes).padStart(2, '0');
            document.getElementById("seconds").innerText = String(seconds).padStart(2, '0');
            
            if (distance < 0) {
                clearInterval(x);
                location.reload();
            }
        }, 1000);