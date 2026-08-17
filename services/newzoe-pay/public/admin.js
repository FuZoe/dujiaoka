"use strict";

// ==================== State ====================
let session = null;
let orders = [];
let users = [];
let currentTab = "dashboard";
let confirmResolve = null;
let markPaidOrder = null;
let markPaidBusy = false;
const retryingOrders = new Set();

// ==================== DOM refs ====================
const $ = (s) => document.querySelector(s);
const $$ = (s) => document.querySelectorAll(s);

const loginPanel = $("#login-panel");
const adminNav = $("#admin-nav");
const tabUsers = $("#nav-tab-users");
const userInfo = $("#user-info");
const logoutBtn = $("#logout");
const ordersBody = $("#orders-body");
const empty = $("#empty");
const usersBody = $("#users-body");
const createDialog = $("#create-dialog");
const createdDialog = $("#created-dialog");
const confirmDialog = $("#confirm-dialog");
const resetPwDialog = $("#reset-pw-dialog");
const markPaidDialog = $("#mark-paid-dialog");

// ==================== Helpers ====================
function money(fen) { return "¥" + (Number(fen || 0) / 100).toFixed(2); }
function dt(v) { return v ? new Intl.DateTimeFormat("zh-CN", { dateStyle: "short", timeStyle: "short" }).format(new Date(v)) : "-"; }
function roleLabel(r) { return r === "super" ? "超级管理员" : "管理员"; }
function esc(v) { return String(v ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[c]); }

function normStatus(o) {
  const s = o.payment?.status || o.status;
  if (["completed", "paid", "pending", "expired"].includes(s)) return s;
  const c = Number(s);
  return ({ "-1": "expired", 1: "pending", 2: "paid", 3: "paid", 4: "completed" })[c] || "pending";
}
const statusMap = { pending: "待支付", paid: "已支付", completed: "已完成", expired: "已过期" };
const smsfResultMap = { matched: "已匹配", duplicate: "重复通知", no_pending_order: "没有同金额待支付订单", amount_not_found: "未识别到金额", ignored: "已忽略" };
const callbackStatusMap = { success: "卡网已发货", waiting: "等待卡网回调", processing: "卡网回调处理中", manual_fulfilled: "人工已发货", error: "卡网回调失败" };
function callbackStatusLabel(status) { return status.startsWith("http_") ? "卡网回调失败" : (callbackStatusMap[status] || status); }
function adminErrorMessage(error) {
  const code = error.data?.error;
  return ({ forbidden: "没有权限操作该订单", invalid_origin: "页面来源校验失败，请刷新后重试", invalid_content_type: "请求格式不正确，请刷新后重试", order_not_found: "订单不存在或已被移除", invalid_fulfillment_choice: "请选择卡网发货处理方式", invalid_order_status: "该订单当前状态不支持此操作" })[code] || error.message;
}

// ==================== API ====================
async function api(url, opts = {}) {
  const h = { "content-type": "application/json", ...(opts.headers || {}) };
  if (opts.body instanceof FormData || opts.body instanceof Blob || opts.body instanceof Uint8Array) delete h["content-type"];
  const r = await fetch(url, { ...opts, headers: h, credentials: "same-origin" });
  const d = await r.json().catch(() => ({}));
  if (!r.ok) {
    if (r.status === 401) setAuth(false);
    const fallback = r.status === 413 ? "文件超过服务器上传限制" : (r.status === 401 ? "登录已过期，请重新登录" : `请求失败（HTTP ${r.status}）`);
    throw Object.assign(new Error(d.error || fallback), { status: r.status, data: d });
  }
  return d;
}

// ==================== Auth ====================
async function checkSession() {
  try {
    session = await api("/api/admin/session");
    if (!session.authenticated) return setAuth(false);
    setAuth(true);
  } catch { setAuth(false); }
}

function setAuth(ok) {
  loginPanel.hidden = ok;
  adminNav.hidden = !ok;
  logoutBtn.hidden = !ok;
  userInfo.hidden = !ok;
  if (ok && session) {
    userInfo.textContent = session.displayName + " (" + roleLabel(session.role) + ")";
    tabUsers.hidden = session.role !== "super";
    showTab(currentTab);
    loadOrders();
    if (session.role === "super") loadUsers();
  } else {
    $$('dialog[open]').forEach((dialog) => dialog.close());
    if (confirmResolve) { confirmResolve(false); confirmResolve = null; }
    $$(".tab-content").forEach((t) => { t.hidden = true; t.classList.remove("active"); });
    session = null;
  }
}

// ==================== Navigation ====================
$$(".nav-tab").forEach((btn) => {
  btn.addEventListener("click", () => {
    currentTab = btn.dataset.tab;
    showTab(currentTab);
    if (currentTab === "dashboard") loadOrders();
    if (currentTab === "users" && session?.role === "super") loadUsers();
    if (currentTab === "qrcode") refreshQrPreview();
    if (currentTab === "settings") { loadSmsfConfig(); loadSmsfEvents(); }
  });
});

function showTab(tab) {
  $$(".nav-tab").forEach((b) => b.classList.toggle("active", b.dataset.tab === tab));
  $$(".tab-content").forEach((t) => { t.hidden = true; t.classList.remove("active"); });
  const target = $("#tab-" + tab);
  if (target) { target.hidden = false; target.classList.add("active"); }
}

// ==================== Dashboard / Orders ====================
function renderOrders() {
  const q = ($("#search").value || "").trim().toLowerCase();
  const f = $("#status-filter").value;
  const filtered = orders.filter((o) => {
    const s = normStatus(o);
    const h = `${o.id} ${o.title || ""}`.toLowerCase();
    return (!f || s === f) && (!q || h.includes(q));
  });
  ordersBody.replaceChildren(...filtered.map((o) => {
    const row = document.createElement("tr");
    const s = normStatus(o);
    const payment = o.payment || null;
    const link = payment ? "https://pay.newzoe.cloud/" + encodeURIComponent(o.id) : "";
    const amountFen = payment?.amountFen ?? o.amountFen;
    const baseAmountFen = payment?.baseAmountFen ?? o.baseAmountFen ?? amountFen;
    const amountNote = baseAmountFen !== amountFen ? `<small>商品原价 ${money(baseAmountFen)}</small>` : "";
    const callbackStatus = payment?.callbackStatus || "";
    const callbackNote = callbackStatus ? `<small>${esc(callbackStatusLabel(callbackStatus))}</small>` : "";
    const canMarkPaid = payment && s === "pending";
    const isShopOrder = (payment?.source || o.source) === "dujiaoka";
    const callbackLeaseExpired = callbackStatus === "processing" && Date.parse(payment?.callbackStartedAt || 0) < Date.now() - 2 * 60 * 1000;
    const canRetryFulfillment = payment && s === "paid" && isShopOrder && !payment.callbackSuppressedAt && callbackStatus !== "success" && (callbackStatus !== "processing" || callbackLeaseExpired);
    const retrying = retryingOrders.has(o.id);
    const actions = `${link ? `<a class="table-link" href="${link}" target="_blank" rel="noopener">收银台</a>` : ""}${canMarkPaid ? `<button class="mark-paid-button" data-order="${esc(o.id)}" type="button">标记已支付</button>` : ""}${canRetryFulfillment ? `<button class="retry-fulfillment-button" data-order="${esc(o.id)}" type="button"${retrying ? " disabled" : ""}>${retrying ? "处理中..." : "重试发货"}</button>` : ""}`;
    row.innerHTML = `<td><strong>${esc(o.id)}</strong><div class="mobile-order-actions">${actions}</div></td><td>${esc(o.title || "未命名")}</td><td>${money(amountFen)}${amountNote}</td><td>${esc(o.payee || payment?.payee || "-")}</td><td>${o.source === "manual" ? "手工" : "卡网"}</td><td><span class="status status-${esc(s)}">${esc(statusMap[s] || s)}</span>${callbackNote}</td><td>${dt(o.createdAt)}</td><td><div class="table-actions">${actions}</div></td>`;
    return row;
  }));
  $$(".mark-paid-button").forEach((button) => button.addEventListener("click", () => openMarkPaid(button.dataset.order)));
  $$(".retry-fulfillment-button").forEach((button) => button.addEventListener("click", () => retryFulfillment(button.dataset.order)));
  empty.hidden = filtered.length > 0;
  $("#metric-all").textContent = orders.length;
  $("#metric-pending").textContent = orders.filter((o) => normStatus(o) === "pending").length;
  $("#metric-paid").textContent = orders.filter((o) => normStatus(o) === "paid").length;
  $("#metric-completed").textContent = orders.filter((o) => normStatus(o) === "completed").length;
}

function openMarkPaid(orderId) {
  if (markPaidBusy) return;
  markPaidOrder = orders.find((order) => order.id === orderId) || null;
  if (!markPaidOrder) return;
  const payment = markPaidOrder.payment;
  const isShopOrder = (payment?.source || markPaidOrder.source) === "dujiaoka";
  $("#mark-paid-order-id").textContent = markPaidOrder.id;
  $("#mark-paid-amount").textContent = money(payment?.amountFen ?? markPaidOrder.amountFen);
  $("#shop-fulfillment-choice").hidden = !isShopOrder;
  $("#manual-order-note").hidden = isShopOrder;
  $("#trigger-shop-fulfillment").checked = true;
  $("#mark-paid-error").textContent = "";
  markPaidDialog.showModal();
}

function setMarkPaidBusy(busy) {
  markPaidBusy = busy;
  $("#confirm-mark-paid").disabled = busy;
  $("#close-mark-paid").disabled = busy;
  $("#cancel-mark-paid").disabled = busy;
  $("#trigger-shop-fulfillment").disabled = busy;
}

function closeMarkPaid(force = false) {
  if (markPaidBusy && !force) return;
  if (markPaidDialog.open) markPaidDialog.close();
  markPaidOrder = null;
}

$("#close-mark-paid").addEventListener("click", () => closeMarkPaid());
$("#cancel-mark-paid").addEventListener("click", () => closeMarkPaid());
markPaidDialog.addEventListener("cancel", (event) => {
  if (markPaidBusy) event.preventDefault();
});
$("#confirm-mark-paid").addEventListener("click", async () => {
  if (!markPaidOrder || markPaidBusy) return;
  const targetOrder = markPaidOrder;
  const button = $("#confirm-mark-paid");
  const error = $("#mark-paid-error");
  const isShopOrder = (targetOrder.payment?.source || targetOrder.source) === "dujiaoka";
  setMarkPaidBusy(true);
  button.textContent = "处理中...";
  error.textContent = "";
  try {
    const result = await api(`/api/admin/orders/${encodeURIComponent(targetOrder.id)}/mark-paid`, {
      method: "POST",
      body: JSON.stringify({ triggerShopFulfillment: isShopOrder && $("#trigger-shop-fulfillment").checked })
    });
    const callbackStatus = result.order?.callbackStatus || "";
    if (markPaidOrder === targetOrder) closeMarkPaid(true);
    await loadOrders();
    if (isShopOrder && result.shopFulfillmentTriggered && callbackStatus !== "success") {
      alert("订单已标记支付，但卡网回调未成功，请查看订单状态。");
    }
  } catch (ex) {
    if (ex.status === 401) { closeMarkPaid(true); return; }
    if (markPaidOrder === targetOrder) error.textContent = adminErrorMessage(ex);
  } finally {
    setMarkPaidBusy(false);
    button.textContent = "确认已收款";
  }
});

async function retryFulfillment(orderId) {
  if (retryingOrders.has(orderId)) return;
  const confirmed = await showConfirm("重试卡网发货", `将再次向卡网发送订单 ${orderId} 的发货回调。`);
  if (!confirmed) return;
  retryingOrders.add(orderId);
  renderOrders();
  try {
    const result = await api(`/api/admin/orders/${encodeURIComponent(orderId)}/mark-paid`, {
      method: "POST",
      body: JSON.stringify({ triggerShopFulfillment: true })
    });
    await loadOrders();
    if (result.order?.callbackStatus === "processing") alert("卡网回调正在处理中，请稍后刷新查看结果。");
    else if (result.order?.callbackStatus !== "success") alert("卡网回调仍未成功，请稍后重试。");
  } catch (ex) {
    if (ex.status === 401) return;
    alert(adminErrorMessage(ex));
  } finally {
    retryingOrders.delete(orderId);
    renderOrders();
  }
}

async function loadOrders() {
  const btn = $("#refresh");
  btn.disabled = true;
  try {
    orders = (await api("/api/admin/orders")).orders;
    renderOrders();
  } catch (e) { if (e.status === 401) setAuth(false); }
  finally { btn.disabled = false; }
}

// ==================== Users Management ====================
async function loadUsers() {
  try {
    const d = await api("/api/admin/users");
    users = d.users || [];
    renderUsers();
  } catch (e) { console.error("loadUsers", e); }
}

function renderUsers() {
  usersBody.replaceChildren(...users.map((u) => {
    const row = document.createElement("tr");
    row.innerHTML = `<td><strong>${esc(u.username)}</strong></td><td>${esc(u.displayName)}</td><td>${esc(roleLabel(u.role))}</td><td>${u.qrcode ? "已上传" : "未上传"}</td><td>${dt(u.createdAt)}</td><td>${u.username !== "admin" ? `<button class="secondary-button compact reset-pw-btn" data-user="${esc(u.username)}" type="button">改密</button><button class="secondary-button compact del-user-btn" data-user="${esc(u.username)}" type="button" style="color:#c83232;margin-left:6px">删除</button>` : ""}</td>`;
    return row;
  }));
  // Bind buttons
  $$(".reset-pw-btn").forEach((b) => b.addEventListener("click", () => openResetPw(b.dataset.user)));
  $$(".del-user-btn").forEach((b) => b.addEventListener("click", () => confirmDeleteUser(b.dataset.user)));
}

// ==================== Create User ====================
$("#create-user-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.currentTarget;
  const fd = new FormData(form);
  const err = $("#create-user-error");
  err.textContent = "";
  try {
    await api("/api/admin/users", { method: "POST", body: JSON.stringify(Object.fromEntries(fd)) });
    form.reset();
    await loadUsers();
  } catch (ex) {
    if (ex.status === 401) { resetPwDialog.close(); return; }
    err.textContent = ex.data?.error || ex.message;
  }
});

// ==================== Reset Password ====================
let resetPwTarget = "";
function openResetPw(user) {
  resetPwTarget = user;
  $("#reset-pw-target").textContent = "用户: " + user;
  $("#reset-pw-error").textContent = "";
  resetPwDialog.showModal();
}
$("#close-reset-pw").addEventListener("click", () => resetPwDialog.close());
$("#reset-pw-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const pw = new FormData(e.currentTarget).get("password");
  const err = $("#reset-pw-error");
  err.textContent = "";
  try {
    await api("/api/admin/users/" + resetPwTarget, { method: "PATCH", body: JSON.stringify({ password: String(pw) }) });
    resetPwDialog.close();
    await loadUsers();
  } catch (ex) {
    err.textContent = ex.data?.error || ex.message;
  }
});

// ==================== Delete User ====================
function confirmDeleteUser(user) {
  showConfirm("删除用户", "确定要删除用户 " + user + " 吗？其收款码也将被删除。此操作不可撤销。").then((ok) => {
    if (!ok) return;
    api("/api/admin/users/" + user, { method: "DELETE" }).then(() => {
      loadUsers();
    }).catch((ex) => alert(ex.data?.error === "merchant_has_orders" ? "该商户已有订单，需保留账户和订单归属" : "删除失败: " + (ex.data?.error || ex.message)));
  });
}

function showConfirm(title, msg) {
  return new Promise((resolve) => {
    confirmResolve = resolve;
    $("#confirm-title").textContent = title;
    $("#confirm-msg").textContent = msg;
    confirmDialog.showModal();
  });
}
$("#close-confirm").addEventListener("click", () => { confirmDialog.close(); confirmResolve?.(false); });
$("#confirm-cancel").addEventListener("click", () => { confirmDialog.close(); confirmResolve?.(false); });
$("#confirm-ok").addEventListener("click", () => { confirmDialog.close(); confirmResolve?.(true); });

// ==================== QR Code ====================
async function refreshQrPreview() {
  const img = $("#qr-current-img");
  const label = $("#qr-current-label");
  img.hidden = true;
  label.textContent = "正在加载...";
  try {
    const data = await api("/api/admin/qrcode");
    label.textContent = data.uploaded ? `当前收款码（${session.user}）` : (data.configured ? `默认收款码（${session.user}）` : "尚未上传收款码");
    if (data.url) { img.src = data.url + "&v=" + Date.now(); img.hidden = false; }
  } catch {
    label.textContent = "收款码加载失败";
  }
}

$("#upload-qr").addEventListener("click", async () => {
  const file = $("#qr-file-input").files[0];
  const err = $("#qr-upload-error");
  const ok = $("#qr-upload-success");
  err.textContent = "";
  ok.hidden = true;
  if (!file) { err.textContent = "请选择图片文件"; return; }
  if (file.size > 5242880) { err.textContent = "文件不能超过 5MB"; return; }
  if (!file.type.startsWith("image/")) { err.textContent = "请选择图片文件"; return; }
  try {
    const buf = await file.arrayBuffer();
    const result = await api("/api/admin/qrcode?user=" + encodeURIComponent(session.user), { method: "POST", body: new Uint8Array(buf) });
    ok.hidden = false;
    ok.textContent = "上传成功! URL: " + result.url;
    refreshQrPreview();
    if (session.role === "super") loadUsers();
  } catch (ex) {
    err.textContent = ex.data?.error || ex.message;
  }
});

// ==================== Settings / SmsF Config ====================
async function loadSmsfConfig() {
  try {
    const d = await api("/api/admin/smsf-config");
    $("#cfg-webhook").textContent = d.webhookUrl;
    $("#cfg-secret").textContent = d.secret;
  } catch (e) { console.error("loadSmsfConfig", e); }
}

async function loadSmsfEvents() {
  const body = $("#smsf-events-body");
  const emptyState = $("#smsf-events-empty");
  try {
    const events = (await api("/api/admin/smsf-events")).events || [];
    body.replaceChildren(...events.map((event) => {
      const row = document.createElement("tr");
      const amounts = (event.amountsFen || []).map(money).join("、") || "-";
      row.innerHTML = `<td>${dt(event.receivedAt)}</td><td>${amounts}</td><td>${event.payee || "-"}</td><td>${smsfResultMap[event.result] || event.result}</td><td>${event.orderId || "-"}</td>`;
      return row;
    }));
    emptyState.hidden = events.length > 0;
  } catch (e) {
    console.error("loadSmsfEvents", e);
  }
}

$("#refresh-smsf-events").addEventListener("click", loadSmsfEvents);

$$(".copy-btn").forEach((btn) => {
  btn.addEventListener("click", () => {
    const target = $("#" + btn.dataset.copy);
    if (!target) return;
    navigator.clipboard.writeText(target.textContent).then(() => {
      btn.textContent = "已复制";
      setTimeout(() => { btn.textContent = "复制"; }, 1500);
    });
  });
});

// ==================== Create Order ====================
$("#open-create").addEventListener("click", () => createDialog.showModal());
$("#close-create").addEventListener("click", () => createDialog.close());
$("#create-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.currentTarget;
  const fd = new FormData(form);
  const err = $("#create-error");
  err.textContent = "";
  try {
    const result = await api("/api/admin/orders", { method: "POST", body: JSON.stringify(Object.fromEntries(fd)) });
    createDialog.close();
    form.reset();
    $("#created-link").href = result.paymentUrl;
    $("#created-link").textContent = result.paymentUrl;
    createdDialog.showModal();
    await loadOrders();
  } catch (ex) {
    if (ex.status === 401) { createDialog.close(); return; }
    err.textContent = ex.data?.error === "qrcode_required" ? "请先在“收款码”页面上传你自己的微信收款码" : "请填写正确的金额（最多两位小数）";
  }
});
$("#copy-link").addEventListener("click", async () => {
  await navigator.clipboard.writeText($("#created-link").href);
  $("#copy-link").textContent = "已复制";
});
$("#open-link").addEventListener("click", () => window.open($("#created-link").href, "_blank", "noopener"));
createdDialog.addEventListener("click", (e) => { if (e.target === createdDialog) createdDialog.close(); });

// ==================== Login / Logout ====================
$("#login-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = e.currentTarget;
  const submit = form.querySelector('button[type="submit"]');
  const fd = new FormData(form);
  const err = $("#login-error");
  err.textContent = "";
  submit.disabled = true;
  submit.textContent = "登录中...";
  try {
    await api("/api/admin/login", { method: "POST", body: JSON.stringify(Object.fromEntries(fd)) });
    form.reset();
    window.location.replace("/admin?logged_in=" + Date.now());
  } catch (ex) {
    err.textContent = ex.data?.error === "too_many_attempts" ? "尝试次数过多，请稍等片刻再试" : (ex.data?.error === "invalid_credentials" ? "用户名或密码不正确" : "登录请求失败，请重试");
    submit.disabled = false;
    submit.textContent = "登录";
  }
});

logoutBtn.addEventListener("click", async () => {
  await api("/api/admin/logout", { method: "POST" }).catch(() => {});
  setAuth(false);
});

// ==================== Search/Filter ====================
$("#search").addEventListener("input", renderOrders);
$("#status-filter").addEventListener("change", renderOrders);
$("#refresh").addEventListener("click", loadOrders);

// ==================== Init ====================
checkSession();
