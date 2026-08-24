'use strict';

const crypto = require('node:crypto');
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const PUBLIC_DIR = path.join(__dirname, 'public');
const LEGACY_QRCODE_DIR = path.join(PUBLIC_DIR, 'qrcodes');
const SMSF_DUP_WIN = 5000;
const DEFAULT_PAYMENT_MINUTES = Number(process.env.PAY_ORDER_TTL_MINUTES || 20);
const SETTLEMENT_GRACE_MINUTES = Number(process.env.PAY_SETTLEMENT_GRACE_MINUTES || 5);
const MAX_MANUAL_PAYMENT_MINUTES = 24 * 60;
const CALLBACK_LEASE = 2 * 60 * 1000;
const SMSF_EVENT_LIMIT = 500;
const MAX_AMOUNT_FEN = 99999999;
const CT = {'.css':'text/css; charset=utf-8','.html':'text/html; charset=utf-8','.ico':'image/x-icon','.jpg':'image/jpeg','.jpeg':'image/jpeg','.js':'text/javascript; charset=utf-8','.png':'image/png','.svg':'image/svg+xml'};

function secureEq(a,b){const x=Buffer.from(a||'','utf8'),y=Buffer.from(b||'','utf8');return x.length===y.length&&crypto.timingSafeEqual(x,y)}
function rdSec(v,f){if(v)return String(v).trim();if(f)return fs.readFileSync(f,'utf8').trim();return''}
function signPayload(sec,ts,b){return crypto.createHmac('sha256',sec).update(ts+'.').update(b).digest('hex')}
function signSmsF(sec,ts){return crypto.createHmac('sha256',sec).update(ts+'\n'+sec).digest('base64')}
function yuanToFen(v){const m=/^(\d+)(?:\.(\d{1,2}))?$/.exec(v);if(!m)return null;const f=Number(m[1])*100+Number((m[2]||'').padEnd(2,'0'));return Number.isSafeInteger(f)?f:null}
// Amounts crossing the shop boundary are integer fen. Keep parsing strict so
// values such as scientific notation, decimals, and unsafe integers cannot
// silently become a different amount through Number().
function parseAmountFen(value){
if(typeof value==='number')return Number.isSafeInteger(value)?value:null;
if(typeof value!=='string'||!/^(?:0|[1-9]\d*)$/.test(value))return null;
const amount=Number(value);return Number.isSafeInteger(amount)?amount:null
}
function extractAmts(t){const a=new Set();const p=/(?:[\u00a5\uffe5]\s*(\d+(?:\.\d{1,2})?)|(\d+(?:\.\d{1,2})?)\s*\u5143)/g;for(const m of t.matchAll(p)){const f=yuanToFen(m[1]||m[2]);if(f!==null)a.add(f)}return[...a]}
function sendJson(res,code,data){const b=JSON.stringify(data);res.writeHead(code,{'Cache-Control':'no-store','Content-Length':Buffer.byteLength(b),'Content-Type':'application/json; charset=utf-8'});res.end(b)}
function sendHtml(res,code,body){res.writeHead(code,{'Cache-Control':'no-store','Content-Length':Buffer.byteLength(body),'Content-Type':'text/html; charset=utf-8','X-Content-Type-Options':'nosniff'});res.end(body)}
function readBody(req,lim=524288){return new Promise((resolve,reject)=>{const chunks=[];let s=0;req.on('data',c=>{s+=c.length;if(s>lim){reject(Object.assign(new Error('too large'),{statusCode:413}));req.destroy();return}chunks.push(c)});req.on('end',()=>resolve(Buffer.concat(chunks)));req.on('error',reject)})}
function parseCookies(req){return Object.fromEntries(String(req.headers.cookie||'').split(';').map(i=>i.trim().split('=')).filter(([k,v])=>k&&v).map(([k,v])=>[k,decodeURIComponent(v)]))}

function loadState(f){try{const p=JSON.parse(fs.readFileSync(f,'utf8'));return{orders:Array.isArray(p.orders)?p.orders.slice(-2000):[],smsfEvents:Array.isArray(p.smsfEvents)?p.smsfEvents.slice(-SMSF_EVENT_LIMIT):[],txns:Array.isArray(p.transactions)?p.transactions.slice(-500):Array.isArray(p.txns)?p.txns.slice(-500):[],users:Array.isArray(p.users)?p.users:[]}}catch(e){if(e.code!=='ENOENT')console.error('state:',e);return{orders:[],smsfEvents:[],txns:[],users:[]}}}
function saveState(f,s){const d=path.dirname(f);fs.mkdirSync(d,{recursive:true});const t=f+'.'+process.pid+'.tmp';const stored={orders:s.orders,smsfEvents:s.smsfEvents,transactions:s.txns,users:s.users};fs.writeFileSync(t,JSON.stringify(stored,null,2),{mode:0o600});fs.renameSync(t,f)}
function pubOrder(o,status=o.status){return{amountFen:o.amountFen,baseAmountFen:o.baseAmountFen||o.amountFen,callbackStartedAt:o.callbackStartedAt||null,callbackStatus:o.callbackStatus||'',callbackSuppressedAt:o.callbackSuppressedAt||null,callbackSuppressionError:o.callbackSuppressionError||'',createdAt:o.createdAt,expiresAt:o.expiresAt||null,externalStatus:o.externalStatus||'',id:o.id,manualOnly:!!o.manualOnly,manualPaidBy:o.manualPaidBy||'',manualSuppressUrl:o.manualSuppressUrl||'',matchExpiresAt:o.matchExpiresAt||null,paidAt:o.paidAt||null,paymentMethod:o.paymentMethod||'',paymentMethodOriginal:o.paymentMethodOriginal||o.paymentMethod||'',returnUrl:o.returnUrl||'',source:o.source,status,title:o.title,payee:o.payee||''}}
function normalizePaymentMethod(value,name=''){
const raw=String(value||'').trim().toLowerCase();const label=String(name||'').trim();
if(raw==='alipay'||raw==='aliweb'||raw==='alipayscan'||raw==='aliwap'||raw==='zfbf2f'||raw.includes('alipay')||raw.includes('zfb')||label.includes('支付宝'))return'alipay';
if(raw==='newzoe-wechat'||raw==='wechat'||raw.includes('wechat')||label.includes('微信'))return'wechat';
if(raw==='binancepay'||raw.includes('binance')||label.includes('币安'))return'binancepay';
return raw;
}
function shopStatusKey(value){
const raw=String(value??'').trim().toLowerCase();
if(raw==='-1'||raw==='expired')return'expired';
if(raw==='1'||raw==='wait_pay'||raw==='pending')return'pending';
if(raw==='2'||raw==='3'||raw==='paid'||raw==='processing')return'paid';
if(raw==='4'||raw==='completed')return'completed';
if(raw==='5'||raw==='failure'||raw==='failed')return'failed';
if(raw==='6'||raw==='abnormal')return'abnormal';
return raw||'pending';
}
function shopStatusSettled(value){
const status=shopStatusKey(value);
return status==='paid'||status==='completed';
}
function sameOriginUrl(value,fallback,allowedOrigin){
try{const parsed=new URL(String(value||''));const base=new URL(String(allowedOrigin));if(parsed.origin!==base.origin||parsed.protocol!==base.protocol)return fallback;return parsed.toString()}catch{return fallback}
}

function hashPw(pw){const s=crypto.randomBytes(16).toString('hex');return s+':'+crypto.scryptSync(pw,s,64).toString('hex')}
function verifyPw(pw,stored){const[s,h]=stored.split(':');if(!s||!h)return false;return secureEq(h,crypto.scryptSync(pw,s,64).toString('hex'))}
function seedUsers(s){if(s.users.length===0){s.users.push({username:'admin',password:hashPw('admin'),role:'super',displayName:'\u8d85\u7ea7\u7ba1\u7406\u5458',qrcode:null,createdAt:new Date().toISOString()})}}
function userByName(s,name){return s.users.find(u=>u.username===name)}

function createPayServer(opts={}){
const secret=opts.secret||rdSec(process.env.PAY_NOTIFY_SECRET,process.env.PAY_NOTIFY_SECRET_FILE);
if(!secret||secret.length<32)throw new Error('PAY_NOTIFY_SECRET too short');
const smsfSecret=opts.smsfSecret??rdSec(process.env.SMSF_NOTIFY_SECRET,process.env.SMSF_NOTIFY_SECRET_FILE);
const shopSecret=opts.shopSecret??rdSec(process.env.SHOP_API_SECRET,process.env.SHOP_API_SECRET_FILE);
if(shopSecret&&shopSecret.length<32)throw new Error('SHOP_API_SECRET too short');
const superPassword=opts.adminPassword??rdSec(process.env.PAY_ADMIN_PASSWORD,process.env.PAY_ADMIN_PASSWORD_FILE);
const sessSecret=opts.sessionSecret||rdSec(process.env.PAY_ADMIN_SESSION_SECRET,process.env.PAY_ADMIN_SESSION_SECRET_FILE)||secret;
const shopOrdUrl=opts.shopOrdersUrl??process.env.SHOP_ORDERS_URL??'';
const cbOrigin=opts.allowedCallbackOrigin??process.env.SHOP_CALLBACK_ORIGIN??'https://shop.newzoe.cloud';
const stateFile=opts.stateFile||process.env.PAY_STATE_FILE||path.join(__dirname,'data','state.json');
const qrcodeDir=opts.qrcodeDir||process.env.PAY_QRCODE_DIR||path.join(path.dirname(stateFile),'qrcodes');
const now=opts.now||(()=>Date.now());
const startupNow=now();
const fetchImpl=opts.fetchImpl||global.fetch;
const paymentMinutes=Number.isFinite(Number(opts.paymentMinutes))?Number(opts.paymentMinutes):DEFAULT_PAYMENT_MINUTES;
const settlementGraceMinutes=Number.isFinite(Number(opts.settlementGraceMinutes))?Number(opts.settlementGraceMinutes):SETTLEMENT_GRACE_MINUTES;
if(!Number.isFinite(paymentMinutes)||!Number.isFinite(settlementGraceMinutes)||paymentMinutes<1||settlementGraceMinutes<0)throw new Error('invalid payment expiry configuration');
const paymentWindow=Math.round(paymentMinutes*60*1000);
const settlementGrace=Math.round(settlementGraceMinutes*60*1000);
const clients=new Map();
const state=loadState(stateFile);
seedUsers(state);
if(superPassword){const a=userByName(state,'admin');if(a)a.password=hashPw(superPassword)}
let stateMigrated=false;
for(const order of state.orders){
const expectedPayee=order.source==='dujiaoka'?'admin':(order.payee||'admin');
  const canonicalId=String(order.id||'').toUpperCase();
  if(canonicalId&&order.id!==canonicalId){order.id=canonicalId;stateMigrated=true}
  if(order.payee!==expectedPayee){order.payee=expectedPayee;stateMigrated=true}
if(!Number.isInteger(order.baseAmountFen)&&Number.isInteger(order.amountFen)){order.baseAmountFen=order.amountFen;stateMigrated=true}
if(order.status==='paid'&&!order.settledAt&&(order.updatedAt||order.paidAt)){order.settledAt=order.updatedAt||order.paidAt;stateMigrated=true}
const reference=Date.parse(order.activatedAt||order.createdAt||'');
const expires=Date.parse(order.expiresAt||'');
if(!Number.isFinite(expires)&&Number.isFinite(reference)){order.expiresAt=new Date(reference+paymentWindow).toISOString();stateMigrated=true}
const matchExpires=Date.parse(order.matchExpiresAt||'');
const normalizedExpires=Date.parse(order.expiresAt||'');
if(!Number.isFinite(matchExpires)&&Number.isFinite(normalizedExpires)){order.matchExpiresAt=new Date(normalizedExpires+settlementGrace).toISOString();stateMigrated=true}
}
// Settled amounts and already-inactive orders are immutable. Repair active
// pending collisions deterministically without silently changing a displayed
// amount: the later order is quarantined and re-registration allocates anew.
const occupiedByPayee=new Map();
for(const order of state.orders){
const matchExpires=Date.parse(order.matchExpiresAt||'');
if(order.status!=='paid'||!Number.isInteger(order.amountFen)||!Number.isFinite(matchExpires)||matchExpires<=startupNow)continue;
const payee=order.payee||'admin';const occupied=occupiedByPayee.get(payee)||new Set();occupied.add(order.amountFen);occupiedByPayee.set(payee,occupied)
}
const activePending=state.orders.filter(order=>{
const matchExpires=Date.parse(order.matchExpiresAt||'');
return order.status==='pending'&&!order.autoMatchDisabledAt&&Number.isInteger(order.amountFen)&&Number.isFinite(matchExpires)&&matchExpires>startupNow
}).sort((a,b)=>{
const aRef=Date.parse(a.activatedAt||a.createdAt||'');const bRef=Date.parse(b.activatedAt||b.createdAt||'');
const byTime=(Number.isFinite(aRef)?aRef:Number.MAX_SAFE_INTEGER)-(Number.isFinite(bRef)?bRef:Number.MAX_SAFE_INTEGER);
if(byTime)return byTime;const aId=String(a.id||''),bId=String(b.id||'');return aId===bId?0:(aId<bId?-1:1)
});
for(const order of activePending){
const payee=order.payee||'admin';const occupied=occupiedByPayee.get(payee)||new Set();
if(occupied.has(order.amountFen)){order.autoMatchDisabledAt=new Date(startupNow).toISOString();order.updatedAt=order.autoMatchDisabledAt;stateMigrated=true;continue}
occupied.add(order.amountFen);occupiedByPayee.set(payee,occupied)
}
for(const user of state.users){if(user.username!=='admin'&&!user.smsfSecret){user.smsfSecret=crypto.randomBytes(32).toString('hex');stateMigrated=true}}
const txnRecords=new Map(state.txns.filter(i=>i&&i.transactionId).map(i=>[i.transactionId,i]));
// Keep transaction identity available even after the compact transaction
// audit list rolls over. Paid orders are the durable source of truth.
for(const order of state.orders){
if(order.status==='paid'&&order.transactionId&&!txnRecords.has(order.transactionId))txnRecords.set(order.transactionId,{amountFen:order.amountFen,orderId:order.id,paidAt:order.paidAt||order.settledAt||'',transactionId:order.transactionId})
}
const txnIds=new Set(txnRecords.keys());
const recentSmsF=new Map();
const loginAttempts=new Map();
fs.mkdirSync(qrcodeDir,{recursive:true});

function persist(){if(state.orders.length>2000)state.orders.splice(0,state.orders.length-2000);if(state.smsfEvents.length>SMSF_EVENT_LIMIT)state.smsfEvents.splice(0,state.smsfEvents.length-SMSF_EVENT_LIMIT);if(state.txns.length>500)state.txns.splice(0,state.txns.length-500);saveState(stateFile,state)}
if(stateMigrated)persist();
  function orderById(id){const key=String(id||'').toUpperCase();return state.orders.find(i=>String(i.id||'').toUpperCase()===key)}
function orderPayee(order){return order?.payee||'admin'}
function orderIsInactive(order){return order?.status==='pending'&&!!order.autoMatchDisabledAt}
function deadline(order,field){const value=Date.parse(order?.[field]||'');return Number.isFinite(value)?value:0}
function orderPaymentExpired(order){return !!order&&deadline(order,'expiresAt')<=now()}
function orderMatchExpired(order){return !!order&&deadline(order,'matchExpiresAt')<=now()}
function publicOrder(order){
let status=order.status;
if(order.manualOnly&&shopStatusSettled(order.externalStatus))status='paid';
else if(orderIsInactive(order))status='inactive';
else if(order.status==='pending'&&orderMatchExpired(order))status='expired';
else if(order.status==='pending'&&orderPaymentExpired(order))status='confirming';
return pubOrder(order,status)
}
function orderOccupiesAmount(order,payee,excludeId=''){
if(order?.manualOnly)return false;
if(order.id===excludeId||orderPayee(order)!==payee||!Number.isInteger(order.amountFen))return false;
return ['pending','paid'].includes(order.status)&&!orderMatchExpired(order)
}
function allocateAmountFen(baseAmountFen,payee,excludeId=''){
const occupied=new Set(state.orders.filter(order=>orderOccupiesAmount(order,payee,excludeId)).map(order=>order.amountFen));
let amountFen=baseAmountFen;
while(amountFen<=MAX_AMOUNT_FEN&&occupied.has(amountFen))amountFen++;
return amountFen<=MAX_AMOUNT_FEN?amountFen:null
}
function payeeQrPath(username){
const user=userByName(state,username);
if(user?.qrcode){const current=path.join(qrcodeDir,user.qrcode);if(fs.existsSync(current))return current;const legacy=path.join(LEGACY_QRCODE_DIR,user.qrcode);if(fs.existsSync(legacy))return legacy}
if(username==='admin'){const fallback=path.join(PUBLIC_DIR,'wechat-pay.jpg');if(fs.existsSync(fallback))return fallback}
return''}
function payeeQrUrl(username){return payeeQrPath(username)?'/wechat-pay.jpg?user='+encodeURIComponent(username):''}
function sendEvent(res,ev,data){res.write('event: '+ev+'\ndata: '+JSON.stringify(data)+'\n\n')}
function broadcast(pmt,cid,oid){let d=0;for(const[id,ss]of clients.entries()){if(cid&&id!==cid)continue;for(const s of ss){if(oid&&s.orderId!==oid)continue;if(!oid&&s.orderId)continue;sendEvent(s.res,'payment',pmt);d++}}return d}
function validSigned(req,body,sec){const ts=String(req.headers['x-shop-timestamp']||'');const sig=String(req.headers['x-shop-signature']||'');const tms=Number(ts);if(!Number.isFinite(tms)||Math.abs(now()-tms)>300000)return false;return secureEq(sig,signPayload(sec,ts,body))}

function makeSession(user){const exp=now()+43200000;const pl=Buffer.from(JSON.stringify({expiresAt:exp,user})).toString('base64url');const sig=crypto.createHmac('sha256',sessSecret).update(pl).digest('base64url');return pl+'.'+sig}
function sessUser(req){const tok=parseCookies(req).newzoe_pay_admin||'';const[pl,sig]=tok.split('.');if(!pl||!sig)return null;const exp=crypto.createHmac('sha256',sessSecret).update(pl).digest('base64url');if(!secureEq(sig,exp))return null;try{const s=JSON.parse(Buffer.from(pl,'base64url').toString('utf8'));if(s.expiresAt>now())return s.user}catch{}return null}
function currentUserObj(req){const u=sessUser(req);return u?userByName(state,u):null}
function validAdminOrigin(req){
const origin=String(req.headers.origin||'');
if(!origin)return true;
const expectedHost=String(req.headers['x-forwarded-host']||req.headers.host||'').split(',')[0].trim();
const expectedProto=String(req.headers['x-forwarded-proto']||(req.socket.encrypted?'https':'http')).split(',')[0].trim();
try{const parsed=new URL(origin);return parsed.host===expectedHost&&parsed.protocol===expectedProto+':'}catch{return false}
}

async function registerShopOrder(req,res){
if(!shopSecret)return sendJson(res,503,{error:'shop_api_not_configured'});
const body=await readBody(req);
if(!validSigned(req,body,shopSecret))return sendJson(res,401,{error:'invalid_signature'});
let p;try{p=JSON.parse(body.toString('utf8'))}catch{return sendJson(res,400,{error:'invalid_json'})}
const id=String(p.orderId||'').toUpperCase();
const af=parseAmountFen(p.amountFen);
const title=String(p.title||'\u8ba2\u5355').trim().slice(0,160);
const cbUrl=String(p.callbackUrl||'');
const retUrl=String(p.returnUrl||'');
const requestedExpires=p.expiresAt?Date.parse(p.expiresAt):NaN;
const requestedMatchExpires=p.matchExpiresAt?Date.parse(p.matchExpiresAt):NaN;
const expiresAt=Number.isFinite(requestedExpires)?requestedExpires:now()+paymentWindow;
const matchExpiresAt=Number.isFinite(requestedMatchExpires)?requestedMatchExpires:expiresAt+settlementGrace;
try{const cb=new URL(cbUrl);if(cb.origin!==cbOrigin)throw new Error('origin')}catch{return sendJson(res,400,{error:'invalid_callback'})}
if(!/^[A-Z0-9_-]{8,64}$/.test(id)||!Number.isInteger(af)||af<1||af>MAX_AMOUNT_FEN)return sendJson(res,400,{error:'invalid_order'});
if(!Number.isFinite(expiresAt)||!Number.isFinite(matchExpiresAt)||matchExpiresAt<expiresAt)return sendJson(res,400,{error:'invalid_expiry'});
let order=orderById(id);
if(order&&order.status==='paid'){if(order.payee!=='admin'){order.payee='admin';persist()}return sendJson(res,200,{order:pubOrder(order),paymentUrl:'https://pay.newzoe.cloud/'+id})}
if((order&&orderPaymentExpired(order))||(!order&&expiresAt<=now()))return sendJson(res,410,{error:'order_expired'});
const created=!order;
const updateTime=new Date(now()).toISOString();
if(!order){
const amountFen=allocateAmountFen(af,'admin');
if(amountFen===null)return sendJson(res,409,{error:'amount_unavailable'});
order={activatedAt:updateTime,amountFen,baseAmountFen:af,createdAt:updateTime,expiresAt:new Date(expiresAt).toISOString(),id,matchExpiresAt:new Date(matchExpiresAt).toISOString()};state.orders.push(order)
}else{
const reactivating=!!order.autoMatchDisabledAt;
const collides=state.orders.some(candidate=>candidate.id!==order.id&&!candidate.autoMatchDisabledAt&&orderOccupiesAmount(candidate,'admin')&&candidate.amountFen===order.amountFen);
if(collides||reactivating){
const amountFen=allocateAmountFen(reactivating?af:(order.baseAmountFen||af),'admin',order.id);
if(amountFen===null)return sendJson(res,409,{error:'amount_unavailable'});
order.amountFen=amountFen;
}
if(reactivating){order.baseAmountFen=af;delete order.autoMatchDisabledAt}
}
const requestedMethod=normalizePaymentMethod(p.paymentMethod||p.payment_method||'wechat',p.paymentName||'');
if(order.manualOnly&&order.paymentMethodOriginal==='alipay'&&requestedMethod!=='alipay')return sendJson(res,409,{error:'payment_method_conflict'});
Object.assign(order,{baseAmountFen:order.baseAmountFen||af,callbackStatus:order.callbackStatus||'waiting',callbackUrl:cbUrl,manualOnly:requestedMethod==='alipay',paymentMethod:requestedMethod,paymentMethodOriginal:requestedMethod,returnUrl:retUrl,source:'dujiaoka',status:'pending',title,payee:'admin',updatedAt:updateTime});
persist();
return sendJson(res,created?201:200,{order:pubOrder(order),paymentUrl:'https://pay.newzoe.cloud/'+id});
}

async function cbShop(order,tid,{manualOverride=false,manualFulfilled=false}={}){
if(order.source!=='dujiaoka'||!order.callbackUrl||!shopSecret||(!manualFulfilled&&order.callbackSuppressedAt)||(!manualFulfilled&&order.callbackStatus==='success'))return{attempted:false};
const priorStarted=Date.parse(order.callbackStartedAt||0);
if(order.callbackStatus==='processing'&&Number.isFinite(priorStarted)&&priorStarted>now()-CALLBACK_LEASE)return{attempted:false,inProgress:true};
order.callbackStatus='processing';order.callbackStartedAt=new Date(now()).toISOString();order.callbackAttempts=Number(order.callbackAttempts||0)+1;order.updatedAt=order.callbackStartedAt;persist();
const pl=JSON.stringify({amountFen:order.baseAmountFen||order.amountFen,matchedAt:order.settledAt,paidAmountFen:order.amountFen,orderId:order.id,paidAt:order.paidAt,transactionId:tid,...(manualOverride?{manualOverride:true}:{}),...(manualFulfilled?{manualFulfilled:true}:{})});
const ts=String(now());
try{const r=await fetchImpl(order.callbackUrl,{body:pl,headers:{'content-type':'application/json','x-shop-signature':signPayload(shopSecret,ts,Buffer.from(pl)),'x-shop-timestamp':ts},method:'POST',signal:AbortSignal.timeout(15000)});order.callbackStatus=r.ok?'success':'http_'+r.status}catch(e){order.callbackStatus='error';console.error('cb fail',order.id,e.message)}
order.updatedAt=new Date(now()).toISOString();persist()
return{attempted:true,success:order.callbackStatus==='success'}
}
async function suppressShop(order,actor){
if(order.source!=='dujiaoka'||!shopSecret)return{attempted:false,success:false,error:'shop_suppression_unavailable'};
// This endpoint only records that an admin fulfilled an Alipay order. Never
// send the marker to the payment gateway callback, which expects a provider
// transaction payload and would otherwise reject it.
const callbackUrl=sameOriginUrl(order.manualSuppressUrl||order.suppressUrl,`${cbOrigin}/api/newzoe/manual-suppress`,cbOrigin);
const pl=JSON.stringify({orderId:order.id,manualFulfilled:true,manualFulfilledAt:order.callbackSuppressedAt||new Date(now()).toISOString(),manualFulfilledBy:String(actor||'pay-admin').slice(0,64)});
const ts=String(now());
try{const r=await fetchImpl(callbackUrl,{body:pl,headers:{'content-type':'application/json','x-shop-signature':signPayload(shopSecret,ts,Buffer.from(pl)),'x-shop-timestamp':ts},method:'POST',signal:AbortSignal.timeout(15000)});return{attempted:true,success:r.ok,status:r.status}}catch(e){console.error('suppress fail',order.id,e.message);return{attempted:true,success:false,error:e.message}}
}
function recordManualSuppression(order,actor,at){
order.callbackStatus='manual_fulfilled';
order.callbackSuppressedAt=at;
order.callbackSuppressedBy=String(actor||'pay-admin').slice(0,64);
delete order.callbackSuppressionError;
order.updatedAt=at;
}

async function settleOrder(order,paidAt,tid,{triggerShopCallback=true,manualOverride=false}={}){
if(order.status==='paid')return{duplicate:true,delivered:0};
order.status='paid';order.paidAt=paidAt;order.settledAt=new Date(now()).toISOString();order.transactionId=tid;
order.updatedAt=order.settledAt;
const txn={amountFen:order.amountFen,baseAmountFen:order.baseAmountFen||order.amountFen,orderId:order.id,paidAt,transactionId:tid};
state.txns.push(txn);txnIds.add(tid);txnRecords.set(tid,txn);persist();
const pmt={amountFen:order.amountFen,orderId:order.id,paidAt,status:'paid',test:false};
const d=broadcast(pmt,'',order.id);
if(triggerShopCallback)await cbShop(order,tid,{manualOverride});
return{delivered:d,duplicate:false}
}

function findPending(amts,payee='admin',sourceTime=NaN){
return state.orders.filter(o=>{
 if(o.status!=='pending'||o.manualOnly||o.autoMatchDisabledAt||orderMatchExpired(o)||orderPayee(o)!==payee||!amts.includes(o.amountFen))return false;
if(!Number.isFinite(sourceTime))return true;
const reference=Date.parse(o.createdAt||o.activatedAt||'');return !Number.isFinite(reference)||sourceTime>=reference
}).sort((a,b)=>Date.parse(a.activatedAt||a.createdAt)-Date.parse(b.activatedAt||b.createdAt))[0]
}

function recordSmsfEvent(data){
const event={id:crypto.randomBytes(8).toString('hex'),receivedAt:new Date(now()).toISOString(),...data};
state.smsfEvents.push(event);persist();
console.info('SmsF',JSON.stringify(event));
return event
}

async function handleNotify(req,res){
const body=await readBody(req);
const ts=req.headers['x-pay-timestamp'];const sig=req.headers['x-pay-signature'];
const tms=Number(ts)*1000;
if(!Number.isFinite(tms)||Math.abs(now()-tms)>300000)return sendJson(res,401,{error:'invalid_timestamp'});
if(!secureEq(sig,signPayload(secret,ts,body)))return sendJson(res,401,{error:'invalid_signature'});
let p;try{p=JSON.parse(body.toString('utf8'))}catch{return sendJson(res,400,{error:'invalid_json'})}
const af=parseAmountFen(p.amountFen);const tid=String(p.transactionId||'');
const cid=p.clientId?String(p.clientId):'';
const oid=p.orderId?String(p.orderId).toUpperCase():'';
const isTest=p.mode==='test';
if(!Number.isSafeInteger(af)||af<1||af>MAX_AMOUNT_FEN||!/^[A-Za-z0-9_.:-]{8,128}$/.test(tid))return sendJson(res,400,{error:'invalid_payment'});
if(cid&&!/^[A-Za-z0-9_-]{16,80}$/.test(cid))return sendJson(res,400,{error:'invalid_client'});
if(isTest&&!cid)return sendJson(res,400,{error:'test_requires_client'});
const order=oid?orderById(oid):null;
const priorTxn=txnRecords.get(tid);
if(priorTxn){
if(order&&priorTxn.orderId===order.id&&priorTxn.amountFen===af)return sendJson(res,200,{accepted:true,delivered:0,duplicate:true,matched:true});
return sendJson(res,409,{accepted:false,error:'transaction_conflict',matched:false})
}
if(order){
if(order.manualOnly)return sendJson(res,202,{accepted:true,matched:false,reason:'manual_order_required'});
if(order.status!=='paid'&&orderMatchExpired(order))return sendJson(res,410,{error:'order_expired'});
if(order.amountFen!==af)return sendJson(res,202,{accepted:true,matched:false});
const r=await settleOrder(order,p.paidAt||new Date(now()).toISOString(),tid);return sendJson(res,200,{accepted:true,delivered:r.delivered,duplicate:r.duplicate,matched:true})
}
if(!isTest)return sendJson(res,202,{accepted:true,matched:false,reason:'order_required'});
const pmt={amountFen:af,paidAt:p.paidAt||new Date(now()).toISOString(),status:'paid',test:isTest};
return sendJson(res,200,{accepted:true,delivered:broadcast(pmt,cid),matched:true,test:isTest})
}

async function handleSmsFNotify(req,res,url){
const requestedPayee=String(url.searchParams.get('user')||'admin').trim().toLowerCase();
const payeeUser=userByName(state,requestedPayee);
if(!payeeUser)return sendJson(res,404,{error:'payee_not_found'});
const payee=payeeUser.username;
const payeeSmsfSecret=payee==='admin'?smsfSecret:payeeUser.smsfSecret;
if(!payeeSmsfSecret)return sendJson(res,503,{error:'smsf_not_configured'});
const body=await readBody(req);
const ct=String(req.headers['content-type']||'').toLowerCase();
let p;try{p=ct.includes('application/json')?JSON.parse(body.toString('utf8')):Object.fromEntries(new URLSearchParams(body.toString('utf8')))}catch{return sendJson(res,400,{error:'invalid_payload'})}
const ts=String(p.timestamp||'');const tms=Number(ts);
if(!Number.isFinite(tms)||Math.abs(now()-tms)>300000)return sendJson(res,401,{error:'invalid_timestamp'});
let sig=String(p.sign||'').replaceAll(' ','+');try{sig=decodeURIComponent(sig)}catch{}
if(!secureEq(sig,signSmsF(payeeSmsfSecret,ts)))return sendJson(res,401,{error:'invalid_signature'});
const pkg=String(p.from||p.packageName||'');
const title=String(p.title||'');const content=String(p.content||p.msg||'');
const ntfTxt=title+'\n'+content;
const amts=extractAmts(ntfTxt);
const isWx=pkg==='com.tencent.mm';
const hasTrust=/微信支付|微信收款助手|收款助手/.test(ntfTxt);
const hasPmt=/收款|到账|支付成功|已支付/.test(ntfTxt);
const fp=crypto.createHash('sha256').update(payee).update('\0').update(pkg).update('\0').update(ntfTxt).digest('hex');
const sourceRaw=String(p.receive_time||p.receiveTime||'').trim();
let sourceTime=NaN;let hasSourceTime=false;
if(sourceRaw){
const numeric=Number(sourceRaw);
if(Number.isFinite(numeric)){
sourceTime=Math.abs(numeric)<1e11?numeric*1000:numeric;hasSourceTime=true
}else{
const parsed=Date.parse(sourceRaw);if(Number.isFinite(parsed)){sourceTime=parsed;hasSourceTime=true}
}
}
if(!Number.isFinite(sourceTime)||!Number.isFinite(new Date(sourceTime).getTime())){sourceTime=tms;hasSourceTime=false}
const sourceIso=new Date(sourceTime).toISOString();
const transactionId=hasSourceTime
?'smsf-'+crypto.createHash('sha256').update('smsf\0').update(payee).update('\0').update(pkg).update('\0').update(ntfTxt).update('\0').update(sourceIso).digest('hex')
:'smsf-'+crypto.createHash('sha256').update(payee).update('\0').update(pkg).update('\0').update(ntfTxt).update('\0').update(ts).digest('hex');
const notificationAt=sourceIso;
const prev=recentSmsF.get(fp);
const prior=state.smsfEvents.findLast(event=>event.fingerprint===fp);
const priorExact=state.smsfEvents.findLast(event=>event.fingerprint===fp&&event.notificationAt===notificationAt);
const persistedTxn=txnRecords.get(transactionId);
const baseAudit={amountsFen:amts,fingerprint:fp,notificationAt,packageName:pkg,payee,title:title.slice(0,80)};
if(isWx&&hasTrust&&hasPmt&&persistedTxn){recordSmsfEvent({...baseAudit,orderId:persistedTxn.orderId||'',matched:true,result:'duplicate'});return sendJson(res,200,{accepted:true,duplicate:true,matched:true})}
const pend=findPending(amts,payee,sourceTime);
const matched=isWx&&hasTrust&&hasPmt&&!!pend;
const audit={...baseAudit,orderId:pend?.id||''};
if(isWx&&hasTrust&&hasPmt&&prev!==undefined&&Math.abs(tms-prev)<=SMSF_DUP_WIN){recordSmsfEvent({...audit,orderId:prior?.orderId||'',matched:true,result:'duplicate'});return sendJson(res,200,{accepted:true,duplicate:true,matched:true})}
if(isWx&&hasTrust&&hasPmt&&priorExact&&['no_pending_order','amount_not_found'].includes(priorExact.result)){recordSmsfEvent({...audit,orderId:'',matched:false,result:priorExact.result});return sendJson(res,priorExact.result==='amount_not_found'?422:409,{accepted:false,error:priorExact.result,matched:false})}
if(!matched){
if(isWx&&hasTrust&&hasPmt){
const error=amts.length===0?'amount_not_found':'no_pending_order';
recordSmsfEvent({...audit,matched:false,result:error});
return sendJson(res,error==='amount_not_found'?422:409,{accepted:false,error,matched:false})
}
recordSmsfEvent({...audit,matched:false,result:'ignored'});
return sendJson(res,200,{accepted:true,matched:false})
}
recentSmsF.set(fp,tms);
if(txnIds.has(transactionId)){recordSmsfEvent({...audit,matched:true,result:'duplicate'});return sendJson(res,200,{accepted:true,duplicate:true,matched:true})}
const r=await settleOrder(pend,notificationAt,transactionId);
recordSmsfEvent({...audit,matched:true,result:r.duplicate?'duplicate':'matched'});
return sendJson(res,200,{accepted:true,delivered:r.delivered,duplicate:r.duplicate,matched:true,orderId:pend.id})
}

function handlePublicOrder(res,id){
const order=orderById(id);
if(!order)return sendJson(res,404,{error:'order_not_found'});
if(orderIsInactive(order))return sendJson(res,410,{error:'order_inactive'});
if(order.status==='pending'&&!orderPaymentExpired(order)){
const collides=state.orders.some(candidate=>candidate.id!==order.id&&!candidate.autoMatchDisabledAt&&orderOccupiesAmount(candidate,orderPayee(order))&&candidate.amountFen===order.amountFen);
if(collides){
const amountFen=allocateAmountFen(order.baseAmountFen||order.amountFen,orderPayee(order),order.id);
if(amountFen===null)return sendJson(res,409,{error:'amount_unavailable'});
order.amountFen=amountFen
}
if(!order.updatedAt||collides){order.updatedAt=new Date(now()).toISOString();persist()}
}
const payee=orderPayee(order);const user=userByName(state,payee);
return sendJson(res,200,{...publicOrder(order),payee,payeeDisplayName:user?.displayName||payee,qrcodeReady:order.status==='pending'&&!orderPaymentExpired(order)&&!!payeeQrPath(payee),serverTime:new Date(now()).toISOString()})
}

function materializeAlipayOrder(so,save=true){
const id=String(so?.id||'').toUpperCase();
if(!/^[A-Z0-9_-]{8,64}$/.test(id))return null;
const paymentMethod=normalizePaymentMethod(so.paymentMethod,so.paymentName);
if(paymentMethod!=='alipay')return null;
const existing=orderById(id);
if(existing){
if(existing.paymentMethodOriginal==='alipay'||existing.paymentMethod==='alipay'||existing.manualOnly)return existing;
if(existing.status==='paid'||(existing.paymentMethod&&existing.paymentMethod!=='wechat'))return null;
return null;
}
const createdRaw=String(so.createdAt||'').trim();
const createdMs=createdRaw?Date.parse(createdRaw):now();
if(!Number.isFinite(createdMs))return null;
const expiresRaw=String(so.expiresAt||'').trim();
const expiresMs=expiresRaw?Date.parse(expiresRaw):createdMs+paymentWindow;
if(!Number.isFinite(expiresMs)||expiresMs<createdMs)return null;
const matchRaw=String(so.matchExpiresAt||'').trim();
const matchMs=matchRaw?Date.parse(matchRaw):expiresMs+settlementGrace;
if(!Number.isFinite(matchMs)||matchMs<expiresMs)return null;
const createdAt=new Date(createdMs).toISOString();
const expiresAt=new Date(expiresMs).toISOString();
const matchExpiresAt=new Date(matchMs).toISOString();
const callbackUrl=sameOriginUrl(so.callbackUrl,`${cbOrigin}/pay/newzoe/notify_url`,cbOrigin);
const returnUrl=sameOriginUrl(so.returnUrl,`${cbOrigin}/detail-order-sn/${id}`,cbOrigin);
const amountFen=parseAmountFen(so.amountFen);
if(!Number.isSafeInteger(amountFen)||amountFen<1||amountFen>MAX_AMOUNT_FEN)return null;
const manualSuppressUrl=sameOriginUrl(so.manualSuppressUrl||so.suppressUrl,`${cbOrigin}/api/newzoe/manual-suppress`,cbOrigin);
const externalStatus=shopStatusKey(so.status);
const settled=shopStatusSettled(externalStatus);
const remoteUpdated=Date.parse(String(so.updatedAt||so.createdAt||''));
const settledAt=Number.isFinite(remoteUpdated)?new Date(remoteUpdated).toISOString():createdAt;
const order={activatedAt:createdAt,amountFen,baseAmountFen:amountFen,callbackStatus:'waiting',callbackUrl,createdAt,expiresAt,externalStatus,id,manualOnly:true,manualSuppressUrl,matchExpiresAt,paymentMethod:'alipay',paymentMethodOriginal:'alipay',returnUrl,source:'dujiaoka',status:settled?'paid':'pending',title:String(so.title||'支付宝订单').slice(0,160),payee:'admin',updatedAt:createdAt,...(settled?{paidAt:settledAt,settledAt}:{})};
state.orders.push(order);if(save)persist();return order;
}
async function fetchShopOrders(){
if(!shopOrdUrl||!shopSecret||!fetchImpl)return[];
try{const r=await fetchImpl(shopOrdUrl,{headers:{'x-newzoe-key':shopSecret}});if(!r.ok)return[];const p=await r.json();const list=(Array.isArray(p.orders)?p.orders:[]).map(so=>({...so,id:String(so?.id||'').toUpperCase()}));const beforeCount=state.orders.length;let changed=false;for(const so of list){const materialized=materializeAlipayOrder(so,false);const remoteStatus=shopStatusKey(so.status);if(materialized&&materialized.externalStatus!==remoteStatus){materialized.externalStatus=remoteStatus;materialized.updatedAt=new Date(now()).toISOString();changed=true}if(materialized&&shopStatusSettled(remoteStatus)&&materialized.status==='pending'){const remoteUpdated=Date.parse(String(so.updatedAt||so.createdAt||''));const settledAt=Number.isFinite(remoteUpdated)?new Date(remoteUpdated).toISOString():new Date(now()).toISOString();materialized.status='paid';materialized.paidAt=materialized.paidAt||settledAt;materialized.settledAt=materialized.settledAt||settledAt;materialized.updatedAt=new Date(now()).toISOString();changed=true}}if(changed||state.orders.length!==beforeCount)persist();return list}catch(e){console.error('sync fail:',e.message);return[]}
}
async function refreshExternalOrder(order){
if(!order?.manualOnly||order.paymentMethodOriginal!=='alipay')return{ok:true,order:null};
const list=await fetchShopOrders();
const remote=list.find(item=>String(item?.id||'').toUpperCase()===String(order.id).toUpperCase());
if(!remote)return{ok:false,error:'external_order_not_found'};
const method=normalizePaymentMethod(remote.paymentMethod,remote.paymentName);
if(method!=='alipay')return{ok:false,error:'payment_method_conflict'};
const status=shopStatusKey(remote.status);order.externalStatus=status;
if(!['pending','expired'].includes(status))return{ok:false,error:'external_order_status',status};
return{ok:true,order:remote,status};
}

async function handleAdminApi(req,res,url){
if(!['GET','HEAD'].includes(req.method)&&!validAdminOrigin(req))return sendJson(res,403,{error:'invalid_origin'});
if(url.pathname==='/api/admin/session'&&req.method==='GET'){
const u=sessUser(req);if(!u)return sendJson(res,200,{authenticated:false});
const obj=userByName(state,u);
return sendJson(res,200,{authenticated:true,user:u,role:obj?.role||'admin',displayName:obj?.displayName||u})
}
if(url.pathname==='/api/admin/login'&&req.method==='POST'){
const ip=(req.headers['x-forwarded-for']||'').split(',')[0].trim()||req.headers['x-real-ip']||req.socket.remoteAddress||'x';
const att=loginAttempts.get(ip)||{count:0,since:now()};
if(now()-att.since>600000)Object.assign(att,{count:0,since:now()});
if(att.count>=10)return sendJson(res,429,{error:'too_many_attempts'});
const body=await readBody(req);
let p;try{p=JSON.parse(body.toString('utf8'))}catch{return sendJson(res,400,{error:'invalid_json'})}
const obj=userByName(state,String(p.username||''));
if(!obj||!verifyPw(String(p.password||''),obj.password)){att.count++;loginAttempts.set(ip,att);return sendJson(res,401,{error:'invalid_credentials'})}
loginAttempts.delete(ip);
res.setHeader('Set-Cookie','newzoe_pay_admin='+makeSession(obj.username)+'; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=43200');
return sendJson(res,200,{authenticated:true,user:obj.username,role:obj.role,displayName:obj.displayName})
}
const curObj=currentUserObj(req);
if(!curObj)return sendJson(res,401,{error:'authentication_required'});
const isSuper=curObj.role==='super';
if(url.pathname==='/api/admin/logout'&&req.method==='POST'){res.setHeader('Set-Cookie','newzoe_pay_admin=; Path=/; HttpOnly; Secure; SameSite=Strict; Max-Age=0');return sendJson(res,200,{authenticated:false})}

// users CRUD (super)
if(url.pathname==='/api/admin/users'&&req.method==='GET'){if(!isSuper)return sendJson(res,403,{});return sendJson(res,200,{users:state.users.map(u=>({username:u.username,role:u.role,displayName:u.displayName,qrcode:u.qrcode,createdAt:u.createdAt}))})}
if(url.pathname==='/api/admin/users'&&req.method==='POST'){
if(!isSuper)return sendJson(res,403,{});
const body=await readBody(req);let p;try{p=JSON.parse(body.toString('utf8'))}catch{return sendJson(res,400,{error:'invalid_json'})}
const nu=String(p.username||'').trim().toLowerCase();
if(!/^[a-z0-9_]{2,32}$/.test(nu))return sendJson(res,400,{error:'invalid_username'});
if(userByName(state,nu))return sendJson(res,409,{error:'username_exists'});
const pw=String(p.password||'');if(pw.length<6)return sendJson(res,400,{error:'password_too_short'});
const entry={username:nu,password:hashPw(pw),role:'admin',displayName:String(p.displayName||nu).slice(0,40),qrcode:null,createdAt:new Date(now()).toISOString()};
entry.smsfSecret=crypto.randomBytes(32).toString('hex');
state.users.push(entry);persist();
return sendJson(res,201,{user:{username:entry.username,role:entry.role,displayName:entry.displayName,qrcode:entry.qrcode,createdAt:entry.createdAt}})
}
if(url.pathname.startsWith('/api/admin/users/')&&req.method==='DELETE'){
if(!isSuper)return sendJson(res,403,{});
const target=url.pathname.split('/').pop();
if(target==='admin')return sendJson(res,400,{error:'cannot_delete_admin'});
const idx=state.users.findIndex(u=>u.username===target);
if(idx===-1)return sendJson(res,404,{error:'user_not_found'});
if(state.orders.some(order=>orderPayee(order)===target))return sendJson(res,409,{error:'merchant_has_orders'});
if(state.users[idx].qrcode)try{fs.unlinkSync(path.join(qrcodeDir,state.users[idx].qrcode))}catch{}
state.users.splice(idx,1);persist();
return sendJson(res,200,{deleted:true})
}
if(url.pathname.startsWith('/api/admin/users/')&&req.method==='PATCH'){
if(!isSuper)return sendJson(res,403,{});
const target=url.pathname.split('/').pop();
const u=userByName(state,target);if(!u)return sendJson(res,404,{error:'user_not_found'});
const body=await readBody(req);let p;try{p=JSON.parse(body.toString('utf8'))}catch{return sendJson(res,400,{error:'invalid_json'})}
if(p.password){if(String(p.password).length<6)return sendJson(res,400,{error:'password_too_short'});u.password=hashPw(String(p.password))}
if(p.displayName)u.displayName=String(p.displayName).slice(0,40);
persist();return sendJson(res,200,{user:{username:u.username,role:u.role,displayName:u.displayName,qrcode:u.qrcode,createdAt:u.createdAt}})
}

// QR code upload
if(url.pathname==='/api/admin/qrcode'&&req.method==='GET'){
const target=isSuper&&url.searchParams.get('user')?String(url.searchParams.get('user')):curObj.username;
if(!isSuper&&target!==curObj.username)return sendJson(res,403,{});
const user=userByName(state,target);if(!user)return sendJson(res,404,{error:'user_not_found'});
const qrPath=payeeQrPath(target);
return sendJson(res,200,{configured:!!qrPath,qrcode:user.qrcode||null,uploaded:!!user.qrcode,url:payeeQrUrl(target),username:target})
}
if(url.pathname==='/api/admin/qrcode'&&req.method==='POST'){
const target=url.searchParams.get('user')||curObj.username;
if(!isSuper&&target!==curObj.username)return sendJson(res,403,{});
const u=userByName(state,target);if(!u)return sendJson(res,404,{error:'user_not_found'});
const body=await readBody(req,5*1024*1024);
let buf=body;
if(!buf||buf.length<100||buf.length>5242880)return sendJson(res,400,{error:'invalid_image'});
let ext='.jpg';
if(buf[0]===0x89&&buf[1]===0x50)ext='.png';
else if(buf[0]===0xff&&buf[1]===0xd8)ext='.jpg';
else if(buf[0]===0x47&&buf[1]===0x49)ext='.gif';
else if(buf[0]===0x52&&buf[1]===0x49)ext='.webp';
if(u.qrcode)try{fs.unlinkSync(path.join(qrcodeDir,u.qrcode))}catch{}
const fn='qr-'+u.username+'-'+Date.now()+ext;
fs.writeFileSync(path.join(qrcodeDir,fn),buf,{mode:0o600});
u.qrcode=fn;persist();
return sendJson(res,200,{qrcode:fn,url:'/qrcodes/'+fn})
}

// orders
if(url.pathname==='/api/admin/orders'&&req.method==='GET'){
const shop=await fetchShopOrders();
const localById=new Map(state.orders.map(o=>[String(o.id||'').toUpperCase(),o]));
const merged=shop.map(so=>{
const normalizedId=String(so?.id||'').toUpperCase();
const po=localById.get(normalizedId);localById.delete(normalizedId);
const payment=po?publicOrder(po):null;
const externalStatus=shopStatusKey(so.status);
const status=payment?.manualOnly&&payment.status==='paid'&&['pending','expired'].includes(externalStatus)
  ?'paid'
  :externalStatus;
return{...so,id:normalizedId,amountFen:payment?.amountFen||so.amountFen,baseAmountFen:payment?.baseAmountFen||so.amountFen,paymentMethod:payment?.paymentMethod||normalizePaymentMethod(so.paymentMethod,so.paymentName),payee:'admin',status,payment}
});
for(const o of localById.values()){const payment=publicOrder(o);merged.push({amountFen:o.amountFen,baseAmountFen:o.baseAmountFen||o.amountFen,createdAt:o.createdAt,expiresAt:o.expiresAt,id:o.id,source:o.source,status:payment.status,title:o.title,payee:orderPayee(o),payment})}
merged.sort((a,b)=>Date.parse(b.createdAt||0)-Date.parse(a.createdAt||0));
const filtered=isSuper?merged:merged.filter(o=>(o.payment?.payee||o.payee||'admin')===curObj.username);
return sendJson(res,200,{orders:filtered.slice(0,1000)})
}
if(url.pathname==='/api/admin/orders'&&req.method==='POST'){
if(!payeeQrPath(curObj.username))return sendJson(res,409,{error:'qrcode_required'});
const body=await readBody(req);let p;try{p=JSON.parse(body.toString('utf8'))}catch{return sendJson(res,400,{})}
const af=yuanToFen(String(p.amount||''));
const title=String(p.title||'\u624b\u5de5\u6536\u6b3e').trim().slice(0,160);
const expiryMinutes=p.expiryMinutes===undefined?paymentMinutes:Number(p.expiryMinutes);
if(!af||af>MAX_AMOUNT_FEN||!title)return sendJson(res,400,{error:'invalid_order'});
if(!Number.isInteger(expiryMinutes)||expiryMinutes<1||expiryMinutes>MAX_MANUAL_PAYMENT_MINUTES)return sendJson(res,400,{error:'invalid_expiry'});
const id='M'+new Date(now()).toISOString().replace(/\D/g,'').slice(2,14)+crypto.randomBytes(3).toString('hex').toUpperCase();
const amountFen=allocateAmountFen(af,curObj.username);
if(amountFen===null)return sendJson(res,409,{error:'amount_unavailable'});
const createdAt=new Date(now()).toISOString();
const expiresAt=new Date(now()+expiryMinutes*60*1000).toISOString();
const matchExpiresAt=new Date(now()+(expiryMinutes+settlementGraceMinutes)*60*1000).toISOString();
const order={activatedAt:createdAt,amountFen,baseAmountFen:af,createdAt,expiresAt,id,matchExpiresAt,source:'manual',status:'pending',title,payee:curObj.username,updatedAt:createdAt};
state.orders.push(order);persist();
return sendJson(res,201,{order:pubOrder(order),paymentUrl:'https://pay.newzoe.cloud/'+id})
}

const markPaidMatch=/^\/api\/admin\/orders\/([A-Z0-9_-]{8,64})\/mark-paid$/.exec(url.pathname);
if(markPaidMatch&&req.method==='POST'){
const order=orderById(markPaidMatch[1]);
if(!order)return sendJson(res,404,{error:'order_not_found'});
if(!isSuper&&orderPayee(order)!==curObj.username)return sendJson(res,403,{error:'forbidden'});
if(!String(req.headers['content-type']||'').toLowerCase().includes('application/json'))return sendJson(res,415,{error:'invalid_content_type'});
const body=await readBody(req);let p;try{p=JSON.parse(body.toString('utf8'))}catch{return sendJson(res,400,{error:'invalid_json'})}
if(order.source==='dujiaoka'&&typeof p.triggerShopFulfillment!=='boolean')return sendJson(res,400,{error:'invalid_fulfillment_choice'});
if(order.manualOnly&&order.paymentMethodOriginal==='alipay'&&order.status!=='paid'){
const external=await refreshExternalOrder(order);
if(!external.ok)return sendJson(res,409,{error:external.error,status:external.status||''});
}
if(order.status==='paid'){
const isAlipay=order.source==='dujiaoka'&&order.paymentMethodOriginal==='alipay';
if(isAlipay&&p.triggerShopFulfillment===false&&!order.callbackSuppressedAt){
 const suppression=await suppressShop(order,curObj.username);
 if(suppression.success)recordManualSuppression(order,curObj.username,new Date(now()).toISOString());
 else{order.callbackStatus='error';order.callbackSuppressionError=suppression.error||`http_${suppression.status||'error'}`;order.updatedAt=new Date(now()).toISOString()}
 persist();
 return sendJson(res,200,{duplicate:true,order:pubOrder(order),shopFulfillmentSuppressed:suppression.success,shopFulfillmentSuppressionAttempted:suppression.attempted,shopFulfillmentSuppressionError:suppression.success?'':(suppression.error||`http_${suppression.status||'error'}`),shopFulfillmentRetried:false,shopFulfillmentTriggered:false})
}
const shouldRetry=order.source==='dujiaoka'&&p.triggerShopFulfillment===true&&!order.callbackSuppressedAt&&order.callbackStatus!=='success';
const retryResult=shouldRetry?await cbShop(order,order.transactionId||('manual-retry-'+order.id),{manualOverride:true}):{attempted:false};
return sendJson(res,200,{duplicate:true,order:pubOrder(order),shopFulfillmentRetried:retryResult.attempted,shopFulfillmentTriggered:retryResult.attempted})
}
if(order.status!=='pending')return sendJson(res,409,{error:'invalid_order_status'});
const paidAt=new Date(now()).toISOString();
const triggerShopCallback=order.source==='dujiaoka'&&p.triggerShopFulfillment===true;
order.manualPaidAt=paidAt;order.manualPaidBy=curObj.username;order.paymentMethodOriginal=order.paymentMethodOriginal||order.paymentMethod||'';order.paymentMethod='manual_admin';
const tid='manual-'+String(now())+'-'+crypto.randomBytes(6).toString('hex');
const result=await settleOrder(order,paidAt,tid,{triggerShopCallback,manualOverride:triggerShopCallback});
const suppression=order.source==='dujiaoka'&&!triggerShopCallback&&order.paymentMethodOriginal==='alipay'?await suppressShop(order,curObj.username):{attempted:false,success:true};
if(order.source==='dujiaoka'&&!triggerShopCallback){
 if(order.paymentMethodOriginal==='alipay'){
  if(suppression.success)recordManualSuppression(order,curObj.username,paidAt);
  else{order.callbackStatus='error';order.callbackSuppressionError=suppression.error||`http_${suppression.status||'error'}`;order.updatedAt=new Date(now()).toISOString()}
 }else recordManualSuppression(order,curObj.username,paidAt);
 persist();
}
return sendJson(res,200,{duplicate:result.duplicate,order:pubOrder(order),shopFulfillmentSuppressed:suppression.success,shopFulfillmentSuppressionAttempted:suppression.attempted,shopFulfillmentSuppressionError:suppression.success?'':(suppression.error||`http_${suppression.status||'error'}`),shopFulfillmentTriggered:triggerShopCallback})
}

// smsf config
if(url.pathname==='/api/admin/smsf-config'&&req.method==='GET'){
const suffix=curObj.username==='admin'?'':'?user='+encodeURIComponent(curObj.username);
const userSmsfSecret=curObj.username==='admin'?smsfSecret:curObj.smsfSecret;
return sendJson(res,200,{webhookUrl:'https://pay.newzoe.cloud/api/smsf/notify'+suffix,secret:userSmsfSecret||'',username:curObj.username})
}
if(url.pathname==='/api/admin/smsf-events'&&req.method==='GET'){
const visible=isSuper?state.smsfEvents:state.smsfEvents.filter(event=>event.payee===curObj.username);
return sendJson(res,200,{events:visible.slice(-100).reverse()})
}

return sendJson(res,404,{})
}

function serveStatic(rel,res){
const fp=path.resolve(PUBLIC_DIR,rel);
if(!fp.startsWith(PUBLIC_DIR+path.sep))return sendJson(res,404,{});
return serveFile(fp,res)
}
function serveFile(fp,res,cacheControl=''){
fs.stat(fp,(err,st)=>{if(err||!st.isFile())return sendJson(res,404,{});const ext=path.extname(fp).toLowerCase();
res.writeHead(200,{'Cache-Control':cacheControl||(ext==='.html'?'no-cache':'public, max-age=3600'),'Content-Length':st.size,'Content-Type':CT[ext]||'application/octet-stream','X-Content-Type-Options':'nosniff'});fs.createReadStream(fp).pipe(res)})
}

const server=http.createServer(async(req,res)=>{
try{const url=new URL(req.url,'http://localhost');
if(req.method==='GET'&&url.pathname==='/api/health')return sendJson(res,200,{ok:true,orders:state.orders.length,users:state.users.length});
if(url.pathname.startsWith('/api/admin/'))return await handleAdminApi(req,res,url);
if(req.method==='POST'&&url.pathname==='/api/shop/orders')return await registerShopOrder(req,res);
const om=/^\/api\/orders\/([A-Za-z0-9_-]{8,64})$/.exec(url.pathname);
if(req.method==='GET'&&om)return handlePublicOrder(res,om[1].toUpperCase());
if(req.method==='GET'&&url.pathname==='/api/events'){
const cid=url.searchParams.get('client')||'';const oid=String(url.searchParams.get('order')||'').toUpperCase();
if(!/^[A-Za-z0-9_-]{16,80}$/.test(cid))return sendJson(res,400,{error:'invalid_client'});
if(oid&&!orderById(oid))return sendJson(res,404,{error:'order_not_found'});
res.writeHead(200,{'Cache-Control':'no-cache, no-transform','Connection':'keep-alive','Content-Type':'text/event-stream; charset=utf-8','X-Accel-Buffering':'no'});res.flushHeaders();
const co=oid?orderById(oid):null;
const publicStatus=co?publicOrder(co):null;
if(publicStatus?.status==='paid'){sendEvent(res,'status',publicStatus);return res.end()}
if(orderIsInactive(co)){sendEvent(res,'status',{id:co.id,status:'inactive'});return res.end()}
if(co&&orderMatchExpired(co)){sendEvent(res,'status',{id:co.id,status:'expired'});return res.end()}
sendEvent(res,'status',publicStatus||{status:'waiting'});
const streams=clients.get(cid)||new Set();const stream={orderId:oid,res};streams.add(stream);clients.set(cid,streams);
const hb=setInterval(()=>res.write(': heartbeat\n\n'),20000);
req.on('close',()=>{clearInterval(hb);streams.delete(stream);if(streams.size===0)clients.delete(cid)});return}
if(req.method==='POST'&&url.pathname==='/api/payment/notify')return await handleNotify(req,res);
if(req.method==='POST'&&url.pathname==='/api/smsf/notify')return await handleSmsFNotify(req,res,url);
if(req.method==='GET'&&/^\/[A-Za-z0-9_-]{8,64}$/.test(url.pathname)){
const oid=url.pathname.slice(1).toUpperCase();
return serveStatic('index.html',res)}
if(req.method==='GET'||req.method==='HEAD'){
let pp=url.pathname.replace(/^\/+/,'');let requested=pp==='admin'?'admin.html':pp==='docs'?'docs.html':pp===''?'index.html':pp;
if(requested==='wechat-pay.jpg'){
let payee=String(url.searchParams.get('user')||'admin').trim().toLowerCase();
const oid=String(url.searchParams.get('order')||'').toUpperCase();
if(oid){const order=orderById(oid);if(!order)return sendJson(res,404,{error:'order_not_found'});if(orderIsInactive(order))return sendJson(res,410,{error:'order_inactive'});if(order.status==='paid')return sendJson(res,410,{error:'order_paid'});if(orderPaymentExpired(order))return sendJson(res,410,{error:'order_expired'});payee=orderPayee(order)}
const qrPath=payeeQrPath(payee);if(qrPath)return serveFile(qrPath,res,oid?'no-store':'');
return sendJson(res,404,{error:'qrcode_not_configured'})}
return serveStatic(requested,res)}
return sendJson(res,405,{error:'method_not_allowed'})}catch(e){console.error(e);if(!res.headersSent)sendJson(res,e.statusCode||500,{error:'internal_error'});else res.end()}});
return server}

if(require.main===module){const port=Number(process.env.PORT||3210);const host=process.env.HOST||'127.0.0.1';createPayServer().listen(port,host,()=>console.log('newzoe-pay listening on http://'+host+':'+port))}
module.exports={createPayServer,extractNotificationAmounts:extractAmts,signPayload,signSmsForwarder:signSmsF,yuanTextToFen:yuanToFen}
