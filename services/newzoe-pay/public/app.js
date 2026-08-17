'use strict';

const statusElement = document.querySelector('#payment-status');
const statusTitle = document.querySelector('#status-title');
const statusDetail = document.querySelector('#status-detail');
const dialog = document.querySelector('#success-dialog');
const successMessage = document.querySelector('#success-message');
const closeButton = document.querySelector('#close-success');
const amountElement = document.querySelector('#payment-amount');
const footerAmount = document.querySelector('#footer-amount');
const orderReference = document.querySelector('#order-reference');
const paymentLabel = document.querySelector('#payment-label');
const paymentRecipient = document.querySelector('#payment-recipient');
const paymentCountdown = document.querySelector('#payment-countdown');
const countdownLabel = document.querySelector('#countdown-label');
const countdownValue = document.querySelector('#countdown-value');
const paymentQrcode = document.querySelector('#payment-qrcode');
const checkout = document.querySelector('#checkout');
const placeholderCopy = document.querySelector('#placeholder-copy');
const placeholderTitle = document.querySelector('#placeholder-title');
const placeholderDetail = document.querySelector('#placeholder-detail');
const placeholderAnimation = document.querySelector('#placeholder-animation');
const retryButton = document.querySelector('#retry-load');
const orderSummary = document.querySelector('#order-summary');
const qrContent = document.querySelector('#qr-content');
const footerStatus = document.querySelector('#footer-status');

const orderId = decodeURIComponent(location.pathname.slice(1)).toUpperCase();
let order = null;
let paymentReady = false;
let events = null;
let countdownTimer = null;
let serverClockOffset = 0;

function formatAmount(amountFen) {
  return (amountFen / 100).toFixed(2);
}

function getClientId() {
  let id = sessionStorage.getItem('newzoe-pay-client');
  if (!id) {
    id = crypto.randomUUID();
    sessionStorage.setItem('newzoe-pay-client', id);
  }
  return id;
}

function setStatus(state, title, detail) {
  statusElement.className = `payment-status ${state ? `is-${state}` : ''}`.trim();
  statusTitle.textContent = title;
  statusDetail.textContent = detail;
}

function stopCountdown() {
  if (countdownTimer) window.clearInterval(countdownTimer);
  countdownTimer = null;
}

function expireCheckout() {
  if (order?.status === 'paid') return;
  stopCountdown();
  if (events) events.close();
  paymentQrcode.removeAttribute('src');
  paymentCountdown.hidden = true;
  showPlaceholder('订单已过期', '二维码已关闭，请返回商店重新下单');
}

function waitForSettlement() {
  if (order?.status === 'paid') return;
  if (order) order.status = 'confirming';
  paymentQrcode.removeAttribute('src');
  paymentQrcode.hidden = true;
  qrContent.hidden = true;
  placeholderAnimation.hidden = false;
  checkout.classList.remove('is-placeholder');
  placeholderCopy.hidden = true;
  orderSummary.hidden = false;
  statusElement.hidden = false;
  setStatus('confirming', '到账确认中', '支付窗口已结束，系统仍在确认到账，请勿重复付款');
  footerStatus.textContent = '到账确认中';
  paymentReady = false;
}

function updateCountdown() {
  const expiresAt = Date.parse(order?.expiresAt || '');
  const matchExpiresAt = Date.parse(order?.matchExpiresAt || '');
  if (!Number.isFinite(expiresAt) || !Number.isFinite(matchExpiresAt)) {
    paymentCountdown.hidden = true;
    return;
  }
  const serverNow = Date.now() + serverClockOffset;
  if (serverNow >= matchExpiresAt) {
    expireCheckout();
    return;
  }
  if (serverNow >= expiresAt) {
    waitForSettlement();
    const remaining = Math.max(0, matchExpiresAt - serverNow);
    const seconds = Math.ceil(remaining / 1000);
    const minutesPart = Math.floor(seconds / 60);
    const secondsPart = seconds % 60;
    countdownLabel.textContent = '到账确认剩余';
    countdownValue.textContent = `${String(minutesPart).padStart(2, '0')}:${String(secondsPart).padStart(2, '0')}`;
    paymentCountdown.hidden = false;
    return;
  }
  const remaining = Math.max(0, expiresAt - serverNow);
  const seconds = Math.ceil(remaining / 1000);
  const minutesPart = Math.floor(seconds / 60);
  const secondsPart = seconds % 60;
  countdownLabel.textContent = '支付剩余时间';
  countdownValue.textContent = `${String(minutesPart).padStart(2, '0')}:${String(secondsPart).padStart(2, '0')}`;
  paymentCountdown.hidden = false;
}

function startCountdown() {
  stopCountdown();
  const serverTime = Date.parse(order?.serverTime || '');
  serverClockOffset = Number.isFinite(serverTime) ? serverTime - Date.now() : 0;
  updateCountdown();
  countdownTimer = window.setInterval(updateCountdown, 250);
}

function showPlaceholder(title, detail, { retry = false } = {}) {
  checkout.classList.add('is-placeholder');
  placeholderCopy.hidden = false;
  placeholderAnimation.hidden = false;
  orderSummary.hidden = true;
  statusElement.hidden = true;
  qrContent.hidden = true;
  paymentQrcode.hidden = true;
  paymentCountdown.hidden = true;
  retryButton.hidden = !retry;
  placeholderTitle.textContent = title;
  placeholderDetail.textContent = detail;
  footerAmount.textContent = 'NEWZOE PAY';
  footerStatus.textContent = title;
  paymentReady = false;
}

function showOrder() {
  checkout.classList.remove('is-placeholder');
  placeholderCopy.hidden = true;
  placeholderAnimation.hidden = true;
  retryButton.hidden = true;
  orderSummary.hidden = false;
  statusElement.hidden = false;
  qrContent.hidden = false;
  footerStatus.textContent = '实时确认';
}

function showSuccess(payment) {
  stopCountdown();
  showOrder();
  paymentCountdown.hidden = true;
  qrContent.hidden = true;
  paymentQrcode.hidden = true;
  paymentQrcode.removeAttribute('src');
  const amount = formatAmount(payment.amountFen);
  setStatus('paid', '支付成功', `¥${amount} 已到账`);
  successMessage.textContent = payment.test
    ? `¥${amount} 测试通知链路正常。`
    : `已收到 ¥${amount}，订单正在确认。`;

  if (typeof dialog.showModal === 'function' && !dialog.open) dialog.showModal();
  else dialog.setAttribute('open', '');

  if (order?.returnUrl) {
    window.setTimeout(() => { location.href = order.returnUrl; }, 1800);
  }
}

async function loadOrder() {
  if (!orderId) {
    showPlaceholder('等待订单', '请通过完整付款链接进入收银台');
    return false;
  }
  const response = await fetch(`/api/orders/${encodeURIComponent(orderId)}`, { cache: 'no-store' });
  if (!response.ok) {
    if (response.status === 410) showPlaceholder('订单已失效', '请返回商店重新下单');
    else showPlaceholder('订单未找到', '请检查付款链接后重试');
    return false;
  }
  order = await response.json();
  if (order.status === 'expired') {
    expireCheckout();
    return false;
  }
  const amount = formatAmount(order.amountFen);
  document.title = `${order.title} | NewZoe Pay`;
  paymentLabel.textContent = order.title;
  amountElement.textContent = amount;
  footerAmount.textContent = `支付金额 ¥${amount}`;
  orderReference.hidden = false;
  orderReference.textContent = `订单号 ${order.id}`;
  paymentRecipient.textContent = order.payeeDisplayName || order.payee || 'NewZoe';
  if (order.status === 'paid') {
    showOrder();
    paymentReady = false;
    showSuccess(order);
    return false;
  }
  if (order.status === 'confirming') {
    waitForSettlement();
    startCountdown();
    return true;
  }
  paymentReady = order.qrcodeReady !== false;
  if (paymentReady) {
    showOrder();
    paymentQrcode.src = `/wechat-pay.jpg?order=${encodeURIComponent(order.id)}&v=${Date.now()}`;
    paymentQrcode.alt = `${paymentRecipient.textContent} 的微信收款二维码`;
    paymentQrcode.hidden = false;
    startCountdown();
  } else {
    showPlaceholder('暂不可支付', '该订单的收款码尚未配置');
    return false;
  }
  return true;
}

async function start() {
  const loaded = await loadOrder();
  if (!loaded) return;
  const eventsUrl = new URL('/api/events', location.origin);
  eventsUrl.searchParams.set('client', getClientId());
  if (orderId) eventsUrl.searchParams.set('order', orderId);
  events = new EventSource(eventsUrl);

  events.addEventListener('open', () => {
    if (order?.status === 'confirming') {
      waitForSettlement();
    } else if (paymentReady && !statusElement.classList.contains('is-paid')) {
      setStatus('', '等待支付', '到账后本页将自动更新');
    }
  });

  events.addEventListener('payment', (event) => {
    const payment = JSON.parse(event.data);
    if (payment.status === 'paid' && (!order || payment.orderId === order.id)) {
      if (order) order.status = 'paid';
      events.close();
      showSuccess(payment);
    }
  });

  events.addEventListener('status', (event) => {
    const current = JSON.parse(event.data);
    if (current.status === 'paid') {
      if (order) Object.assign(order, current, { status: 'paid' });
      events.close();
      showSuccess(current);
    } else if (current.status === 'inactive' || current.status === 'expired') {
      events.close();
      expireCheckout();
    } else if (current.status === 'confirming') {
      waitForSettlement();
    }
  });

  events.addEventListener('error', () => {
    if (!statusElement.classList.contains('is-paid')) {
      setStatus('offline', '正在重新连接', '支付状态将在连接恢复后更新');
    }
  });
}

closeButton.addEventListener('click', () => dialog.close());
retryButton.addEventListener('click', () => location.reload());
paymentQrcode.addEventListener('error', () => {
  if (paymentReady) showPlaceholder('二维码加载失败', '请稍后重新加载', { retry: true });
});
start().catch(() => showPlaceholder('暂时未连接', '服务恢复后可重新加载', { retry: true }));
