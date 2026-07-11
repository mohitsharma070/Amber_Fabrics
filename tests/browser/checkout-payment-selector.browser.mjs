import { chromium } from 'playwright';

const html = String.raw`<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<title>Checkout Payment Selector Browser Test</title>
<style>
  .d-none { display: none; }
  .is-active { outline: 2px solid #0f766e; }
</style>
</head>
<body>
<form id="checkout_form">
  <input type="radio" name="payment_method" id="payment_cod" value="cod" checked aria-controls="cod-panel" />
  <input type="radio" name="payment_method" id="payment_razorpay" value="razorpay" aria-controls="razorpay-panel" />
  <input type="hidden" name="online_method" id="online_method" value="" />

  <div data-pay-option="cod"></div>
  <div data-pay-option="razorpay"></div>

  <div id="cod-panel"></div>
  <div id="razorpay-panel" hidden>
    <div class="checkout-online-methods" role="tablist" aria-label="Online payment method">
      <button type="button" class="checkout-online-method" id="online_method_upi_tab" role="tab" aria-controls="online_method_upi_panel" aria-selected="false" tabindex="-1" data-online-method="upi">UPI</button>
      <button type="button" class="checkout-online-method" id="online_method_card_tab" role="tab" aria-controls="online_method_card_panel" aria-selected="false" tabindex="-1" data-online-method="card">Card</button>
      <button type="button" class="checkout-online-method" id="online_method_emi_tab" role="tab" aria-controls="online_method_emi_panel" aria-selected="false" tabindex="-1" data-online-method="emi">EMI</button>
    </div>
    <div id="online_method_upi_panel" class="checkout-online-panel" data-online-panel="upi" role="tabpanel" aria-labelledby="online_method_upi_tab" hidden>UPI panel</div>
    <div id="online_method_card_panel" class="checkout-online-panel" data-online-panel="card" role="tabpanel" aria-labelledby="online_method_card_tab" hidden>Card panel</div>
    <div id="online_method_emi_panel" class="checkout-online-panel" data-online-panel="emi" role="tabpanel" aria-labelledby="online_method_emi_tab" hidden>EMI panel</div>
  </div>
</form>

<script>
(function () {
  var codRadio = document.getElementById('payment_cod');
  var razorpayRadio = document.getElementById('payment_razorpay');
  var payOptionCards = document.querySelectorAll('[data-pay-option]');
  var codPanel = document.getElementById('cod-panel');
  var razorpayPanel = document.getElementById('razorpay-panel');
  var onlineMethodButtons = document.querySelectorAll('.checkout-online-method');
  var onlinePanels = document.querySelectorAll('.checkout-online-panel');
  var onlineMethodInput = document.getElementById('online_method');
  var onlineMethods = ['upi', 'card', 'emi'];

  var paymentState = {
    paymentMethod: codRadio.checked ? 'cod' : 'razorpay',
    selectedOnlineMethod: onlineMethods.indexOf(String(onlineMethodInput && onlineMethodInput.value || '')) !== -1
      ? String(onlineMethodInput.value)
      : 'upi',
    lastValidOnlineMethod: 'upi'
  };
  paymentState.lastValidOnlineMethod = paymentState.selectedOnlineMethod;

  function syncOnlineMethodInput() {
    if (!onlineMethodInput) return;
    onlineMethodInput.value = paymentState.selectedOnlineMethod;
  }

  function ensureValidOnlineMethodForRazorpay() {
    if (onlineMethods.indexOf(paymentState.selectedOnlineMethod) === -1) {
      paymentState.selectedOnlineMethod = onlineMethods.indexOf(paymentState.lastValidOnlineMethod) !== -1
        ? paymentState.lastValidOnlineMethod
        : 'upi';
    }
    paymentState.lastValidOnlineMethod = paymentState.selectedOnlineMethod;
    syncOnlineMethodInput();
  }

  function activateOnlineMethod(method, focusSelected) {
    paymentState.selectedOnlineMethod = onlineMethods.indexOf(method) !== -1 ? method : 'upi';
    paymentState.lastValidOnlineMethod = paymentState.selectedOnlineMethod;
    syncOnlineMethodInput();

    onlineMethodButtons.forEach(function (btn) {
      var active = btn.getAttribute('data-online-method') === paymentState.selectedOnlineMethod;
      btn.classList.toggle('is-active', active);
      btn.setAttribute('aria-selected', active ? 'true' : 'false');
      btn.tabIndex = active ? 0 : -1;
      if (active && focusSelected) btn.focus();
    });

    onlinePanels.forEach(function (panel) {
      var active = panel.getAttribute('data-online-panel') === paymentState.selectedOnlineMethod;
      panel.classList.toggle('is-active', active);
      panel.hidden = !active;
      panel.classList.toggle('d-none', !active);
      panel.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
  }

  function syncPaymentPanels() {
    var selected = paymentState.paymentMethod;
    payOptionCards.forEach(function (card) {
      card.classList.toggle('is-active', card.getAttribute('data-pay-option') === selected);
    });

    codPanel.hidden = selected !== 'cod';
    codPanel.classList.toggle('d-none', selected !== 'cod');
    codPanel.setAttribute('aria-hidden', selected === 'cod' ? 'false' : 'true');

    razorpayPanel.hidden = selected !== 'razorpay';
    razorpayPanel.classList.toggle('d-none', selected !== 'razorpay');
    razorpayPanel.setAttribute('aria-hidden', selected === 'razorpay' ? 'false' : 'true');

    if (selected === 'razorpay') {
      ensureValidOnlineMethodForRazorpay();
      activateOnlineMethod(paymentState.selectedOnlineMethod);
    }
  }

  function handlePaymentMethodChange() {
    paymentState.paymentMethod = codRadio.checked ? 'cod' : 'razorpay';
    if (paymentState.paymentMethod === 'razorpay') {
      ensureValidOnlineMethodForRazorpay();
    } else {
      syncOnlineMethodInput();
    }
    syncPaymentPanels();
  }

  codRadio.addEventListener('change', handlePaymentMethodChange);
  razorpayRadio.addEventListener('change', handlePaymentMethodChange);

  onlineMethodButtons.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var method = btn.getAttribute('data-online-method');
      if (!razorpayRadio.checked) {
        razorpayRadio.checked = true;
        handlePaymentMethodChange();
      }
      activateOnlineMethod(method, true);
      syncOnlineMethodInput();
    });

    btn.addEventListener('keydown', function (ev) {
      if (['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].indexOf(ev.key) === -1) return;
      ev.preventDefault();
      var buttons = Array.prototype.slice.call(onlineMethodButtons);
      var index = buttons.indexOf(btn);
      if (ev.key === 'ArrowRight' || ev.key === 'ArrowDown') index = (index + 1) % buttons.length;
      if (ev.key === 'ArrowLeft' || ev.key === 'ArrowUp') index = (index - 1 + buttons.length) % buttons.length;
      if (ev.key === 'Home') index = 0;
      if (ev.key === 'End') index = buttons.length - 1;
      if (!razorpayRadio.checked) {
        razorpayRadio.checked = true;
        handlePaymentMethodChange();
      }
      activateOnlineMethod(buttons[index].getAttribute('data-online-method'), true);
      syncOnlineMethodInput();
    });
  });

  if (paymentState.paymentMethod === 'razorpay') {
    ensureValidOnlineMethodForRazorpay();
  }
  activateOnlineMethod(paymentState.selectedOnlineMethod);
  syncPaymentPanels();
})();
</script>
</body>
</html>`;

function assert(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
await page.setContent(html);

const snapshots = [];

async function snapshot(name) {
  const state = await page.evaluate(() => {
    const onlineInput = document.getElementById('online_method');
    const cod = document.getElementById('payment_cod');
    const razorpay = document.getElementById('payment_razorpay');
    const activeTab = document.querySelector('.checkout-online-method[aria-selected="true"]');
    const formData = new FormData(document.getElementById('checkout_form'));
    return {
      payment: cod.checked ? 'cod' : (razorpay.checked ? 'razorpay' : 'unknown'),
      online: onlineInput ? onlineInput.value : '',
      activeMethod: activeTab ? activeTab.getAttribute('data-online-method') : '',
      submittedPayment: String(formData.get('payment_method') || ''),
      submittedOnline: String(formData.get('online_method') || '')
    };
  });
  snapshots.push({ name, state });
  return state;
}

let s = await snapshot('initial COD state');
assert(s.payment === 'cod', 'Expected initial payment method cod');
assert(s.online === 'upi', 'Expected initial hidden online_method default to upi');

await page.check('#payment_razorpay');
s = await snapshot('COD to Razorpay');
assert(s.payment === 'razorpay', 'Expected razorpay selected');
assert(s.online === 'upi', 'Expected default upi when switching to razorpay');
assert(s.activeMethod === 'upi', 'Expected UPI tab active after COD to Razorpay');

await page.check('#payment_cod');
await page.check('#payment_razorpay');
s = await snapshot('Razorpay to COD and back');
assert(s.payment === 'razorpay', 'Expected return to razorpay');
assert(s.online === 'upi', 'Expected prior valid online method to be restored');

await page.click('#online_method_card_tab');
s = await snapshot('Card selection');
assert(s.online === 'card' && s.activeMethod === 'card', 'Expected Card selected');

await page.click('#online_method_emi_tab');
s = await snapshot('EMI selection');
assert(s.online === 'emi' && s.activeMethod === 'emi', 'Expected EMI selected');

await page.click('#online_method_upi_tab');
s = await snapshot('UPI selection');
assert(s.online === 'upi' && s.activeMethod === 'upi', 'Expected UPI selected');

await page.focus('#online_method_upi_tab');
await page.keyboard.press('ArrowRight');
s = await snapshot('Keyboard ArrowRight');
assert(s.online === 'card', 'Expected ArrowRight to move selection to Card');

await page.keyboard.press('End');
s = await snapshot('Keyboard End');
assert(s.online === 'emi', 'Expected End to move selection to EMI');

console.log('Browser state snapshots:');
for (const item of snapshots) {
  console.log('- ' + item.name + ': payment=' + item.state.payment + ', online_method=' + item.state.online + ', active=' + item.state.activeMethod + ', submitted(payment_method)=' + item.state.submittedPayment + ', submitted(online_method)=' + item.state.submittedOnline);
}

await browser.close();
