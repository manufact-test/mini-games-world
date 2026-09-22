import { requestStagingOidcToken } from './staging-oidc-token.mjs';

const originalFetch = globalThis.fetch;
const originalUrl = process.env.ACTIONS_ID_TOKEN_REQUEST_URL;
const originalToken = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN;

const assert = (condition, message) => {
  if (!condition) throw new Error(message);
};

try {
  process.env.ACTIONS_ID_TOKEN_REQUEST_URL = 'https://oidc.example.test/token?x=1';
  process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN = 'runner-token';

  let calls = 0;
  globalThis.fetch = async () => {
    calls++;
    if (calls < 3) return { ok:false, status:503, json:async () => ({}) };
    return { ok:true, status:200, json:async () => ({ value:'header.payload.signature' }) };
  };
  const token = await requestStagingOidcToken({ attempts:5 });
  assert(token === 'header.payload.signature', 'Transient 503 sequence must recover.');
  assert(calls === 3, 'Transient recovery must stop after the first successful token.');

  calls = 0;
  globalThis.fetch = async () => {
    calls++;
    return { ok:false, status:503, json:async () => ({}) };
  };
  let terminal = null;
  try {
    await requestStagingOidcToken({ attempts:2 });
  } catch (error) {
    terminal = error;
  }
  assert(terminal instanceof Error, 'Exhausted OIDC retries must fail.');
  assert(String(terminal.message).includes('[MGW_E2E_INFRA_OIDC_FAILURE]'),
    'Exhausted OIDC retries must publish the infrastructure marker.');
  assert(calls === 2, 'Bounded OIDC retries must honor the requested attempt count.');

  console.log('staging-oidc-token selftest passed');
} finally {
  globalThis.fetch = originalFetch;
  if (originalUrl === undefined) delete process.env.ACTIONS_ID_TOKEN_REQUEST_URL;
  else process.env.ACTIONS_ID_TOKEN_REQUEST_URL = originalUrl;
  if (originalToken === undefined) delete process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN;
  else process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN = originalToken;
}
