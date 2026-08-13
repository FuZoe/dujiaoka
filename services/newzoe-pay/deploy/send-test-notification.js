'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');

const secretFile = process.env.PAY_NOTIFY_SECRET_FILE || '/etc/newzoe-pay/notify.secret';
const notifyUrl = process.env.PAY_NOTIFY_URL || 'http://127.0.0.1:3210/api/payment/notify';
const secret = fs.readFileSync(secretFile, 'utf8').trim();
const timestamp = Math.floor(Date.now() / 1000).toString();
const body = JSON.stringify({
  amountFen: 1,
  paidAt: new Date().toISOString(),
  transactionId: `qa-test-${Date.now()}`
});
const signature = crypto
  .createHmac('sha256', secret)
  .update(`${timestamp}.`)
  .update(body)
  .digest('hex');

fetch(notifyUrl, {
  body,
  headers: {
    'content-type': 'application/json',
    'x-pay-signature': signature,
    'x-pay-timestamp': timestamp
  },
  method: 'POST'
}).then(async (response) => {
  const responseBody = await response.text();
  console.log(response.status, responseBody);
  if (!response.ok) process.exitCode = 1;
});
