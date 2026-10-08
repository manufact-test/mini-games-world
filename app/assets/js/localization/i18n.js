const INLINE_LOCALIZATION_ID = 'mgw-localization';
const EXPLICIT_LOCALE_KEY = 'mgw_locale_override';

function readPath(source, key){
  return String(key || '').split('.').reduce((value, part) => {
    if (value && typeof value === 'object' && Object.prototype.hasOwnProperty.call(value, part)) return value[part];
    return undefined;
  }, source);
}

function interpolate(template, params = {}){
  return String(template).replace(/\{([A-Za-z0-9_]+)\}/g, (match, key) => Object.prototype.hasOwnProperty.call(params, key) ? String(params[key]) : match);
}

function normalizeDate(value){
  if (value instanceof Date) return value;
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) throw new TypeError('Invalid localization date value.');
  return date;
}

function normalizeLocaleCandidate(value){
  const raw = String(value || '').trim().toLowerCase().replace('_', '-');
  return raw.split('-')[0] || '';
}

function resolveLocale(manifest, requested){
  const supported = Array.isArray(manifest?.supported_locales) ? manifest.supported_locales : [];
  const fallback = String(manifest?.fallback_locale || manifest?.default_locale || 'ru');
  const candidate = normalizeLocaleCandidate(requested || manifest?.default_locale || fallback);
  return supported.includes(candidate) ? candidate : fallback;
}

export function createI18n(payload, requestedLocale = null){
  if (!payload || typeof payload !== 'object') throw new TypeError('Localization payload is required.');
  const manifest = payload.manifest;
  const catalogs = payload.catalogs;
  if (!manifest || typeof manifest !== 'object' || !catalogs || typeof catalogs !== 'object') throw new TypeError('Localization manifest and catalogs are required.');
  const locale = resolveLocale(manifest, requestedLocale);
  const catalog = catalogs[locale];
  if (!catalog || typeof catalog !== 'object') throw new Error(`Localization catalog is unavailable: ${locale}`);
  const formatConfig = manifest.formats?.[locale] || {};
  const intlLocale = String(formatConfig.intl_locale || locale);
  const pluralRules = new Intl.PluralRules(intlLocale);

  function t(key, params = {}){
    const value = readPath(catalog, key);
    if (typeof value !== 'string') throw new Error(`Missing translation key: ${key}`);
    return interpolate(value, params);
  }
  function plural(key, count, params = {}){
    const forms = readPath(catalog, key);
    if (!forms || typeof forms !== 'object' || Array.isArray(forms)) throw new Error(`Missing plural translation key: ${key}`);
    const category = pluralRules.select(Number(count));
    const template = forms[category] ?? forms.other;
    if (typeof template !== 'string') throw new Error(`Missing plural form: ${key}.${category}`);
    return interpolate(template, { ...params, count });
  }
  function formatNumber(value, options = {}){ return new Intl.NumberFormat(intlLocale, { ...(formatConfig.number || {}), ...options }).format(Number(value)); }
  function formatDate(value, style = 'short', options = {}){
    const format = formatConfig.date?.[style] || formatConfig.date?.short || {};
    return new Intl.DateTimeFormat(intlLocale, { ...format, ...options }).format(normalizeDate(value));
  }
  function formatDateTime(value, style = 'short', options = {}){
    const format = formatConfig.datetime?.[style] || formatConfig.datetime?.short || {};
    return new Intl.DateTimeFormat(intlLocale, { ...format, ...options }).format(normalizeDate(value));
  }
  function rules(gameType){
    const entry = manifest.rules?.games?.[String(gameType || '')];
    if (!entry || typeof entry !== 'object') throw new Error(`Rules metadata is unavailable: ${gameType}`);
    if (!Array.isArray(entry.languages) || !entry.languages.includes(locale)) throw new Error(`Rules language is unavailable: ${gameType}/${locale}`);
    return { ...entry, locale, title:t(entry.title_key) };
  }
  return Object.freeze({ locale, t, plural, formatNumber, formatDate, formatDateTime, rules });
}

function inlinePayload(documentRef = globalThis.document){
  const node = documentRef?.getElementById?.(INLINE_LOCALIZATION_ID);
  if (!node) throw new Error('Inline localization payload is unavailable.');
  return JSON.parse(String(node.textContent || ''));
}

function explicitLocaleOverride(){
  try { return globalThis.localStorage?.getItem(EXPLICIT_LOCALE_KEY) || null; } catch (error) { return null; }
}

export function readInlineLocalization(documentRef = globalThis.document){
  const requestedLocale = explicitLocaleOverride() || documentRef?.documentElement?.lang || null;
  const i18n = createI18n(inlinePayload(documentRef), requestedLocale);
  if (documentRef?.documentElement) documentRef.documentElement.lang = i18n.locale;
  return i18n;
}

let clientI18n = null;
let syncedTelegramLocale = null;

function syncTelegramBotLocale(locale){
  const initData = String(globalThis.Telegram?.WebApp?.initData || '');
  if (!initData || !['ru', 'en'].includes(locale) || syncedTelegramLocale === locale) return;
  syncedTelegramLocale = locale;
  // Explicit Mini App language controls future Telegram bot replies on this
  // Telegram account; full MGW cross-device preference remains MVP-27.5.
  void fetch('/bot/telegram-locale.php', {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({initData, locale}),
    credentials:'same-origin',
    cache:'no-store',
  }).then(response => {
    if (!response.ok && syncedTelegramLocale === locale) syncedTelegramLocale = null;
  }).catch(() => {
    if (syncedTelegramLocale === locale) syncedTelegramLocale = null;
  });
}
export function getI18n(){ clientI18n ||= readInlineLocalization(); return clientI18n; }

export function resolvePreferredLocale({ explicitLocale = null, accountLocale = null, platformLocale = null, fallbackLocale = 'ru' } = {}){
  return normalizeLocaleCandidate(explicitLocale)
    || normalizeLocaleCandidate(accountLocale)
    || normalizeLocaleCandidate(platformLocale)
    || normalizeLocaleCandidate(fallbackLocale)
    || 'ru';
}

function activateLocale(requestedLocale){
  const payload = inlinePayload();
  clientI18n = createI18n(payload, requestedLocale);
  if (globalThis.document?.documentElement) globalThis.document.documentElement.lang = clientI18n.locale;
  return clientI18n.locale;
}

export function applyAccountLocalePreference(accountLocale = null){
  // Authenticated account preference is canonical; reject unsupported values.
  const candidate = normalizeLocaleCandidate(accountLocale);
  const canonicalLocale = ['ru','en'].includes(candidate) ? candidate : null;
  const explicitLocale = canonicalLocale ? null : explicitLocaleOverride();
  const platformLocale = globalThis.navigator?.languages?.[0] || globalThis.navigator?.language || null;
  const fallbackLocale = inlinePayload()?.manifest?.fallback_locale || 'ru';
  const previous = clientI18n?.locale || readInlineLocalization().locale;
  const activated = activateLocale(resolvePreferredLocale({
    explicitLocale, accountLocale:canonicalLocale, platformLocale, fallbackLocale,
  }));
  if (canonicalLocale) {
    try { globalThis.localStorage?.removeItem(EXPLICIT_LOCALE_KEY); } catch (error) {}
  }
  // Preserve the previously accepted Telegram sync for a legacy explicit
  // device selection when the canonical account has no saved preference yet.
  if (canonicalLocale || explicitLocale) syncTelegramBotLocale(activated);
  if (activated !== previous && globalThis.document?.dispatchEvent) {
    globalThis.document.dispatchEvent(new CustomEvent('mgw:locale-changed', {
      detail:{ previous, locale:activated, source:'account' },
    }));
  }
  return activated;
}

// Optimistic visual-only change: no localStorage or Telegram bot writes.
// The authenticated canonical profile stays authoritative until server ACK.
export function previewAccountLocale(locale){
  if (!['ru', 'en'].includes(locale)) throw new TypeError('Unsupported language');
  const previous = clientI18n?.locale || readInlineLocalization().locale;
  const activated = activateLocale(locale);
  if (activated !== previous && globalThis.document?.dispatchEvent) {
    globalThis.document.dispatchEvent(new CustomEvent('mgw:locale-changed', {
      detail:{ previous, locale:activated, source:'preview' },
    }));
  }
  return activated;
}

export function setExplicitLocale(locale){
  const previous = clientI18n?.locale || readInlineLocalization().locale;
  const activated = activateLocale(locale);
  try { globalThis.localStorage?.setItem(EXPLICIT_LOCALE_KEY, activated); } catch (error) {}
  syncTelegramBotLocale(activated);
  if (activated !== previous && globalThis.document?.dispatchEvent) {
    globalThis.document.dispatchEvent(new CustomEvent('mgw:locale-changed', {
      detail:{ previous, locale:activated },
    }));
  }
  return activated;
}

export function t(key, params = {}){ return getI18n().t(key, params); }
export function plural(key, count, params = {}){ return getI18n().plural(key, count, params); }
export function formatNumber(value, options = {}){ return getI18n().formatNumber(value, options); }
export function formatDate(value, style = 'short', options = {}){ return getI18n().formatDate(value, style, options); }
export function formatDateTime(value, style = 'short', options = {}){ return getI18n().formatDateTime(value, style, options); }
export function rulesMetadata(gameType){ return getI18n().rules(gameType); }
