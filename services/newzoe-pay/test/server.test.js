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

test('opening a legacy colliding pending order repairs its payable amount', async () => {
  await withServer(async (baseUrl) => {
    const response = await fetch(`${baseUrl}/api/orders/LEGACYPENDING01`);
    assert.equal(response.status, 200);
    const order = await response.json();
    assert.equal(order.baseAmountFen, 13500);
    assert.equal(order.amountFen, 13501);
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

test('a previously unmatched delayed notification cannot settle a later order when retried', async () => {
  let clock = NOW;
  await withServer(async (baseUrl) => {
    const fields = { content: '微信支付收款到账8.88元，今日第1笔', from: 'com.tencent.mm', title: '微信支付' };
    const first = await fetch(`${baseUrl}/api/smsf/notify`, { body: smsfForm(fields, String(clock)), method: 'POST' });
    assert.equal(first.status, 409);

    clock += 60 * 60 * 1000;
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

    const retry = await fetch(`${baseUrl}/api/smsf/notify`, { body: smsfForm(fields, String(clock)), method: 'POST' });
    assert.equal(retry.status, 409);
    assert.equal((await (await fetch(`${baseUrl}/api/orders/LATERORDER1234`)).json()).status, 'pending');
  }, {
    fetchImpl: async () => ({ ok: true, status: 200 }),
    now: () => clock
  });
});

test('SmsForwarder deduplicates the same payment sent by overlapping rules', async () => {
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
