const STANDARD_AVATAR_LABEL = 'MG';
const STANDARD_AVATAR_ID = 'starter-default-01';
const AVATAR_ELEMENT_IDS = ['topAvatar', 'profileAvatar', 'searchMeAvatar'];

let installed = false;

export function initStandardAvatarPolicy(){
  if (installed) return;
  installed = true;

  const style = document.createElement('style');
  style.id = 'mgw-standard-avatar-policy';
  style.textContent = `
    #topAvatar,
    #profileAvatar,
    #searchMeAvatar {
      background-image: none !important;
      background-size: auto !important;
      background-position: center !important;
      background-repeat: no-repeat !important;
    }
  `;
  document.head.appendChild(style);

  const enforce = () => {
    AVATAR_ELEMENT_IDS.forEach(id => {
      const avatar = document.getElementById(id);
      if (!avatar) return;

      if (avatar.dataset.avatarId !== STANDARD_AVATAR_ID) {
        avatar.dataset.avatarId = STANDARD_AVATAR_ID;
      }
      if (avatar.textContent !== STANDARD_AVATAR_LABEL) {
        avatar.textContent = STANDARD_AVATAR_LABEL;
      }
      if (avatar.style.backgroundImage) avatar.style.backgroundImage = '';
      if (avatar.style.backgroundSize) avatar.style.backgroundSize = '';
      if (avatar.style.backgroundPosition) avatar.style.backgroundPosition = '';
      if (avatar.style.backgroundRepeat) avatar.style.backgroundRepeat = '';
      if (avatar.classList.contains('has-photo')) avatar.classList.remove('has-photo');
      if (!avatar.classList.contains('has-standard-avatar')) {
        avatar.classList.add('has-standard-avatar');
      }
    });
  };

  enforce();

  const observer = new MutationObserver(enforce);
  observer.observe(document.documentElement, {
    subtree:true,
    childList:true,
    characterData:true,
    attributes:true,
    attributeFilter:['style', 'class'],
  });

  document.addEventListener('mgw:app-ready', enforce);
}
