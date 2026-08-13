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
  const server = createPayServer({
    now: () => NOW,
    secret: SECRET,
    smsfSecret: SMSF_SECRET,
    shopSecret: SHOP_SECRET,
    stateFile: path.join(directory, 'state.json'),
    ...options
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

test('SmsForwarder matches delayed notifications for orders activated within 24 hours', async () => {
  let clock = NOW;
  await withServer(async (baseUrl) => {
    const orderBody = JSON.stringify({
      amountFen: 500,
      callbackUrl: 'https://shop.newzoe.cloud/pay/newzoe/notify_url',
      orderId: 'DELAYEDORDER123',
      returnUrl: 'https://shop.newzoe.cloud/detail-order-sn/DELAYEDORDER123',
      title: '延迟通知测试'
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
    await fetch(`${baseUrl}/api/orders/DELAYEDORDER123`);

    clock += 6 * 60 * 60 * 1000;
    const response = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: smsfForm({ content: '微信支付收款到账5.00元', from: 'com.tencent.mm', title: '微信支付' }, String(clock)),
      method: 'POST'
    });
    assert.equal(response.status, 200);
    assert.equal((await response.json()).orderId, 'DELAYEDORDER123');
  }, {
    fetchImpl: async () => ({ ok: true, status: 200 }),
    now: () => clock
  });
});

test('same-amount orders settle FIFO while duplicate forwarding settles only once', async () => {
  let clock = NOW;
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
      await fetch(`${baseUrl}/api/shop/orders`, {
        body,
        headers: {
          'content-type': 'application/json',
          'x-shop-signature': signPayload(SHOP_SECRET, timestamp, Buffer.from(body)),
          'x-shop-timestamp': timestamp
        },
        method: 'POST'
      });
      await fetch(`${baseUrl}/api/orders/${orderId}`);
      clock += 1000;
    };
    await create('FIFOORDER0001');
    await create('FIFOORDER0002');

    // Refreshing the first checkout must not move it behind the second order.
    clock += 1000;
    await fetch(`${baseUrl}/api/orders/FIFOORDER0001`);

    const firstFields = { content: '微信支付收款到账1.00元，今日第1笔', from: 'com.tencent.mm', title: '微信支付' };
    const first = await fetch(`${baseUrl}/api/smsf/notify`, { body: smsfForm(firstFields, String(clock)), method: 'POST' });
    assert.equal((await first.json()).orderId, 'FIFOORDER0001');

    clock += 25;
    const duplicate = await fetch(`${baseUrl}/api/smsf/notify`, { body: smsfForm(firstFields, String(clock)), method: 'POST' });
    assert.deepEqual(await duplicate.json(), { accepted: true, duplicate: true, matched: true });
    assert.equal((await (await fetch(`${baseUrl}/api/orders/FIFOORDER0002`)).json()).status, 'pending');

    clock += 6000;
    const second = await fetch(`${baseUrl}/api/smsf/notify`, {
      body: smsfForm({ content: '微信支付收款到账1.00元，今日第2笔', from: 'com.tencent.mm', title: '微信支付' }, String(clock)),
      method: 'POST'
    });
    assert.equal((await second.json()).orderId, 'FIFOORDER0002');
  }, {
    fetchImpl: async () => ({ ok: true, status: 200 }),
    now: () => clock
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
      body: JSON.stringify({ amount: '12.34', title: '线下补款' }),
      headers: { 'content-type': 'application/json', cookie },
      method: 'POST'
    });
    assert.equal(createResponse.status, 201);
    const created = await createResponse.json();
    assert.equal(created.order.amountFen, 1234);
    assert.match(created.paymentUrl, /^https:\/\/pay\.newzoe\.cloud\/M/);

    const ordersResponse = await fetch(`${baseUrl}/api/admin/orders`, { headers: { cookie } });
    const orders = (await ordersResponse.json()).orders;
    assert.equal(orders.length, 1);
    assert.equal(orders[0].status, 'pending');
  }, {
    adminPassword: 'test-admin-password',
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
        body: JSON.stringify({ amount: `${index + 1}.01`, title: `${merchant.displayName}订单` }),
        headers: { 'content-type': 'application/json', cookie },
        method: 'POST'
      });
      assert.equal(create.status, 201);
      merchant.order = (await create.json()).order;
      assert.equal(merchant.order.payee, merchant.username);
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
