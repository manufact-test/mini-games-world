export function hidePreloader(){
  const el = document.getElementById('preloader');
  if (!el) return;
  setTimeout(() => el.classList.add('hidden'), 450);
}
