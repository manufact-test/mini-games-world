export function showScreen(name){
  document.querySelectorAll('.screen').forEach(screen => {
    screen.classList.toggle('active', screen.dataset.screen === name);
  });
}
