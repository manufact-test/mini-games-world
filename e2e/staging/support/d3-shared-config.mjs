import { telegramAppRoute } from './telegram-launch-route.mjs';

export const STAGING_ORIGIN = process.env.MGW_STAGING_ORIGIN
  || 'https://seashell-okapi-889488.hostingersite.com';
export const OIDC_AUDIENCE = 'mini-games-world-staging-e2e';
export const AUTH_ROUTE = `${STAGING_ORIGIN}/bot/staging-test-auth.php`;
export const APP_ROUTE = telegramAppRoute(STAGING_ORIGIN);
export const API_ROUTE = `${STAGING_ORIGIN}/bot/api.php`;
export const INVITES_ROUTE = `${STAGING_ORIGIN}/bot/invites.php`;
export const TEST_COOKIE = 'mgw_staging_test_session';

export function requestAction(request) {
  try {
    return String(request.postDataJSON()?.action || '');
  } catch {
    return '';
  }
}

export function isActionResponse(route, action) {
  return response => response.url() === route
    && response.request().method() === 'POST'
    && requestAction(response.request()) === action;
}
