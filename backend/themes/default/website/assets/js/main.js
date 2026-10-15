const toggle = document.querySelector('.nav-toggle');
const navigation = document.querySelector('#main-navigation');
toggle?.addEventListener('click', () => {
  const expanded = toggle.getAttribute('aria-expanded') !== 'true';
  toggle.setAttribute('aria-expanded', String(expanded));
  navigation?.classList.toggle('is-open', expanded);
});
