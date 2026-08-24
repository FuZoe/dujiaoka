'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const test = require('node:test');

const { createPayServer, signPayload, signSmsForwarder } = require('../server');

const SECRET = 'test-secret-with-more-than-thirty-two-characters';
const SMSF_SECRET = 'smsf-test-secret-with-more-than-thirty-two-characters';
const SHOP_SECRET = 'shop-test-secret-with-more-than-thirty-two-characters';
const NOW = Date.UTC(2026, 7, 11, 8, 0, 0);

async function withServer(run, options = {}) {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'newzoe-pay-'));
  const stateFile = options.stateFile || path.join(directory, 'state.json');
  if (options.initialState) fs.writeFileSync(stateFile, JSON.stringify(options.initialState));
  const { initialState, ...serverOptions } = options;
  const server = createPayServer({
    now: () => NOW,
    secret: SECRET,
    smsfSecret: SMSF_SECRET,
    shopSecret: SHOP_SECRET,
    stateFile,
    // Tests must never use the real shop callback endpoint by default.
    fetchImpl: async () => ({ ok: true, status: 200 }),
    ...serverOptions
  });
  await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
  try {
    await run(`http://127.0.0.1:${server.address().port}`);
  } finally {
    await new Promise((resolve) => server.close(resolve));
    fs.rmSync(directory, { force: true, recursive: true });
  }
}

function smsfForm(fields, timestamp = String(NOW)) {
  return new URLSearchParams({
    ...fields,
    sign: signSmsForwarder(SMSF_SECRET, timestamp),
    timestamp
  });
}

function signedHeaders(body, timestamp = String(NOW / 1000)) {
  return {
    'content-type': 'application/json',
    'x-pay-signature': signPayload(SECRET, timestamp, Buffer.from(body)),
    'x-pay-timestamp': timestamp
  };
}

test('health endpoint exposes service counts without a global expected amount', async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/api/health`);
    assert.equal(response.status, 200);
    assert.deepEqual(await response.json(), { ok: true, orders: 0, users: 1 });
  });
});

test('notification endpoint rejects invalid signatures', async () => {
  await withServer(async (baseUrl) => {
    const body = JSON.stringify({ amountFen: 1, transactionId: 'txn-invalid-signature' });
    const response = await fetch(`${baseUrl}/api/payment/notify`, {
      body,
      headers: {
        'content-type': 'application/json',
        'x-pay-signature': 'bad',
        'x-pay-timestamp': String(NOW / 1000)
      },
      method: 'POST'
    });
    assert.equal(response.status, 401);
    assert.deepEqual(await response.json(), { error: 'invalid_signature' });
  });
});

test('a signed one-fen notification reaches the selected browser session', async () => {
  await withServer(async (baseUrl) => {
    const clientId = 'test-client-1234567890';
    const controller = new AbortController();
    const eventsPromise = fetch(`${baseUrl}/api/events?client=${clientId}`, { signal: controller.signal });
    const eventsResponse = await eventsPromise;
    const reader = eventsResponse.body.getReader();
    const decoder = new TextDecoder();
    let received = decoder.decode((await reader.read()).value);

    const body = JSON.stringify({
      amountFen: 1,
      clientId,
      mode: 'test',
      transactionId: 'txn-test-12345678'
    });
    const notifyResponse = await fetch(`${baseUrl}/api/payment/notify`, {
      body,
      headers: signedHeaders(body),
      method: 'POST'
    });
    assert.equal(notifyResponse.status, 200);
    assert.equal((await notifyResponse.json()).delivered, 1);

    while (!received.includes('event: payment')) {
      received += decoder.decode((await reader.read()).value);
    }
    assert.match(received, /"amountFen":1/);
    assert.match(received, /"status":"paid"/);
    assert.match(received, /"test":true/);
    controller.abort();
  });
});

test('other amounts are accepted without triggering success', async () => {
  await withServer(async (baseUrl) => {
    const body = JSON.stringify({ amountFen: 2, transactionId: 'txn-two-fen-12345' });
    const response = await fetch(`${baseUrl}/api/payment/notify`, {
      body,
      headers: signedHeaders(body),
      method: 'POST'
    });
    assert.equal(response.status, 202);
    assert.deepEqual(await response.json(), { accepted: true, matched: false, reason: 'order_required' });
  });
});

test('payment transaction ids are idempotent globally and reject cross-order reuse', async () => {
  const callbacks = [];
  await withServer(async (baseUrl) => {
    const register = async (orderId, amountFen) => {
      const body = JSON.stringify({
        amountFen,
        callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
        orderId,
        returnUrl: `https://shop.newzoe.cloud/detail-order-sn/${orderId}`,
        title: orderId
      });
      const timestamp = String(NOW);
      const response = await fetch(`${baseUrl}/api/shop/orders`, {
        body,
        headers: {
          'content-type': 'application/json',
          'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(body)),
          'x-shop-timestamp': timestamp
        },
        method: 'POST'
      });
      assert.equal(response.status, 201);
    };
    await register('TXNORDER0001', 100);
    await register('TXNORDER0002', 200);

    const notify = async (orderId, amountFen) => {
      const body = JSON.stringify({ amountFen, orderId, transactionId: 'provider-txn-global-01' });
      const response = await fetch(`${baseUrl}/api/payment/notify`, {
        body,
        headers: signedHeaders(body),
        method: 'POST'
      });
      return { body: await response.json(), status: response.status };
    };

    const first = await notify('TXNORDER0001', 100);
    assert.equal(first.status, 200);
    assert.equal(first.body.duplicate, false);
    const exactReplay = await notify('TXNORDER0001', 100);
    assert.deepEqual(exactReplay, {
      body: { accepted: true, delivered: 0, duplicate: true, matched: true },
      status: 200
    });
    const otherOrder = await notify('TXNORDER0002', 200);
    assert.deepEqual(otherOrder, {
      body: { accepted: false, error: 'transaction_conflict', matched: false },
      status: 409
    });
    const otherAmount = await notify('TXNORDER0001', 101);
    assert.deepEqual(otherAmount, {
      body: { accepted: false, error: 'transaction_conflict', matched: false },
      status: 409
    });
    assert.equal((await (await fetch(`${baseUrl}/api/orders/TXNORDER0002`)).json()).status, 'pending');
    assert.equal(callbacks.length, 1);
  }, {
    fetchImpl: async (url, request) => {
      callbacks.push({ url, request });
      return { ok: true, status: 200 };
    }
  });
});

test('a paid order keeps its transaction id after the compact audit list rotates', async () => {
  await withServer(async (baseUrl) => {
    const orderBody = JSON.stringify({
      amountFen: 200,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'TXNREUSEORDER02',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/TXNREUSEORDER02',
      title: 'new order'
    });
    const registered = await fetch(`${baseUrl}/api/shop/orders`, {
      body: orderBody,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, String(NOW), Buffer.from(orderBody)),
        'x-shop-timestamp': String(NOW)
      },
      method: 'POST'
    });
    assert.equal(registered.status, 201);

    const body = JSON.stringify({
      amountFen: 200,
      orderId: 'TXNREUSEORDER02',
      transactionId: 'old-provider-transaction-01'
    });
    const response = await fetch(`${baseUrl}/api/payment/notify`, {
      body,
      headers: signedHeaders(body),
      method: 'POST'
    });
    assert.deepEqual({ body: await response.json(), status: response.status }, {
      body: { accepted: false, error: 'transaction_conflict', matched: false },
      status: 409
    });
  }, {
    initialState: {
      orders: [{
        amountFen: 100,
        createdAt: new Date(NOW - 60000).toISOString(),
        id: 'TXNREUSEPAID001',
        paidAt: new Date(NOW - 30000).toISOString(),
        payee: 'admin',
        source: 'dujiaoka',
        status: 'paid',
        transactionId: 'old-provider-transaction-01'
      }],
      transactions: [],
      users: []
    }
  });
});

test('SmsForwarder ignores a valid payment notification when no pending order exists', async () => {
  await withServer(async (baseUrl) => {
    const notifyResponse = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: smsfForm({
        content: '微信支付收款到账0.01元',
        from: 'com.tencent.mm',
        title: '微信收款助手'
      }),
      method: 'POST'
    });

    assert.equal(notifyResponse.status, 409);
    assert.deepEqual(await notifyResponse.json(), { accepted: false, error: 'no_pending_order', matched: false });
  });
});

test('SmsForwarder accepts channel tests without creating a payment', async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: smsfForm({ content: '这是一条测试消息', from: 'cn.ppps.forwarder' }),
      method: 'POST'
    });

    assert.equal(response.status, 200);
    assert.deepEqual(await response.json(), { accepted: true, matched: false });
  });
});

test('SmsForwarder reports trusted payment text without an amount', async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: smsfForm({
        content: '微信支付收款到账',
        from: 'com.tencent.mm',
        title: '微信'
      }),
      method: 'POST'
    });

    assert.equal(response.status, 422);
    assert.deepEqual(await response.json(), { accepted: false, error: 'amount_not_found', matched: false });
  });
});

test('checkout closes its QR at 20 minutes while payment success can arrive during the five-minute grace', async () => {
  let clock = NOW;
  const callbacks = [];
  await withServer(async (baseUrl) => {
    const loginResponse = await fetch(`${baseUrl}/api/admin/login`, {
      body: JSON.stringify({ password: 'test-admin-password', username: 'admin' }),
      headers: { 'content-type': 'application/json' },
      method: 'POST'
    });
    const cookie = loginResponse.headers.get('set-cookie').split(';')[0];
    const qrUpload = await fetch(`${baseUrl}/api/admin/qrcode`, {
      body: Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47]), Buffer.alloc(128)]),
      headers: { cookie },
      method: 'POST'
    });
    assert.equal(qrUpload.status, 200);

    const orderBody = JSON.stringify({
      amountFen: 500,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'GRACEORDER500',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/GRACEORDER500',
      title: '到账宽限测试'
    });
    const timestamp = String(clock);
    await fetch(`${baseUrl}/api/shop/orders`, {
      body: orderBody,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(orderBody)),
        'x-shop-timestamp': timestamp
      },
      method: 'POST'
    });
    clock = NOW + 20 * 60 * 1000 - 1;
    const active = await fetch(`${baseUrl}/api/orders/GRACEORDER500`);
    assert.equal((await active.json()).status, 'pending');
    const activeQr = await fetch(`${baseUrl}/wechat-pay.jpg?order=GRACEORDER500`);
    assert.equal(activeQr.status, 200);
    assert.equal(activeQr.headers.get('cache-control'), 'no-store');

    clock = NOW + 20 * 60 * 1000;
    const expired = await fetch(`${baseUrl}/api/orders/GRACEORDER500`);
    const expiredOrder = await expired.json();
    assert.equal(expiredOrder.status, 'confirming');
    assert.equal(expiredOrder.qrcodeReady, false);
    assert.equal((await fetch(`${baseUrl}/wechat-pay.jpg?order=GRACEORDER500`)).status, 410);
    const controller = new AbortController();
    const graceEvents = await fetch(`${baseUrl}/api/events?client=expiredclient123456&order=GRACEORDER500`, {
      signal: controller.signal
    });
    const reader = graceEvents.body.getReader();
    const decoder = new TextDecoder();
    let received = decoder.decode((await reader.read()).value);
    assert.match(received, /"status":"confirming"/);

    clock = NOW + 25 * 60 * 1000 - 1;
    const response = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: smsfForm({ content: '微信支付收款到账5.00元', from: 'com.tencent.mm', title: '微信支付' }, String(clock)),
      method: 'POST'
    });
    assert.equal(response.status, 200);
    const matched = await response.json();
    assert.equal(matched.orderId, 'GRACEORDER500');
    assert.equal(matched.delivered, 1);
    const paidOrder = await (await fetch(`${baseUrl}/api/orders/GRACEORDER500`)).json();
    assert.equal(paidOrder.status, 'paid');
    assert.equal(paidOrder.qrcodeReady, false);
    const paidQr = await fetch(`${baseUrl}/wechat-pay.jpg?order=GRACEORDER500`);
    assert.deepEqual([paidQr.status, await paidQr.json()], [410, { error: 'order_paid' }]);
    while (!received.includes('event: payment')) {
      received += decoder.decode((await reader.read()).value);
    }
    assert.match(received, /"status":"paid"/);
    controller.abort();
    assert.equal(callbacks.length, 1);
  }, {
    adminPassword: 'test-admin-password',
    fetchImpl: async (url, request) => {
      callbacks.push({ request, url });
      return { ok: true, status: 200 };
    },
    now: () => clock,
    sessionSecret: 'test-session-secret-with-more-than-thirty-two-characters'
  });
});

test('absolute checkout deadline survives refresh and restart without reactivation', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'newzoe-pay-restart-'));
  const stateFile = path.join(directory, 'state.json');
  let clock = NOW;
  let server;
  const start = async () => {
    server = createPayServer({
      fetchImpl: async () => ({ ok: true, status: 200 }),
      now: () => clock,
      secret: SECRET,
      smsfSecret: SMSF_SECRET,
      shopSecret: SHOP_SECRET,
      stateFile
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    return `http://127.0.0.1:${server.address().port}`;
  };
  const stop = async () => {
    if (server) await new Promise((resolve) => server.close(resolve));
    server = null;
  };

  try {
    let baseUrl = await start();
    const orderBody = JSON.stringify({
      amountFen: 600,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'RESTARTACTIVE01',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/RESTARTACTIVE01',
      title: '重启激活时间测试'
    });
    const createTimestamp = String(clock);
    const created = await fetch(`${baseUrl}/api/shop/orders`, {
      body: orderBody,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, createTimestamp, Buffer.from(orderBody)),
        'x-shop-timestamp': createTimestamp
      },
      method: 'POST'
    });
    assert.equal(created.status, 201);

    const createdOrder = await created.json();
    assert.equal(createdOrder.order.expiresAt, new Date(NOW + 20 * 60 * 1000).toISOString());
    assert.equal(createdOrder.order.matchExpiresAt, new Date(NOW + 25 * 60 * 1000).toISOString());

    clock = NOW + 20 * 60 * 1000;
    const opened = await fetch(`${baseUrl}/api/orders/RESTARTACTIVE01`);
    assert.equal(opened.status, 200);
    assert.equal((await opened.json()).status, 'confirming');
    await stop();
    const persisted = JSON.parse(fs.readFileSync(stateFile, 'utf8'));
    assert.equal(persisted.orders[0].activatedAt, new Date(NOW).toISOString());
    assert.equal(persisted.orders[0].expiresAt, new Date(NOW + 20 * 60 * 1000).toISOString());

    baseUrl = await start();
    assert.equal((await (await fetch(`${baseUrl}/api/orders/RESTARTACTIVE01`)).json()).status, 'confirming');

    const retryTimestamp = String(clock);
    const retried = await fetch(`${baseUrl}/api/shop/orders`, {
      body: orderBody,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, retryTimestamp, Buffer.from(orderBody)),
        'x-shop-timestamp': retryTimestamp
      },
      method: 'POST'
    });
    assert.equal(retried.status, 410);

    clock = NOW + 25 * 60 * 1000;
    const late = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: smsfForm({ content: '微信支付收款到账6.00元', from: 'com.tencent.mm', title: '微信支付' }, String(clock)),
      method: 'POST'
    });
    assert.equal(late.status, 409);
    assert.deepEqual(await late.json(), { accepted: false, error: 'no_pending_order', matched: false });
    assert.equal((await (await fetch(`${baseUrl}/api/orders/RESTARTACTIVE01`)).json()).status, 'expired');
    const expiredEvents = await fetch(`${baseUrl}/api/events?client=finalexpired12345&order=RESTARTACTIVE01`);
    assert.match(await expiredEvents.text(), /"status":"expired"/);

    const explicitBody = JSON.stringify({ amountFen: 600, orderId: 'RESTARTACTIVE01', transactionId: 'late-txn-12345678' });
    const explicit = await fetch(`${baseUrl}/api/payment/notify`, {
      body: explicitBody,
      headers: signedHeaders(explicitBody, String(clock / 1000)),
      method: 'POST'
    });
    assert.equal(explicit.status, 410);
  } finally {
    await stop();
    fs.rmSync(directory, { force: true, recursive: true });
  }
});

test('legacy pending orders derive deadlines from their original timestamp', async () => {
  await withServer(async (baseUrl) => {
    const order = await fetch(`${baseUrl}/api/orders/LEGACYEXPIRED01`);
    assert.equal(order.status, 200);
    const publicOrder = await order.json();
    assert.equal(publicOrder.status, 'expired');
    assert.equal(publicOrder.expiresAt, new Date(NOW - 6 * 60 * 1000).toISOString());

    const body = JSON.stringify({
      amountFen: 700,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'LEGACYEXPIRED01',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/LEGACYEXPIRED01',
      title: '旧订单刷新测试'
    });
    const timestamp = String(NOW);
    const retried = await fetch(`${baseUrl}/api/shop/orders`, {
      body,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(body)),
        'x-shop-timestamp': timestamp
      },
      method: 'POST'
    });
    assert.equal(retried.status, 410);
  }, {
    initialState: {
      orders: [{
        amountFen: 700,
        createdAt: new Date(NOW - 26 * 60 * 1000).toISOString(),
        id: 'LEGACYEXPIRED01',
        payee: 'admin',
        source: 'dujiaoka',
        status: 'pending',
        title: '旧订单'
      }],
      transactions: [],
      users: []
    }
  });
});

test('same-price orders receive unique payable amounts and match independently', async () => {
  let clock = NOW;
  const callbacks = [];
  await withServer(async (baseUrl) => {
    const create = async (orderId) => {
      const body = JSON.stringify({
        amountFen: 100,
        callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
        orderId,
        returnUrl: `https://shop.newzoe.cloud/detail-order-sn/${orderId}`,
        title: orderId
      });
      const timestamp = String(clock);
      const response = await fetch(`${baseUrl}/api/shop/orders`, {
        body,
        headers: {
          'content-type': 'application/json',
          'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(body)),
          'x-shop-timestamp': timestamp
        },
        method: 'POST'
      });
      const created = await response.json();
      await fetch(`${baseUrl}/api/orders/${orderId}`);
      clock += 1000;
      return created.order;
    };
    const firstOrder = await create('UNIQUEORDER001');
    const secondOrder = await create('UNIQUEORDER002');
    assert.equal(firstOrder.baseAmountFen, 100);
    assert.equal(firstOrder.amountFen, 100);
    assert.equal(secondOrder.baseAmountFen, 100);
    assert.equal(secondOrder.amountFen, 101);

    const repeatBody = JSON.stringify({
      amountFen: 100,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'UNIQUEORDER002',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/UNIQUEORDER002',
      title: 'UNIQUEORDER002'
    });
    const repeatTimestamp = String(clock);
    const repeat = await fetch(`${baseUrl}/api/shop/orders`, {
      body: repeatBody,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, repeatTimestamp, Buffer.from(repeatBody)),
        'x-shop-timestamp': repeatTimestamp
      },
      method: 'POST'
    });
    assert.equal(repeat.status, 200);
    assert.equal((await repeat.json()).order.amountFen, 101);

    const secondPayment = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: smsfForm({ content: '微信支付收款到账1.01元，唯一金额第二笔', from: 'com.tencent.mm', title: '微信支付' }, String(clock)),
      method: 'POST'
    });
    assert.equal((await secondPayment.json()).orderId, 'UNIQUEORDER002');

    clock += 1000;
    const firstPayment = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: smsfForm({ content: '微信支付收款到账1.00元，唯一金额第一笔', from: 'com.tencent.mm', title: '微信支付' }, String(clock)),
      method: 'POST'
    });
    assert.equal((await firstPayment.json()).orderId, 'UNIQUEORDER001');

    assert.equal(callbacks.length, 2);
    const secondCallback = JSON.parse(callbacks[0].request.body);
    assert.equal(secondCallback.amountFen, 100);
    assert.equal(secondCallback.paidAmountFen, 101);
    const firstCallback = JSON.parse(callbacks[1].request.body);
    assert.equal(firstCallback.amountFen, 100);
    assert.equal(firstCallback.paidAmountFen, 100);

    clock += 1000;
    const heldOrder = await create('UNIQUEORDER003');
    assert.equal(heldOrder.amountFen, 102);

    clock = NOW + 24 * 60 * 1000 + 58 * 1000;
    const beforeRelease = await create('UNIQUEORDER004');
    assert.equal(beforeRelease.amountFen, 103);

    clock = NOW + 25 * 60 * 1000 + 1;
    const reusedOrder = await create('UNIQUEORDER005');
    assert.equal(reusedOrder.amountFen, 100);

    clock = Date.parse(secondOrder.matchExpiresAt) + 1;
    const nextReusedOrder = await create('UNIQUEORDER006');
    assert.equal(nextReusedOrder.amountFen, 101);
  }, {
    fetchImpl: async (url, request) => {
      callbacks.push({ request, url });
      return { ok: true, status: 200 };
    },
    now: () => clock
  });
});

test('concurrent same-price registrations allocate distinct cents atomically', async () => {
  await withServer(async (baseUrl) => {
    const create = async (index) => {
      const body = JSON.stringify({
        amountFen: 500,
        callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
        orderId: `CONCURRENT${String(index).padStart(3, '0')}`,
        returnUrl: `https://shop.newzoe.cloud/detail-order-sn/CONCURRENT${String(index).padStart(3, '0')}`,
        title: `并发订单 ${index}`
      });
      const timestamp = String(NOW);
      const response = await fetch(`${baseUrl}/api/shop/orders`, {
        body,
        headers: {
          'content-type': 'application/json',
          'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(body)),
          'x-shop-timestamp': timestamp
        },
        method: 'POST'
      });
      assert.equal(response.status, 201);
      return (await response.json()).order.amountFen;
    };

    const amounts = await Promise.all(Array.from({ length: 8 }, (_, index) => create(index)));
    assert.deepEqual([...amounts].sort((a, b) => a - b), [500, 501, 502, 503, 504, 505, 506, 507]);
  });
});

test('a recently settled disabled order still reserves its paid amount', async () => {
  await withServer(async (baseUrl) => {
    const body = JSON.stringify({
      amountFen: 250,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'AFTERDISABLED01',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/AFTERDISABLED01',
      title: '隔离订单后的同价订单'
    });
    const timestamp = String(NOW);
    const response = await fetch(`${baseUrl}/api/shop/orders`, {
      body,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(body)),
        'x-shop-timestamp': timestamp
      },
      method: 'POST'
    });
    assert.equal(response.status, 201);
    assert.equal((await response.json()).order.amountFen, 251);
  }, {
    initialState: {
      orders: [{
        amountFen: 250,
        autoMatchDisabledAt: new Date(NOW - 1000).toISOString(),
        baseAmountFen: 250,
        createdAt: new Date(NOW - 60000).toISOString(),
        id: 'DISABLEDPAID01',
        paidAt: new Date(NOW - 1000).toISOString(),
        payee: 'admin',
        settledAt: new Date(NOW - 1000).toISOString(),
        source: 'dujiaoka',
        status: 'paid'
      }],
      transactions: [],
      users: []
    }
  });
});

test('a recently disabled pending order keeps its amount quarantined', async () => {
  await withServer(async (baseUrl) => {
    const body = JSON.stringify({
      amountFen: 350,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'AFTERINACTIVE01',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/AFTERINACTIVE01',
      title: '失效订单后的同价订单'
    });
    const timestamp = String(NOW);
    const response = await fetch(`${baseUrl}/api/shop/orders`, {
      body,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(body)),
        'x-shop-timestamp': timestamp
      },
      method: 'POST'
    });
    assert.equal(response.status, 201);
    assert.equal((await response.json()).order.amountFen, 351);
  }, {
    initialState: {
      orders: [{
        activatedAt: new Date(NOW - 60000).toISOString(),
        amountFen: 350,
        autoMatchDisabledAt: new Date(NOW - 1000).toISOString(),
        baseAmountFen: 350,
        createdAt: new Date(NOW - 60000).toISOString(),
        id: 'INACTIVEPENDING',
        payee: 'admin',
        source: 'dujiaoka',
        status: 'pending'
      }],
      transactions: [],
      users: []
    }
  });
});

test('a disabled pending order stays closed until signed shop re-registration reactivates it with a unique amount', async () => {
  await withServer(async (baseUrl) => {
    const closed = await fetch(`${baseUrl}/api/orders/DISABLEDORDER01`);
    assert.equal(closed.status, 410);
    assert.deepEqual(await closed.json(), { error: 'order_inactive' });

    const qr = await fetch(`${baseUrl}/wechat-pay.jpg?order=DISABLEDORDER01`);
    assert.equal(qr.status, 410);
    assert.deepEqual(await qr.json(), { error: 'order_inactive' });

    const events = await fetch(`${baseUrl}/api/events?client=inactiveclient123456&order=DISABLEDORDER01`);
    assert.equal(events.status, 200);
    const eventBody = await events.text();
    assert.match(eventBody, /event: status/);
    assert.match(eventBody, /"status":"inactive"/);

    const body = JSON.stringify({
      amountFen: 500,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'DISABLEDORDER01',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/DISABLEDORDER01',
      title: '重新激活订单'
    });
    const timestamp = String(NOW);
    const registered = await fetch(`${baseUrl}/api/shop/orders`, {
      body,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(body)),
        'x-shop-timestamp': timestamp
      },
      method: 'POST'
    });
    assert.equal(registered.status, 200);
    const reactivated = (await registered.json()).order;
    assert.equal(reactivated.baseAmountFen, 500);
    assert.equal(reactivated.amountFen, 501);

    const opened = await fetch(`${baseUrl}/api/orders/DISABLEDORDER01`);
    assert.equal(opened.status, 200);
    assert.equal((await opened.json()).status, 'pending');

    const paid = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: smsfForm({ content: '微信支付收款到账5.01元', from: 'com.tencent.mm', title: '微信支付' }),
      method: 'POST'
    });
    assert.equal(paid.status, 200);
    assert.equal((await paid.json()).orderId, 'DISABLEDORDER01');
  }, {
    fetchImpl: async () => ({ ok: true, status: 200 }),
    initialState: {
      orders: [
        {
          activatedAt: new Date(NOW - 60000).toISOString(),
          amountFen: 500,
          baseAmountFen: 500,
          createdAt: new Date(NOW - 60000).toISOString(),
          id: 'ACTIVEPENDING01',
          payee: 'admin',
          source: 'dujiaoka',
          status: 'pending'
        },
        {
          activatedAt: new Date(NOW - 120000).toISOString(),
          amountFen: 500,
          autoMatchDisabledAt: new Date(NOW - 30000).toISOString(),
          baseAmountFen: 500,
          createdAt: new Date(NOW - 120000).toISOString(),
          id: 'DISABLEDORDER01',
          payee: 'admin',
          source: 'dujiaoka',
          status: 'pending'
        }
      ],
      transactions: [],
      users: []
    }
  });
});

test('startup migration quarantines a legacy pending order colliding with a paid amount', async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/api/orders/LEGACYPENDING01`);
    assert.equal(response.status, 410);
    assert.deepEqual(await response.json(), { error: 'order_inactive' });
  }, {
    initialState: {
      orders: [
        {
          amountFen: 13500,
          baseAmountFen: 13500,
          createdAt: new Date(NOW - 180000).toISOString(),
          id: 'LEGACYPAID0001',
          paidAt: new Date(NOW - 60000).toISOString(),
          payee: 'admin',
          settledAt: new Date(NOW - 60000).toISOString(),
          source: 'dujiaoka',
          status: 'paid'
        },
        {
          activatedAt: new Date(NOW - 120000).toISOString(),
          amountFen: 13500,
          baseAmountFen: 13500,
          createdAt: new Date(NOW - 120000).toISOString(),
          id: 'LEGACYPENDING01',
          payee: 'admin',
          source: 'dujiaoka',
          status: 'pending'
        }
      ],
      transactions: [],
      users: []
    }
  });
});

test('SSE reconnect reports an already-paid order even after its matching deadline', async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/api/events?client=paidreconnect123456&order=PAIDSSEORDER0001`);
    assert.equal(response.status, 200);
    const body = await response.text();
    assert.match(body, /event: status/);
    assert.match(body, /"status":"paid"/);
    assert.doesNotMatch(body, /"status":"expired"/);
  }, {
    initialState: {
      orders: [{
        amountFen: 500,
        createdAt: new Date(NOW - 30 * 60 * 1000).toISOString(),
        expiresAt: new Date(NOW - 10 * 60 * 1000).toISOString(),
        id: 'PAIDSSEORDER0001',
        matchExpiresAt: new Date(NOW - 5 * 60 * 1000).toISOString(),
        paidAt: new Date(NOW - 6 * 60 * 1000).toISOString(),
        payee: 'admin',
        source: 'dujiaoka',
        status: 'paid',
        transactionId: 'paid-sse-transaction-01'
      }],
      transactions: [],
      users: []
    }
  });
});

test('startup migration keeps the earliest active pending amount and quarantines later collisions', async () => {
  await withServer(async (baseUrl) => {
    const earliest = await fetch(`${baseUrl}/api/orders/LEGACYEARLY01`);
    assert.equal(earliest.status, 200);
    assert.equal((await earliest.json()).amountFen, 500);

    const later = await fetch(`${baseUrl}/api/orders/LEGACYLATER01`);
    assert.equal(later.status, 410);
    assert.deepEqual(await later.json(), { error: 'order_inactive' });

    const expired = await fetch(`${baseUrl}/api/orders/LEGACYEXPIRED01`);
    assert.equal(expired.status, 200);
    const expiredOrder = await expired.json();
    assert.equal(expiredOrder.status, 'expired');
    assert.equal(expiredOrder.amountFen, 500);
  }, {
    initialState: {
      orders: [
        {
          amountFen: 500,
          createdAt: new Date(NOW - 60000).toISOString(),
          id: 'LEGACYLATER01',
          source: 'manual',
          status: 'pending',
          title: 'later',
          payee: 'admin'
        },
        {
          amountFen: 500,
          createdAt: new Date(NOW - 120000).toISOString(),
          id: 'LEGACYEARLY01',
          source: 'manual',
          status: 'pending',
          title: 'early',
          payee: 'admin'
        },
        {
          amountFen: 500,
          createdAt: new Date(NOW - 30 * 60 * 1000).toISOString(),
          id: 'LEGACYEXPIRED01',
          source: 'manual',
          status: 'pending',
          title: 'expired',
          payee: 'admin'
        }
      ],
      transactions: [],
      users: []
    }
  });
});

test('an exact unmatched notification replay stays rejected while a new timestamp can match a new payment', async () => {
  let clock = NOW;
  await withServer(async (baseUrl) => {
    const fields = { content: '微信支付收款到账8.88元，今日第1笔', from: 'com.tencent.mm', title: '微信支付' };
    const originalForm = smsfForm(fields, String(clock));
    const first = await fetch(`${baseUrl}/api/smsf/notify`, { body: originalForm, method: 'POST' });
    assert.equal(first.status, 409);

    clock += 60 * 1000;
    const body = JSON.stringify({
      amountFen: 888,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'LATERORDER1234',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/LATERORDER1234',
      title: '后来创建的订单'
    });
    const timestamp = String(clock);
    await fetch(`${baseUrl}/api/shop/orders`, {
      body,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(body)),
        'x-shop-timestamp': timestamp
      },
      method: 'POST'
    });
    await fetch(`${baseUrl}/api/orders/LATERORDER1234`);

    const retry = await fetch(`${baseUrl}/api/smsf/notify`, { body: originalForm, method: 'POST' });
    assert.equal(retry.status, 409);
    assert.equal((await (await fetch(`${baseUrl}/api/orders/LATERORDER1234`)).json()).status, 'pending');

    clock = NOW + 26 * 60 * 1000 + 1;
    const newOrderBody = JSON.stringify({
      amountFen: 888,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'LATERORDER5678',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/LATERORDER5678',
      title: '新的同额订单'
    });
    const newTimestamp = String(clock);
    const registered = await fetch(`${baseUrl}/api/shop/orders`, {
      body: newOrderBody,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, newTimestamp, Buffer.from(newOrderBody)),
        'x-shop-timestamp': newTimestamp
      },
      method: 'POST'
    });
    assert.equal(registered.status, 201);
    const newPayment = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: smsfForm(fields, String(clock)),
      method: 'POST'
    });
    assert.equal(newPayment.status, 200);
    assert.equal((await newPayment.json()).orderId, 'LATERORDER5678');
  }, {
    fetchImpl: async () => ({ ok: true, status: 200 }),
    now: () => clock
  });
});

test('SmsForwarder persists notification ids across restart and honors receive_time', async () => {
  let clock = NOW;
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'newzoe-pay-smsf-restart-'));
  const stateFile = path.join(directory, 'state.json');
  const callbacks = [];
  let server;
  let baseUrl;
  const start = async () => {
    server = createPayServer({
      now: () => clock,
      secret: SECRET,
      smsfSecret: SMSF_SECRET,
      shopSecret: SHOP_SECRET,
      stateFile,
      fetchImpl: async (url, request) => {
        callbacks.push({ request, url });
        return { ok: true, status: 200 };
      }
    });
    await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
    baseUrl = `http://127.0.0.1:${server.address().port}`;
  };
  const stop = async () => new Promise((resolve) => server.close(resolve));
  const register = async (orderId) => {
    const body = JSON.stringify({
      amountFen: 123,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId,
      returnUrl: `https://shop.newzoe.cloud/detail-order-sn/${orderId}`,
      title: orderId
    });
    const timestamp = String(clock);
    const response = await fetch(`${baseUrl}/api/shop/orders`, {
      body,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(body)),
        'x-shop-timestamp': timestamp
      },
      method: 'POST'
    });
    assert.equal(response.status, 201);
  };

  try {
    await start();
    await register('RESTARTSMSF01');
    const fields = {
      content: '微信支付收款到账1.23元',
      from: 'com.tencent.mm',
      receive_time: String(clock),
      title: '微信支付'
    };
    const original = smsfForm(fields, String(clock));
    const first = await fetch(`${baseUrl}/api/smsf/notify`, { body: original, method: 'POST' });
    assert.equal(first.status, 200);
    assert.equal((await first.json()).duplicate, false);
    await stop();

    await start();
    const replay = await fetch(`${baseUrl}/api/smsf/notify`, { body: original, method: 'POST' });
    assert.deepEqual([replay.status, await replay.json()], [200, { accepted: true, duplicate: true, matched: true }]);

    clock = NOW + 25 * 60 * 1000 + 1;
    await register('RESTARTSMSF02');
    const staleSource = smsfForm({ ...fields, receive_time: String(clock - 60 * 1000) }, String(clock));
    const stale = await fetch(`${baseUrl}/api/smsf/notify`, { body: staleSource, method: 'POST' });
    assert.equal(stale.status, 409);
    assert.deepEqual(await stale.json(), { accepted: false, error: 'no_pending_order', matched: false });

    const fresh = smsfForm({ ...fields, receive_time: String(clock) }, String(clock));
    const second = await fetch(`${baseUrl}/api/smsf/notify`, { body: fresh, method: 'POST' });
    assert.equal(second.status, 200);
    assert.equal((await second.json()).orderId, 'RESTARTSMSF02');
    assert.equal(callbacks.length, 2);
  } finally {
    if (server?.listening) await stop();
    fs.rmSync(directory, { force: true, recursive: true });
  }
});

test('SmsForwarder deduplicates the same payment sent by overlapping rules', async () => {
  const callbacks = [];
  await withServer(async (baseUrl) => {
    const orderBody = JSON.stringify({
      amountFen: 1,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'DEDUPORDER123',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/DEDUPORDER123',
      title: '重复通知测试'
    });
    const orderTimestamp = String(NOW);
    const orderResponse = await fetch(`${baseUrl}/api/shop/orders`, {
      body: orderBody,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, orderTimestamp, Buffer.from(orderBody)),
        'x-shop-timestamp': orderTimestamp
      },
      method: 'POST'
    });
    assert.equal(orderResponse.status, 201);
    await fetch(`${baseUrl}/api/orders/DEDUPORDER123`);

    const fields = {
      content: '微信支付收款到账0.01元',
      from: 'com.tencent.mm',
      title: '微信支付'
    };
    const firstTimestamp = String(NOW);
    const secondTimestamp = String(NOW + 25);

    const firstResponse = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: smsfForm(fields, firstTimestamp),
      method: 'POST'
    });
    const secondResponse = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: smsfForm(fields, secondTimestamp),
      method: 'POST'
    });

    assert.equal(firstResponse.status, 200);
    assert.equal((await firstResponse.json()).matched, true);
    assert.equal(secondResponse.status, 200);
    assert.deepEqual(await secondResponse.json(), {
      accepted: true,
      duplicate: true,
      matched: true
    });
    assert.equal(callbacks.length, 1);
  }, {
    fetchImpl: async (url, request) => {
      callbacks.push({ request, url });
      return { ok: true, status: 200 };
    }
  });
});

test('a registered shop order is activated, paid, and sent to the shop callback', async () => {
  const callbacks = [];
  await withServer(async (baseUrl) => {
    const orderBody = JSON.stringify({
      amountFen: 1,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'ORDER12345678',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/ORDER12345678',
      title: '测试商品 x 1'
    });
    const orderTimestamp = String(NOW);
    const registerResponse = await fetch(`${baseUrl}/api/shop/orders`, {
      body: orderBody,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, orderTimestamp, Buffer.from(orderBody)),
        'x-shop-timestamp': orderTimestamp
      },
      method: 'POST'
    });
    assert.equal(registerResponse.status, 201);

    const publicResponse = await fetch(`${baseUrl}/api/orders/ORDER12345678`);
    assert.equal(publicResponse.status, 200);
    assert.equal((await publicResponse.json()).status, 'pending');

    const paidResponse = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: smsfForm({
        content: '微信支付收款到账0.01元',
        from: 'com.tencent.mm',
        title: '微信支付'
      }),
      method: 'POST'
    });
    const paid = await paidResponse.json();
    assert.equal(paid.matched, true);
    assert.equal(paid.orderId, 'ORDER12345678');

    const completedResponse = await fetch(`${baseUrl}/api/orders/ORDER12345678`);
    assert.equal((await completedResponse.json()).status, 'paid');
    assert.equal(callbacks.length, 1);
    const callback = JSON.parse(callbacks[0].request.body);
    assert.equal(callback.manualOverride, undefined);
    assert.equal(callback.matchedAt, new Date(NOW).toISOString());
  }, {
    fetchImpl: async (url, request) => {
      callbacks.push({ request, url });
      return { ok: true, status: 200 };
    }
  });
});

test('admin can sign in and create a custom amount order', async () => {
  await withServer(async (baseUrl) => {
    const loginResponse = await fetch(`${baseUrl}/api/admin/login`, {
      body: JSON.stringify({ password: 'test-admin-password', username: 'admin' }),
      headers: { 'content-type': 'application/json' },
      method: 'POST'
    });
    assert.equal(loginResponse.status, 200);
    const cookie = loginResponse.headers.get('set-cookie').split(';')[0];

    const qrResponse = await fetch(`${baseUrl}/api/admin/qrcode`, {
      body: Buffer.concat([Buffer.from([0x89, 0x50, 0x4e, 0x47]), Buffer.alloc(128)]),
      headers: { cookie },
      method: 'POST'
    });
    assert.equal(qrResponse.status, 200);

    const createResponse = await fetch(`${baseUrl}/api/admin/orders`, {
      body: JSON.stringify({ amount: '12.34', expiryMinutes: '60', title: '线下补款' }),
      headers: { 'content-type': 'application/json', cookie },
      method: 'POST'
    });
    assert.equal(createResponse.status, 201);
    const created = await createResponse.json();
    assert.equal(created.order.amountFen, 1234);
    assert.equal(created.order.baseAmountFen, 1234);
    assert.equal(created.order.expiresAt, new Date(NOW + 60 * 60 * 1000).toISOString());
    assert.equal(created.order.matchExpiresAt, new Date(NOW + 65 * 60 * 1000).toISOString());
    assert.match(created.paymentUrl, /^https:\/\/pay\.newzoe\.cloud\/M/);

    for (const expiryMinutes of ['0', '-1', '1.5', 'invalid', '1441']) {
      const invalidExpiry = await fetch(`${baseUrl}/api/admin/orders`, {
        body: JSON.stringify({ amount: '1.00', expiryMinutes, title: '无效有效期' }),
        headers: { 'content-type': 'application/json', cookie },
        method: 'POST'
      });
      assert.equal(invalidExpiry.status, 400);
      assert.deepEqual(await invalidExpiry.json(), { error: 'invalid_expiry' });
    }

    const secondCreateResponse = await fetch(`${baseUrl}/api/admin/orders`, {
      body: JSON.stringify({ amount: '12.34', title: '同价线下补款' }),
      headers: { 'content-type': 'application/json', cookie },
      method: 'POST'
    });
    assert.equal(secondCreateResponse.status, 201);
    const secondCreated = await secondCreateResponse.json();
    assert.equal(secondCreated.order.baseAmountFen, 1234);
    assert.equal(secondCreated.order.amountFen, 1235);
    assert.equal(secondCreated.order.expiresAt, new Date(NOW + 20 * 60 * 1000).toISOString());
    assert.equal(secondCreated.order.matchExpiresAt, new Date(NOW + 25 * 60 * 1000).toISOString());

    const ordersResponse = await fetch(`${baseUrl}/api/admin/orders`, { headers: { cookie } });
    const orders = (await ordersResponse.json()).orders;
    assert.equal(orders.length, 2);
    assert.equal(orders[0].status, 'pending');
  }, {
    adminPassword: 'test-admin-password',
    sessionSecret: 'test-session-secret-with-more-than-thirty-two-characters'
  });
});

test('admin manual payment can trigger shop fulfillment or permanently suppress it', async () => {
  const callbacks = [];
  await withServer(async (baseUrl) => {
    const loginResponse = await fetch(`${baseUrl}/api/admin/login`, {
      body: JSON.stringify({ password: 'test-admin-password', username: 'admin' }),
      headers: { 'content-type': 'application/json' },
      method: 'POST'
    });
    const cookie = loginResponse.headers.get('set-cookie').split(';')[0];

    const createShopOrder = async (orderId) => {
      const body = JSON.stringify({
        amountFen: 700,
        callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
        orderId,
        returnUrl: `https://shop.newzoe.cloud/detail-order-sn/${orderId}`,
        title: orderId
      });
      const timestamp = String(NOW);
      const response = await fetch(`${baseUrl}/api/shop/orders`, {
        body,
        headers: {
          'content-type': 'application/json',
          'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(body)),
          'x-shop-timestamp': timestamp
        },
        method: 'POST'
      });
      assert.equal(response.status, 201);
      return (await response.json()).order;
    };

    const suppressedOrder = await createShopOrder('MANUALSKIP001');
    assert.equal(suppressedOrder.amountFen, 700);
    const suppressedResponse = await fetch(`${baseUrl}/api/admin/orders/MANUALSKIP001/mark-paid`, {
      body: JSON.stringify({ triggerShopFulfillment: false }),
      headers: { 'content-type': 'application/json', cookie },
      method: 'POST'
    });
    assert.equal(suppressedResponse.status, 200);
    const suppressed = await suppressedResponse.json();
    assert.equal(suppressed.order.status, 'paid');
    assert.equal(suppressed.order.callbackStatus, 'manual_fulfilled');
    assert.equal(suppressed.order.manualPaidBy, 'admin');
    assert.equal(suppressed.order.paymentMethod, 'manual_admin');
    assert.equal(suppressed.shopFulfillmentTriggered, false);
    assert.equal(callbacks.length, 0);

    const duplicateResponse = await fetch(`${baseUrl}/api/admin/orders/MANUALSKIP001/mark-paid`, {
      body: JSON.stringify({ triggerShopFulfillment: true }),
      headers: { 'content-type': 'application/json', cookie },
      method: 'POST'
    });
    assert.equal(duplicateResponse.status, 200);
    assert.equal((await duplicateResponse.json()).duplicate, true);
    assert.equal(callbacks.length, 0);

    const fulfilledOrder = await createShopOrder('MANUALSHIP002');
    assert.equal(fulfilledOrder.baseAmountFen, 700);
    assert.equal(fulfilledOrder.amountFen, 701);
    const fulfilledResponse = await fetch(`${baseUrl}/api/admin/orders/MANUALSHIP002/mark-paid`, {
      body: JSON.stringify({ triggerShopFulfillment: true }),
      headers: { 'content-type': 'application/json', cookie },
      method: 'POST'
    });
    assert.equal(fulfilledResponse.status, 200);
    const fulfilled = await fulfilledResponse.json();
    assert.equal(fulfilled.order.status, 'paid');
    assert.equal(fulfilled.order.callbackStatus, 'success');
    assert.equal(fulfilled.shopFulfillmentTriggered, true);
    assert.equal(callbacks.length, 1);
    const callback = JSON.parse(callbacks[0].request.body);
    assert.equal(callback.amountFen, 700);
    assert.equal(callback.manualOverride, true);
    assert.equal(callback.matchedAt, new Date(NOW).toISOString());
    assert.equal(callback.paidAmountFen, 701);
    assert.equal(callback.orderId, 'MANUALSHIP002');
  }, {
    adminPassword: 'test-admin-password',
    fetchImpl: async (url, request) => {
      callbacks.push({ request, url });
      return { ok: true, status: 200 };
    },
    sessionSecret: 'test-session-secret-with-more-than-thirty-two-characters'
  });
});

test('Alipay shop orders are materialized for manual settlement in the pay admin', async () => {
  const callbacks = [];
  const shopOrder = {
    amountFen: 901,
    callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
    createdAt: new Date(NOW).toISOString(),
    expiresAt: new Date(NOW + 20 * 60 * 1000).toISOString(),
    id: 'ALIPAYMANUAL01',
    manualSuppressUrl: 'https://shop.newzoe.cloud/api/newzoe/manual-suppress',
    matchExpiresAt: new Date(NOW + 25 * 60 * 1000).toISOString(),
    paymentMethod: 'aliweb',
    paymentName: '支付宝',
    returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/ALIPAYMANUAL01',
    source: 'dujiaoka',
    status: 1,
    title: '支付宝订单'
  };
  await withServer(async (baseUrl) => {
    const loginResponse = await fetch(`${baseUrl}/api/admin/login`, {
      body: JSON.stringify({ password: 'test-admin-password', username: 'admin' }),
      headers: { 'content-type': 'application/json' },
      method: 'POST'
    });
    const cookie = loginResponse.headers.get('set-cookie').split(';')[0];

    const listedResponse = await fetch(`${baseUrl}/api/admin/orders`, { headers: { cookie } });
    assert.equal(listedResponse.status, 200);
    const listed = (await listedResponse.json()).orders.find((order) => order.id === shopOrder.id);
    assert.equal(listed.payment.paymentMethod, 'alipay');
    assert.equal(listed.payment.manualOnly, true);
    assert.equal(listed.status, 'pending');

    const notifyBody = JSON.stringify({ amountFen: 901, orderId: shopOrder.id, transactionId: 'provider-alipay-01' });
    const notifyResponse = await fetch(`${baseUrl}/api/payment/notify`, {
      body: notifyBody,
      headers: signedHeaders(notifyBody),
      method: 'POST'
    });
    assert.equal(notifyResponse.status, 202);
    assert.deepEqual(await notifyResponse.json(), { accepted: true, matched: false, reason: 'manual_order_required' });

    const manualResponse = await fetch(`${baseUrl}/api/admin/orders/${shopOrder.id}/mark-paid`, {
      body: JSON.stringify({ triggerShopFulfillment: false }),
      headers: { 'content-type': 'application/json', cookie },
      method: 'POST'
    });
    assert.equal(manualResponse.status, 200);
    const manual = await manualResponse.json();
    assert.equal(manual.order.status, 'paid');
    assert.equal(manual.order.paymentMethod, 'manual_admin');
    assert.equal(manual.order.paymentMethodOriginal, 'alipay');
    assert.equal(manual.order.callbackStatus, 'manual_fulfilled');
    assert.equal(manual.shopFulfillmentTriggered, false);
    assert.equal(callbacks.length, 1);
    const suppression = JSON.parse(callbacks[0].request.body);
    assert.equal(callbacks[0].url, 'https://shop.newzoe.cloud/api/newzoe/manual-suppress');
    assert.equal(suppression.orderId, shopOrder.id);
    assert.equal(suppression.manualFulfilled, true);
  }, {
    adminPassword: 'test-admin-password',
    fetchImpl: async (url, request) => {
      if (String(url) === 'https://shop.test/api/newzoe/orders') return { ok: true, status: 200, json: async () => ({ orders: [shopOrder] }) };
      callbacks.push({ url, request });
      return { ok: true, status: 200 };
    },
    sessionSecret: 'test-session-secret-with-more-than-thirty-two-characters',
    shopOrdersUrl: 'https://shop.test/api/newzoe/orders'
  });
});

test('an Alipay manual confirmation can run the normal shop fulfillment callback', async () => {
  const callbacks = [];
  const shopOrder = {
    amountFen: 1300,
    callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
    createdAt: new Date(NOW).toISOString(),
    id: 'ALIPAYFULFILL01',
    paymentMethod: 'alipayscan',
    paymentName: '支付宝当面付',
    returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/ALIPAYFULFILL01',
    source: 'dujiaoka',
    status: 1,
    title: '支付宝自动发货订单'
  };
  await withServer(async (baseUrl) => {
    const loginResponse = await fetch(`${baseUrl}/api/admin/login`, {
      body: JSON.stringify({ password: 'test-admin-password', username: 'admin' }),
      headers: { 'content-type': 'application/json' },
      method: 'POST'
    });
    const cookie = loginResponse.headers.get('set-cookie').split(';')[0];
    await fetch(`${baseUrl}/api/admin/orders`, { headers: { cookie } });

    const response = await fetch(`${baseUrl}/api/admin/orders/${shopOrder.id}/mark-paid`, {
      body: JSON.stringify({ triggerShopFulfillment: true }),
      headers: { 'content-type': 'application/json', cookie },
      method: 'POST'
    });
    assert.equal(response.status, 200);
    const result = await response.json();
    assert.equal(result.order.status, 'paid');
    assert.equal(result.order.callbackStatus, 'success');
    assert.equal(result.order.paymentMethodOriginal, 'alipay');
    assert.equal(result.shopFulfillmentTriggered, true);
    assert.equal(callbacks.length, 1);
    const callback = JSON.parse(callbacks[0].request.body);
    assert.equal(callback.orderId, shopOrder.id);
    assert.equal(callback.amountFen, 1300);
    assert.equal(callback.manualOverride, true);
  }, {
    adminPassword: 'test-admin-password',
    fetchImpl: async (url, request) => {
      if (String(url) === 'https://shop.test/api/newzoe/orders') return { ok: true, status: 200, json: async () => ({ orders: [shopOrder] }) };
      callbacks.push({ url, request });
      return { ok: true, status: 200 };
    },
    sessionSecret: 'test-session-secret-with-more-than-thirty-two-characters',
    shopOrdersUrl: 'https://shop.test/api/newzoe/orders'
  });
});

test('Alipay admin listing keeps remote status and canonicalizes external ids', async () => {
  const shopOrders = [
    {
      amountFen: 1501,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      createdAt: new Date(NOW).toISOString(),
      id: 'alipayremote01',
      paymentMethod: 'aliweb',
      paymentName: '支付宝',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/alipayremote01',
      source: 'dujiaoka',
      status: 2,
      title: '远端已支付'
    },
    {
      amountFen: 1502,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      createdAt: new Date(NOW).toISOString(),
      id: 'ALIPAYREMOTE02',
      paymentMethod: 'alipayscan',
      paymentName: '支付宝当面付',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/ALIPAYREMOTE02',
      source: 'dujiaoka',
      status: 4,
      title: '远端已完成'
    }
  ];
  await withServer(async (baseUrl) => {
    const loginResponse = await fetch(`${baseUrl}/api/admin/login`, {
      body: JSON.stringify({ password: 'test-admin-password', username: 'admin' }),
      headers: { 'content-type': 'application/json' },
      method: 'POST'
    });
    const cookie = loginResponse.headers.get('set-cookie').split(';')[0];
    const response = await fetch(`${baseUrl}/api/admin/orders`, { headers: { cookie } });
    assert.equal(response.status, 200);
    const listed = (await response.json()).orders;
    const paid = listed.find((order) => order.id === 'ALIPAYREMOTE01');
    const completed = listed.find((order) => order.id === 'ALIPAYREMOTE02');
    assert.equal(paid.status, 'paid');
    assert.equal(paid.payment.externalStatus, 'paid');
    assert.equal(completed.status, 'completed');
    assert.equal(completed.payment.externalStatus, 'completed');
  }, {
    adminPassword: 'test-admin-password',
    fetchImpl: async (url) => {
      if (String(url) === 'https://shop.test/api/newzoe/orders') return { ok: true, status: 200, json: async () => ({ orders: shopOrders }) };
      return { ok: true, status: 200 };
    },
    sessionSecret: 'test-session-secret-with-more-than-thirty-two-characters',
    shopOrdersUrl: 'https://shop.test/api/newzoe/orders'
  });
});

test('remote-completed Alipay orders stay paid locally after their checkout window', async () => {
  const shopOrder = {
    amountFen: 1503,
    callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
    createdAt: new Date(NOW - 60 * 60 * 1000).toISOString(),
    expiresAt: new Date(NOW - 40 * 60 * 1000).toISOString(),
    id: 'ALIPAYREMOTE03',
    matchExpiresAt: new Date(NOW - 35 * 60 * 1000).toISOString(),
    paymentMethod: 'aliweb',
    paymentName: '支付宝',
    returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/ALIPAYREMOTE03',
    source: 'dujiaoka',
    status: 4,
    title: '远端回调已完成',
    updatedAt: new Date(NOW - 59 * 60 * 1000).toISOString()
  };
  await withServer(async (baseUrl) => {
    const loginResponse = await fetch(`${baseUrl}/api/admin/login`, {
      body: JSON.stringify({ password: 'test-admin-password', username: 'admin' }),
      headers: { 'content-type': 'application/json' },
      method: 'POST'
    });
    const cookie = loginResponse.headers.get('set-cookie').split(';')[0];
    const response = await fetch(`${baseUrl}/api/admin/orders`, { headers: { cookie } });
    assert.equal(response.status, 200);
    const order = (await response.json()).orders.find((item) => item.id === shopOrder.id);
    assert.equal(order.status, 'completed');
    assert.equal(order.payment.externalStatus, 'completed');
    assert.equal(order.payment.status, 'paid');
    assert.equal(order.payment.paidAt, shopOrder.updatedAt);
  }, {
    adminPassword: 'test-admin-password',
    fetchImpl: async (url) => {
      if (String(url) === 'https://shop.test/api/newzoe/orders') return { ok: true, status: 200, json: async () => ({ orders: [shopOrder] }) };
      return { ok: true, status: 200 };
    },
    sessionSecret: 'test-session-secret-with-more-than-thirty-two-characters',
    shopOrdersUrl: 'https://shop.test/api/newzoe/orders'
  });
});

test('malformed external Alipay amounts are not materialized for manual settlement', async () => {
  const shopOrders = [
    {
      amountFen: 901,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      id: 'ALIPAYVALID01',
      paymentMethod: 'aliweb',
      paymentName: '支付宝',
      source: 'dujiaoka',
      status: 1,
      title: '有效金额'
    },
    {
      amountFen: '1e3',
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      id: 'ALIPAYBAD001',
      paymentMethod: 'aliweb',
      paymentName: '支付宝',
      source: 'dujiaoka',
      status: 1,
      title: '科学计数法'
    },
    {
      amountFen: Number.MAX_SAFE_INTEGER + 1,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      id: 'ALIPAYBAD002',
      paymentMethod: 'aliweb',
      paymentName: '支付宝',
      source: 'dujiaoka',
      status: 1,
      title: '不安全整数'
    }
  ];
  await withServer(async (baseUrl) => {
    const loginResponse = await fetch(`${baseUrl}/api/admin/login`, {
      body: JSON.stringify({ password: 'test-admin-password', username: 'admin' }),
      headers: { 'content-type': 'application/json' },
      method: 'POST'
    });
    const cookie = loginResponse.headers.get('set-cookie').split(';')[0];
    const response = await fetch(`${baseUrl}/api/admin/orders`, { headers: { cookie } });
    const listed = (await response.json()).orders;
    assert.equal(listed.find((order) => order.id === 'ALIPAYVALID01').payment.manualOnly, true);
    assert.equal(listed.find((order) => order.id === 'ALIPAYBAD001').payment, null);
    assert.equal(listed.find((order) => order.id === 'ALIPAYBAD002').payment, null);
  }, {
    adminPassword: 'test-admin-password',
    fetchImpl: async (url) => {
      if (String(url) === 'https://shop.test/api/newzoe/orders') return { ok: true, status: 200, json: async () => ({ orders: shopOrders }) };
      return { ok: true, status: 200 };
    },
    sessionSecret: 'test-session-secret-with-more-than-thirty-two-characters',
    shopOrdersUrl: 'https://shop.test/api/newzoe/orders'
  });
});

test('a failed Alipay manual-suppression request remains retryable', async () => {
  const callbacks = [];
  const shopOrder = {
    amountFen: 1200,
    callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
    createdAt: new Date(NOW).toISOString(),
    expiresAt: new Date(NOW + 20 * 60 * 1000).toISOString(),
    id: 'ALIPAYSUPPRESS01',
    manualSuppressUrl: 'https://shop.newzoe.cloud/api/newzoe/manual-suppress',
    matchExpiresAt: new Date(NOW + 25 * 60 * 1000).toISOString(),
    paymentMethod: 'aliweb',
    paymentName: '支付宝',
    source: 'dujiaoka',
    status: 1,
    title: '抑制重试'
  };
  let suppressionAttempts = 0;
  await withServer(async (baseUrl) => {
    const loginResponse = await fetch(`${baseUrl}/api/admin/login`, {
      body: JSON.stringify({ password: 'test-admin-password', username: 'admin' }),
      headers: { 'content-type': 'application/json' },
      method: 'POST'
    });
    const cookie = loginResponse.headers.get('set-cookie').split(';')[0];
    const first = await fetch(`${baseUrl}/api/admin/orders`, { headers: { cookie } });
    assert.equal(first.status, 200);

    const failed = await fetch(`${baseUrl}/api/admin/orders/${shopOrder.id}/mark-paid`, {
      body: JSON.stringify({ triggerShopFulfillment: false }),
      headers: { 'content-type': 'application/json', cookie },
      method: 'POST'
    });
    const failedBody = await failed.json();
    assert.equal(failed.status, 200);
    assert.equal(failedBody.shopFulfillmentSuppressed, false);
    assert.equal(failedBody.order.callbackStatus, 'error');
    assert.equal(failedBody.order.callbackSuppressedAt, null);

    const retried = await fetch(`${baseUrl}/api/admin/orders/${shopOrder.id}/mark-paid`, {
      body: JSON.stringify({ triggerShopFulfillment: false }),
      headers: { 'content-type': 'application/json', cookie },
      method: 'POST'
    });
    const retriedBody = await retried.json();
    assert.equal(retried.status, 200);
    assert.equal(retriedBody.duplicate, true);
    assert.equal(retriedBody.shopFulfillmentSuppressed, true);
    assert.equal(retriedBody.order.callbackStatus, 'manual_fulfilled');
    assert.ok(retriedBody.order.callbackSuppressedAt);
  }, {
    adminPassword: 'test-admin-password',
    fetchImpl: async (url, request) => {
      if (String(url) === 'https://shop.test/api/newzoe/orders') return { ok: true, status: 200, json: async () => ({ orders: [shopOrder] }) };
      callbacks.push({ url, request });
      suppressionAttempts += 1;
      return suppressionAttempts === 1 ? { ok: false, status: 503 } : { ok: true, status: 200 };
    },
    sessionSecret: 'test-session-secret-with-more-than-thirty-two-characters',
    shopOrdersUrl: 'https://shop.test/api/newzoe/orders'
  });
  assert.equal(callbacks.length, 2);
  assert.equal(callbacks[0].url, 'https://shop.newzoe.cloud/api/newzoe/manual-suppress');
  assert.equal(callbacks[1].url, 'https://shop.newzoe.cloud/api/newzoe/manual-suppress');
});

test('external Alipay orders with invalid expiry windows are not materialized', async () => {
  const shopOrders = [
    {
      amountFen: 801,
      createdAt: new Date(NOW).toISOString(),
      expiresAt: 'not-a-date',
      id: 'ALIPAYDATEBAD1',
      paymentMethod: 'aliweb',
      paymentName: '支付宝',
      source: 'dujiaoka',
      status: 1,
      title: '无效过期时间'
    },
    {
      amountFen: 802,
      createdAt: new Date(NOW).toISOString(),
      expiresAt: new Date(NOW + 20 * 60 * 1000).toISOString(),
      id: 'ALIPAYDATEBAD2',
      matchExpiresAt: new Date(NOW + 10 * 60 * 1000).toISOString(),
      paymentMethod: 'aliweb',
      paymentName: '支付宝',
      source: 'dujiaoka',
      status: 1,
      title: '匹配窗口过短'
    }
  ];
  await withServer(async (baseUrl) => {
    const loginResponse = await fetch(`${baseUrl}/api/admin/login`, {
      body: JSON.stringify({ password: 'test-admin-password', username: 'admin' }),
      headers: { 'content-type': 'application/json' },
      method: 'POST'
    });
    const cookie = loginResponse.headers.get('set-cookie').split(';')[0];
    const response = await fetch(`${baseUrl}/api/admin/orders`, { headers: { cookie } });
    const listed = (await response.json()).orders;
    assert.equal(listed.find((order) => order.id === 'ALIPAYDATEBAD1').payment, null);
    assert.equal(listed.find((order) => order.id === 'ALIPAYDATEBAD2').payment, null);
  }, {
    adminPassword: 'test-admin-password',
    fetchImpl: async (url) => {
      if (String(url) === 'https://shop.test/api/newzoe/orders') return { ok: true, status: 200, json: async () => ({ orders: shopOrders }) };
      return { ok: true, status: 200 };
    },
    sessionSecret: 'test-session-secret-with-more-than-thirty-two-characters',
    shopOrdersUrl: 'https://shop.test/api/newzoe/orders'
  });
});

test('failed shop fulfillment can be retried without settling the order twice', async () => {
  const callbacks = [];
  await withServer(async (baseUrl) => {
    const loginResponse = await fetch(`${baseUrl}/api/admin/login`, {
      body: JSON.stringify({ password: 'test-admin-password', username: 'admin' }),
      headers: { 'content-type': 'application/json' },
      method: 'POST'
    });
    const cookie = loginResponse.headers.get('set-cookie').split(';')[0];
    const orderBody = JSON.stringify({
      amountFen: 900,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'CALLBACKRETRY01',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/CALLBACKRETRY01',
      title: '回调重试订单'
    });
    const timestamp = String(NOW);
    await fetch(`${baseUrl}/api/shop/orders`, {
      body: orderBody,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(orderBody)),
        'x-shop-timestamp': timestamp
      },
      method: 'POST'
    });

    const crossOrigin = await fetch(`${baseUrl}/api/admin/orders/CALLBACKRETRY01/mark-paid`, {
      body: JSON.stringify({ triggerShopFulfillment: true }),
      headers: { 'content-type': 'application/json', cookie, origin: 'https://shop.newzoe.cloud' },
      method: 'POST'
    });
    assert.equal(crossOrigin.status, 403);
    assert.deepEqual(await crossOrigin.json(), { error: 'invalid_origin' });

    const wrongContentType = await fetch(`${baseUrl}/api/admin/orders/CALLBACKRETRY01/mark-paid`, {
      body: JSON.stringify({ triggerShopFulfillment: true }),
      headers: { 'content-type': 'text/plain', cookie },
      method: 'POST'
    });
    assert.equal(wrongContentType.status, 415);
    assert.deepEqual(await wrongContentType.json(), { error: 'invalid_content_type' });

    const firstResponse = await fetch(`${baseUrl}/api/admin/orders/CALLBACKRETRY01/mark-paid`, {
      body: JSON.stringify({ triggerShopFulfillment: true }),
      headers: { 'content-type': 'application/json', cookie },
      method: 'POST'
    });
    const first = await firstResponse.json();
    assert.equal(first.order.status, 'paid');
    assert.equal(first.order.callbackStatus, 'http_503');
    assert.equal(callbacks.length, 1);

    const retryResponse = await fetch(`${baseUrl}/api/admin/orders/CALLBACKRETRY01/mark-paid`, {
      body: JSON.stringify({ triggerShopFulfillment: true }),
      headers: { 'content-type': 'application/json', cookie },
      method: 'POST'
    });
    const retried = await retryResponse.json();
    assert.equal(retried.duplicate, true);
    assert.equal(retried.shopFulfillmentRetried, true);
    assert.equal(retried.order.callbackStatus, 'success');
    assert.equal(callbacks.length, 2);
    assert.equal(JSON.parse(callbacks[0].request.body).manualOverride, true);
    assert.equal(JSON.parse(callbacks[1].request.body).manualOverride, true);

    const completedRetry = await fetch(`${baseUrl}/api/admin/orders/CALLBACKRETRY01/mark-paid`, {
      body: JSON.stringify({ triggerShopFulfillment: true }),
      headers: { 'content-type': 'application/json', cookie },
      method: 'POST'
    });
    const completed = await completedRetry.json();
    assert.equal(completed.shopFulfillmentRetried, false);
    assert.equal(callbacks.length, 2);
  }, {
    adminPassword: 'test-admin-password',
    fetchImpl: async (url, request) => {
      callbacks.push({ request, url });
      return callbacks.length === 1 ? { ok: false, status: 503 } : { ok: true, status: 200 };
    },
    sessionSecret: 'test-session-secret-with-more-than-thirty-two-characters'
  });
});

test('an expired order can be manually fulfilled with a signed override', async () => {
  let clock = NOW;
  const callbacks = [];
  await withServer(async (baseUrl) => {
    const loginResponse = await fetch(`${baseUrl}/api/admin/login`, {
      body: JSON.stringify({ password: 'test-admin-password', username: 'admin' }),
      headers: { 'content-type': 'application/json' },
      method: 'POST'
    });
    const cookie = loginResponse.headers.get('set-cookie').split(';')[0];
    const orderBody = JSON.stringify({
      amountFen: 910,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'EXPIREDMANUAL01',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/EXPIREDMANUAL01',
      title: '过期人工补单'
    });
    const timestamp = String(clock);
    const created = await fetch(`${baseUrl}/api/shop/orders`, {
      body: orderBody,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(orderBody)),
        'x-shop-timestamp': timestamp
      },
      method: 'POST'
    });
    assert.equal(created.status, 201);

    clock = NOW + 25 * 60 * 1000;
    const ordersResponse = await fetch(`${baseUrl}/api/admin/orders`, { headers: { cookie } });
    const orders = (await ordersResponse.json()).orders;
    assert.equal(orders.find((order) => order.id === 'EXPIREDMANUAL01').status, 'expired');

    const fulfilledResponse = await fetch(`${baseUrl}/api/admin/orders/EXPIREDMANUAL01/mark-paid`, {
      body: JSON.stringify({ triggerShopFulfillment: true }),
      headers: { 'content-type': 'application/json', cookie },
      method: 'POST'
    });
    assert.equal(fulfilledResponse.status, 200);
    assert.equal((await fulfilledResponse.json()).order.status, 'paid');
    assert.equal(callbacks.length, 1);
    assert.equal(JSON.parse(callbacks[0].request.body).manualOverride, true);
  }, {
    adminPassword: 'test-admin-password',
    fetchImpl: async (url, request) => {
      callbacks.push({ request, url });
      return { ok: true, status: 200 };
    },
    now: () => clock,
    sessionSecret: 'test-session-secret-with-more-than-thirty-two-characters'
  });
});

test('concurrent fulfillment clicks send only one shop callback', async () => {
  let callbackCount = 0;
  let releaseCallback;
  const callbackGate = new Promise((resolve) => { releaseCallback = resolve; });
  await withServer(async (baseUrl) => {
    const loginResponse = await fetch(`${baseUrl}/api/admin/login`, {
      body: JSON.stringify({ password: 'test-admin-password', username: 'admin' }),
      headers: { 'content-type': 'application/json' },
      method: 'POST'
    });
    const cookie = loginResponse.headers.get('set-cookie').split(';')[0];
    const orderBody = JSON.stringify({
      amountFen: 901,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'CALLBACKRACE01',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/CALLBACKRACE01',
      title: '回调并发订单'
    });
    const timestamp = String(NOW);
    await fetch(`${baseUrl}/api/shop/orders`, {
      body: orderBody,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(orderBody)),
        'x-shop-timestamp': timestamp
      },
      method: 'POST'
    });

    const request = () => fetch(`${baseUrl}/api/admin/orders/CALLBACKRACE01/mark-paid`, {
      body: JSON.stringify({ triggerShopFulfillment: true }),
      headers: { 'content-type': 'application/json', cookie },
      method: 'POST'
    });
    const firstRequest = request();
    while (callbackCount === 0) await new Promise((resolve) => setTimeout(resolve, 5));
    const secondResponse = await request();
    const second = await secondResponse.json();
    assert.equal(second.duplicate, true);
    assert.equal(second.shopFulfillmentRetried, false);
    assert.equal(second.order.callbackStatus, 'processing');
    assert.equal(callbackCount, 1);

    releaseCallback();
    const firstResponse = await firstRequest;
    const first = await firstResponse.json();
    assert.equal(first.order.callbackStatus, 'success');
    assert.equal(callbackCount, 1);
  }, {
    adminPassword: 'test-admin-password',
    fetchImpl: async () => {
      callbackCount++;
      await callbackGate;
      return { ok: true, status: 200 };
    },
    sessionSecret: 'test-session-secret-with-more-than-thirty-two-characters'
  });
});

test('every merchant gets isolated orders, QR codes, webhooks, and notification secrets', async () => {
  await withServer(async (baseUrl) => {
    const login = async (username, password) => {
      const response = await fetch(`${baseUrl}/api/admin/login`, {
        body: JSON.stringify({ password, username }),
        headers: { 'content-type': 'application/json' },
        method: 'POST'
      });
      assert.equal(response.status, 200);
      return response.headers.get('set-cookie').split(';')[0];
    };

    const adminCookie = await login('admin', 'test-admin-password');
    const merchants = [
      { displayName: '商户甲', password: 'merchant-a-password', username: 'merchant_a' },
      { displayName: '商户乙', password: 'merchant-b-password', username: 'merchant_b' }
    ];

    for (const merchant of merchants) {
      const response = await fetch(`${baseUrl}/api/admin/users`, {
        body: JSON.stringify(merchant),
        headers: { 'content-type': 'application/json', cookie: adminCookie },
        method: 'POST'
      });
      assert.equal(response.status, 201);
    }

    const merchantCookies = {};
    for (const [index, merchant] of merchants.entries()) {
      const cookie = await login(merchant.username, merchant.password);
      merchantCookies[merchant.username] = cookie;

      const emptyOrders = await fetch(`${baseUrl}/api/admin/orders`, { headers: { cookie } });
      assert.deepEqual((await emptyOrders.json()).orders, []);

      const beforeQr = await fetch(`${baseUrl}/api/admin/orders`, {
        body: JSON.stringify({ amount: '1.00', title: '二维码前测试' }),
        headers: { 'content-type': 'application/json', cookie },
        method: 'POST'
      });
      assert.equal(beforeQr.status, 409);
      assert.deepEqual(await beforeQr.json(), { error: 'qrcode_required' });

      const qrBytes = Buffer.concat([
        Buffer.from([0x89, 0x50, 0x4e, 0x47, index + 1]),
        Buffer.alloc(128, index + 1)
      ]);
      const upload = await fetch(`${baseUrl}/api/admin/qrcode`, {
        body: qrBytes,
        headers: { cookie },
        method: 'POST'
      });
      assert.equal(upload.status, 200);

      const create = await fetch(`${baseUrl}/api/admin/orders`, {
        body: JSON.stringify({ amount: '1.00', title: `${merchant.displayName}订单` }),
        headers: { 'content-type': 'application/json', cookie },
        method: 'POST'
      });
      assert.equal(create.status, 201);
      merchant.order = (await create.json()).order;
      assert.equal(merchant.order.payee, merchant.username);
      assert.equal(merchant.order.amountFen, 100);
    }

    for (const merchant of merchants) {
      const response = await fetch(`${baseUrl}/api/admin/orders`, {
        headers: { cookie: merchantCookies[merchant.username] }
      });
      const orders = (await response.json()).orders;
      assert.equal(orders.length, 1);
      assert.equal(orders[0].id, merchant.order.id);
      assert.equal(orders[0].payment.payee, merchant.username);

      const publicOrder = await fetch(`${baseUrl}/api/orders/${merchant.order.id}`);
      const publicData = await publicOrder.json();
      assert.equal(publicData.payee, merchant.username);
      assert.equal(publicData.qrcodeReady, true);

      const qr = await fetch(`${baseUrl}/wechat-pay.jpg?order=${merchant.order.id}`);
      assert.equal(qr.status, 200);
    }

    const configs = await Promise.all(merchants.map(async (merchant) => {
      const response = await fetch(`${baseUrl}/api/admin/smsf-config`, {
        headers: { cookie: merchantCookies[merchant.username] }
      });
      return response.json();
    }));
    assert.match(configs[0].webhookUrl, /\?user=merchant_a$/);
    assert.match(configs[1].webhookUrl, /\?user=merchant_b$/);
    assert.notEqual(configs[0].secret, configs[1].secret);

    const shopBody = JSON.stringify({
      amountFen: 5,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'SHOPADMIN123',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/SHOPADMIN123',
      title: '卡网固定归属测试'
    });
    const timestamp = String(NOW);
    const shopCreate = await fetch(`${baseUrl}/api/shop/orders`, {
      body: shopBody,
      headers: {
        'content-type': 'application/json',
        'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(shopBody)),
        'x-shop-timestamp': timestamp
      },
      method: 'POST'
    });
    assert.equal(shopCreate.status, 201);
    assert.equal((await shopCreate.json()).order.payee, 'admin');

    const forbiddenSettlement = await fetch(`${baseUrl}/api/admin/orders/SHOPADMIN123/mark-paid`, {
      body: JSON.stringify({ triggerShopFulfillment: true }),
      headers: { 'content-type': 'application/json', cookie: merchantCookies.merchant_a },
      method: 'POST'
    });
    assert.equal(forbiddenSettlement.status, 403);
    assert.deepEqual(await forbiddenSettlement.json(), { error: 'forbidden' });

    for (const merchant of merchants) {
      const response = await fetch(`${baseUrl}/api/admin/orders`, {
        headers: { cookie: merchantCookies[merchant.username] }
      });
      const orders = (await response.json()).orders;
      assert.equal(orders.some((order) => order.id === 'SHOPADMIN123'), false);
    }

    const deleteWithOrders = await fetch(`${baseUrl}/api/admin/users/merchant_a`, {
      headers: { cookie: adminCookie },
      method: 'DELETE'
    });
    assert.equal(deleteWithOrders.status, 409);
    assert.deepEqual(await deleteWithOrders.json(), { error: 'merchant_has_orders' });
  }, {
    adminPassword: 'test-admin-password',
    sessionSecret: 'test-session-secret-with-more-than-thirty-two-characters'
  });
});

test('SmsForwarder ignores ordinary WeChat chats that mention one-fen payments', async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: smsfForm({
        content: '收款到账0.01元',
        from: 'com.tencent.mm',
        title: '张三'
      }),
      method: 'POST'
    });

    assert.equal(response.status, 200);
    assert.deepEqual(await response.json(), { accepted: true, matched: false });
  });
});

test('SmsForwarder notifications reject invalid signatures', async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: new URLSearchParams({
        content: '微信收款到账0.01元',
        from: 'com.tencent.mm',
        sign: 'bad',
        timestamp: String(NOW)
      }),
      method: 'POST'
    });

    assert.equal(response.status, 401);
    assert.deepEqual(await response.json(), { error: 'invalid_signature' });
  });
});
