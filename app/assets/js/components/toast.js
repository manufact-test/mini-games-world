let timer = null;

const SILENT_ACKNOWLEDGEMENTS = new Set([
  'Предмет выбран.',
  'Оформление снято.',
  'Фон выбран.',
  'Фон снят.',
  'Бейдж выбран.',
  'Бейдж снят.',
  'Рамка выбрана.',
  'Рамка снята.',
  'Эффект входа выбран.',
  'Эффект входа снят.',
]);

export function toast(message, duration = 2600){
  const normalized = String(message ?? '');
  if (SILENT_ACKNOWLEDGEMENTS.has(normalized)) return;
  const el = document.getElementById('toast');
  if (!el) return;
  el.textContent = normalized;
  el.classList.add('show');
  clearTimeout(timer);
  timer = setTimeout(() => el.classList.remove('show'), duration);
}