import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const read = path => readFileSync(path,'utf8');
const home = read('app/assets/js/screens/home-screen.js');
const api = read('app/assets/js/api/client.js');
const i18n = read('app/assets/js/localization/i18n.js');
const php = read('bot/profile-v2.php');

assert.match(api,/let accountLocaleSaveTail = Promise\.resolve\(\)/);
assert.match(api,/accountLocaleSaveTail\.catch\(\(\) => \{\}\)\.then/);
assert.match(api,/locale_preference_only:true/);
assert.match(api,/epoch === accountLocaleWriteEpoch/);
assert.match(php,/\$moderation->assertAllowed\(\$mgwId, 'profile'\)/);
assert.match(php,/\$profileService->updateProfile\(\$mgwId, \$payload\['profile_update'\]\)/);
assert.match(php,/count\(\$payload\['profile_update'\]\) === 1/);
assert.ok(php.indexOf("locale_preference_response") > php.indexOf("$canonicalProfile = $profileService->updateProfile"));
assert.ok(php.indexOf("locale_preference_response") < php.indexOf("$profileStage = 'inventory'"));
assert.match(i18n,/export function previewAccountLocale\(locale\)/);

const manifest = JSON.parse(read('app/locales/manifest.json'));
const payload = { manifest, catalogs: {
  ru: JSON.parse(read('app/locales/ru.json')),
  en: JSON.parse(read('app/locales/en.json')),
}};
const document = {
  documentElement:{lang:'ru'},
  getElementById:id=>id==='mgw-localization'?{textContent:JSON.stringify(payload)}:null,
  dispatchEvent:()=>{},
};
const local = new Map();
globalThis.document = document;
globalThis.localStorage = {
  getItem:key=>local.get(key)||null,
  setItem:(key,value)=>local.set(key,value),
  removeItem:key=>local.delete(key),
};
const mod = await import('data:text/javascript;base64,'+Buffer.from(i18n).toString('base64'));
assert.equal(mod.previewAccountLocale('en'),'en');
assert.equal(document.documentElement.lang,'en');
assert.equal(local.size,0,'Preview must never persist unacknowledged changes');
assert.equal(mod.previewAccountLocale('ru'),'ru');

const start = home.indexOf('function openLanguageSettingsSheet(){');
const end = home.indexOf('\nfunction localizedStrongHtml(', start);
assert.ok(start>0 && end>start, 'Settings modal source found');
const snippet = home.slice(start,end);
assert.doesNotMatch(snippet,/\.disabled\s*=\s*true/);
assert.ok(snippet.indexOf('closeSheet();\n    previewAccountLocale(locale);') >= 0);
assert.ok(snippet.indexOf('previewAccountLocale(locale);') < snippet.indexOf('api.saveAccountLocale(locale)'));

async function tick(){ await new Promise(resolve=>setImmediate(resolve)); }

function mount(initial='ru',confirmed='ru'){
  let current=initial;
  const buttons=new Map();
  let closes=0, pendingResolve, pendingReject;
  const saves=[];
  const toasts=[];
  const state={mgwProfile:{preferred_locale:confirmed}};
  const promise=new Promise((resolve,reject)=>{pendingResolve=resolve;pendingReject=reject;});
  const fakeDocument={
    getElementById:id=>{
      if(!buttons.has(id))buttons.set(id,{listeners:{},disabled:false,
        addEventListener(type,cb){this.listeners[type]=cb;}});
      return buttons.get(id);
    },
  };
  const context={
    state, document:fakeDocument,
    currentInterfaceLocale:()=>current,
    previewAccountLocale:locale=>{current=locale;return locale;},
    openSheet:()=>{},
    closeSheet:()=>{closes++;},
    escapeHtml:String,
    toast:value=>toasts.push(value),
    t:key=>key,
    api:{saveAccountLocale:locale=>{saves.push(locale);return promise;}},
    languageChangeIntent:0,
  };
  runInNewContext(snippet+'\nopenLanguageSettingsSheet();',context);
  return {
    click:locale=>buttons.get(locale==='en'?'languageEnBtn':'languageRuBtn').listeners.click(),
    get current(){return current;},
    get closes(){return closes;},
    get disabled(){return [...buttons.values()].some(x=>x.disabled);},
    get saves(){return saves;},
    get errors(){return toasts;},
    resolve:()=>pendingResolve({profile:{preferred_locale:saves.at(-1)}}),
    reject:()=>pendingReject(new Error('offline')),
  };
}
const success=mount();
success.click('en');
assert.equal(success.current,'en','Preview must paint in same tap turn before response');
assert.equal(success.closes,1,'Close immediately');
assert.equal(success.disabled,false,'No disabled buttons while request runs');
assert.deepEqual(success.saves,['en']);
success.resolve();
await tick();
assert.equal(success.errors.length,0,'No error on confirmed save');

const failed=mount();
failed.click('en');
assert.equal(failed.current,'en');
failed.reject();
await tick();
assert.equal(failed.current,'ru','Rejected server update rolls back to canonical saved locale');
assert.deepEqual(failed.errors,['offline']);
console.log('MVP-27.5.1 instant language tap / server failure rollback PASS');
