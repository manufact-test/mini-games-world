const DEFAULT_AUDIENCE = 'mini-games-world-staging-e2e';
const DEFAULT_ATTEMPTS = 5;
const BASE_DELAY_MS = 400;

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));

export async function requestStagingOidcToken({
  audience = DEFAULT_AUDIENCE,
  attempts = DEFAULT_ATTEMPTS,
} = {}) {
  const source = process.env.ACTIONS_ID_TOKEN_REQUEST_URL || '';
  const bearer = process.env.ACTIONS_ID_TOKEN_REQUEST_TOKEN || '';
  if (!source || !bearer) {
    throw new Error('[MGW_E2E_INFRA_OIDC_FAILURE] GitHub Actions OIDC environment is unavailable.');
  }

  const maxAttempts = Math.max(1, Number(attempts) || DEFAULT_ATTEMPTS);
  let lastError = null;

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    try {
      const url = new URL(source);
      url.searchParams.set('audience', audience);
      const response = await fetch(url, {
        headers: {
          Authorization: `bearer ${bearer}`,
          Accept: 'application/json',
        },
      });

      if (response.ok) {
        const payload = await response.json();
        if (typeof payload?.value === 'string' && payload.value !== '') {
          return payload.value;
        }
        throw new Error('GitHub Actions OIDC response did not contain a JWT.');
      }

      const retryable = response.status === 429 || response.status >= 500;
      lastError = new Error(`GitHub Actions OIDC request failed: ${response.status}`);
      if (!retryable) throw lastError;
    } catch (error) {
      lastError = error instanceof Error ? error : new Error(String(error));
    }

    if (attempt < maxAttempts) {
      console.warn(`[MGW_E2E_OIDC_RETRY] attempt=${attempt} reason=${String(lastError?.message || 'unknown')}`);
      await sleep(BASE_DELAY_MS * attempt);
    }
  }

  throw new Error(
    `[MGW_E2E_INFRA_OIDC_FAILURE] ${String(lastError?.message || 'GitHub Actions OIDC request failed')}`
  );
}
