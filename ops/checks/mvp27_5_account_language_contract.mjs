import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const read = path => readFileSync(path,'utf8');
const policy=read('bot/accounts/MgwIdentityPolicy.php');
const profile=read('bot/accounts/MgwProfileService.php');
const endpoint=read('bot/profile-v2.php');
const client=read('app/assets/js/api/client.js');
const home=read('app/assets/js/screens/home-screen.js');
const i18n=read('app/assets/js/localization/i18n.js');
const version=read('app/runtime/client/version-manifest.php');
assert.match(policy,/SUPPORTED_LOCALES\s*=\s*\['ru', 'en'\]/);
assert.match(profile,/preferred_locale = :preferred_locale/);
assert.match(profile,/MgwIdentityPolicy::normalizeLocale\(\$changes\['preferred_locale'\]\)/);
assert.match(profile,/WHERE mgw_id = :mgw_id/);
assert.match(endpoint,/AuthService\(\$configRef\)\)->getUserFromRequest\(\$payload\)/);
assert.match(endpoint,/\$profileService->updateProfile\(\$mgwId, \$payload\['profile_update'\]\)/);
assert.match(client,/profile_update:\{ preferred_locale:locale \}/);
assert.match(client,/result\?\.profile\?\.preferred_locale !== locale/);
assert.match(client,/accountLocaleWriteEpoch/);
assert.match(client,/hydrateCanonicalLanguage\(result, epoch\)/);
assert.match(home,/void api\.saveAccountLocale\(locale\)\.catch/);
assert.match(home,/previewAccountLocale\(locale\)/);
assert.doesNotMatch(home,/button\.disabled = true/);
assert.doesNotMatch(home,/setExplicitLocale\(locale\)/);
assert.match(i18n,/const canonicalLocale = \['ru','en'\]/);
assert.match(i18n,/canonicalLocale \? null : explicitLocaleOverride\(\)/);
assert.match(i18n,/localStorage\?\.removeItem\(EXPLICIT_LOCALE_KEY\)/);
assert.match(i18n,/source:'account'/);
assert.match(version,/mvp27_5=canonical-account-language-v1/);
const module=await import('data:text/javascript;base64,'+Buffer.from(i18n).toString('base64'));
const payload={manifest:JSON.parse(read('app/locales/manifest.json')),catalogs:{
  ru:JSON.parse(read('app/locales/ru.json')),
  en:JSON.parse(read('app/locales/en.json')),
}};
const local=new Map();
globalThis.localStorage={getItem:k=>local.get(k)??null,setItem:(k,v)=>local.set(k,v),removeItem:k=>local.delete(k)};
Object.defineProperty(globalThis,'navigator',{configurable:true,value:{language:'en-US',languages:['en-US']}});
const events=[];
globalThis.document={documentElement:{lang:'ru'},
  getElementById:id=>id==='mgw-localization'?{textContent:JSON.stringify(payload)}:null,
  dispatchEvent:event=>events.push(event)};
if(typeof globalThis.CustomEvent!=='function'){
  globalThis.CustomEvent=class {constructor(type,options){this.type=type;this.detail=options?.detail}};
}
local.set('mgw_locale_override','en');
assert.equal(module.applyAccountLocalePreference('ru'),'ru');
assert.equal(local.has('mgw_locale_override'),false);
assert.equal(events.at(-1)?.detail?.source,'account');
assert.equal(module.applyAccountLocalePreference('en'),'en');
assert.equal(globalThis.document.documentElement.lang,'en');
local.set('mgw_locale_override','ru');
assert.equal(module.applyAccountLocalePreference(null),'ru');
assert.equal(module.applyAccountLocalePreference('de'),'ru');
assert.equal(module.applyAccountLocalePreference('en'),'en');
assert.equal(local.has('mgw_locale_override'),false);
console.log('MVP-27.5 account locale runtime and ownership PASS');
