(function () {
  'use strict';

  window.showOnlinePaymentModal = function () {
    const m = document.getElementById('onlinePaymentModal');
    if (m) {
      m.style.setProperty('display', 'flex', 'important');
      m.classList.add('show');
      const ik = document.getElementById('payment_idempotency_key');
      if (ik) ik.value = 'dep_' + Math.random().toString(36).substr(2, 9) + '_' + Date.now();
    }
  };

  window.closeOnlinePaymentModal = function () {
    const m = document.getElementById('onlinePaymentModal');
    if (m) {
      m.style.setProperty('display', 'none', 'important');
      m.classList.remove('show');
    }
  };

  window.setAmount = function (amount) {
    const input = document.getElementById('amount');
    if (input) input.value = amount;
  };

  document.addEventListener('click', function (event) {
    const modal = document.getElementById('onlinePaymentModal');
    if (modal && event.target === modal) {
      window.closeOnlinePaymentModal();
    }
  });
})();
