'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const test = require('node:test');

function makeElement(id) {
  const element = {
    id,
    className: '',
    hidden: false,
    open: false,
    src: '',
    textContent: '',
    listeners: new Map(),
    addEventListener(type, handler) {
      this.listeners.set(type, handler);
    },
    removeAttribute(name) {
      if (name === 'src') this.src = '';
    },
    setAttribute(name, value) {
      if (name === 'open') this.open = true;
      this[name] = value;
    },
    classList: {
      contains(name) {
        return element.className.split(/\s+/).includes(name);
      },
      add(name) {
        if (!this.contains(name)) element.className = `${element.className} ${name}`.trim();
      },
      remove(name) {
        element.className = element.className.split(/\s+/).filter((item) => item && item !== name).join(' ');
      }
    }
  };
  return element;
}

test('checkout handles a paid SSE status after reconnect without leaving the QR visible', async () => {
  const ids = [
    'payment-status', 'status-title', 'status-detail', 'success-dialog', 'success-message',
    'close-success', 'payment-amount', 'footer-amount', 'order-reference', 'payment-label',
    'payment-recipient', 'payment-countdown', 'countdown-label', 'countdown-value',
    'payment-qrcode', 'checkout', 'placeholder-copy', 'placeholder-title', 'placeholder-detail',
    'placeholder-animation', 'retry-load', 'order-summary', 'qr-content', 'footer-status'
  ];
  const elements = Object.fromEntries(ids.map((id) => [id, makeElement(id)]));
  elements['success-dialog'].showModal = function showModal() { this.open = true; };
  elements['success-dialog'].close = function close() { this.open = false; };
  const eventSources = [];
  class FakeEventSource {
    constructor(url) {
      this.url = url;
      this.listeners = new Map();
      this.closed = false;
      eventSources.push(this);
    }

    addEventListener(type, handler) {
      this.listeners.set(type, handler);
    }

    close() {
      this.closed = true;
    }
  }
  let nextInterval = 1;
  const clearedIntervals = [];
  const context = {
    console,
    crypto: { randomUUID: () => 'client-1234567890123456' },
    document: {
      title: '',
      querySelector(selector) {
        return elements[selector.slice(1)];
      }
    },
    fetch: async () => ({
      ok: true,
      status: 200,
      async json() {
        const now = Date.now();
        return {
          amountFen: 123,
          createdAt: new Date(now).toISOString(),
          expiresAt: new Date(now + 20 * 60 * 1000).toISOString(),
          id: 'SSEPAIDORDER01',
          matchExpiresAt: new Date(now + 25 * 60 * 1000).toISOString(),
          payee: 'admin',
          payeeDisplayName: '管理员',
          qrcodeReady: true,
          returnUrl: '',
          serverTime: new Date(now).toISOString(),
          status: 'pending',
          title: '测试订单'
        };
      }
    }),
    location: {
      origin: 'https://pay.newzoe.cloud',
      pathname: '/SSEPAIDORDER01'
    },
    sessionStorage: {
      values: new Map(),
      getItem(key) { return this.values.get(key) || null; },
      setItem(key, value) { this.values.set(key, value); }
    },
    setTimeout: () => 1,
    URL,
    EventSource: FakeEventSource,
    window: {
      clearInterval(id) { clearedIntervals.push(id); },
      setInterval() { return nextInterval++; },
      setTimeout: () => 1
    }
  };
  vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'public', 'app.js'), 'utf8'), context, {
    filename: 'public/app.js'
  });
  await new Promise((resolve) => setImmediate(resolve));
  await new Promise((resolve) => setImmediate(resolve));
  assert.equal(eventSources.length, 1);

  eventSources[0].listeners.get('status')({
    data: JSON.stringify({ amountFen: 123, id: 'SSEPAIDORDER01', paidAt: new Date().toISOString(), status: 'paid' })
  });

  assert.equal(eventSources[0].closed, true);
  assert.deepEqual(clearedIntervals, [1]);
  assert.equal(elements['payment-qrcode'].hidden, true);
  assert.equal(elements['payment-qrcode'].src, '');
  assert.equal(elements['qr-content'].hidden, true);
  assert.equal(elements['payment-countdown'].hidden, true);
  assert.equal(elements['status-title'].textContent, '支付成功');
  assert.match(elements['payment-status'].className, /is-paid/);
  assert.equal(elements['success-dialog'].open, true);
});
