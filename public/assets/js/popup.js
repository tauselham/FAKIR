(() => {
  'use strict';
  const init = () => {
    const dataNode = document.getElementById('site-popup-data');
    if (!dataNode) return;
    let items;
    try { items = JSON.parse(dataNode.textContent || '[]'); } catch (_) { return; }
    if (!Array.isArray(items) || !items.length) return;

    const mobile = window.matchMedia('(max-width: 767px)').matches;
    const eligible = items.filter(item => item.device_target === 'all' ||
      (item.device_target === 'mobile' && mobile) ||
      (item.device_target === 'desktop' && !mobile));
    const chosen = eligible.find(item => {
      const key = 'site-popup-' + item.id;
      if (item.frequency === 'once_session') return !sessionStorage.getItem(key);
      if (item.frequency === 'once_day') {
        const shown = localStorage.getItem(key);
        const todayDhaka = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date());
        return shown !== todayDhaka;
      }
      return true;
    });
    if (!chosen) return;

    const key = 'site-popup-' + chosen.id;
    const remember = () => {
      if (chosen.frequency === 'once_session') sessionStorage.setItem(key, '1');
      if (chosen.frequency === 'once_day') {
        const todayDhaka = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date());
        localStorage.setItem(key, todayDhaka);
      }
    };
    const overlay = document.createElement('div');
    overlay.className = 'site-popup-overlay';
    overlay.setAttribute('role', 'presentation');
    const dialog = document.createElement('section');
    dialog.className = 'site-popup-dialog';
    dialog.setAttribute('role', 'dialog');
    dialog.setAttribute('aria-modal', 'true');
    dialog.setAttribute('aria-label', chosen.title || 'ওয়েবসাইটের ঘোষণা');
    const close = document.createElement('button');
    close.type = 'button'; close.className = 'site-popup-close';
    close.setAttribute('aria-label', 'পপআপ বন্ধ করুন'); close.textContent = '×';
    close.addEventListener('click', dismiss);
    dialog.append(close);

    if (chosen.title) {
      const title = document.createElement('h2');
      title.className = 'site-popup-title'; title.textContent = chosen.title;
      dialog.append(title);
    }

    if (chosen.popup_type === 'image') {
      const img = document.createElement('img');
      img.className = 'site-popup-image'; img.src = chosen.image_url;
      img.alt = chosen.title || 'ঘোষণা';
      if (chosen.image_link_url) {
        const link = document.createElement('a');
        link.href = chosen.image_link_url;
        if (chosen.image_link_target === '_blank') { link.target = '_blank'; link.rel = 'noopener noreferrer'; }
        link.append(img); dialog.append(link);
      } else dialog.append(img);
    } else {
      const body = document.createElement('p');
      body.className = 'site-popup-body'; body.textContent = chosen.body_text || '';
      dialog.append(body);
    }

    if (chosen.button_text && chosen.button_url) {
      const actions = document.createElement('div'); actions.className = 'site-popup-actions';
      const link = document.createElement('a'); link.className = 'site-popup-button';
      link.href = chosen.button_url; link.textContent = chosen.button_text;
      if (chosen.button_target === '_blank') { link.target = '_blank'; link.rel = 'noopener noreferrer'; }
      actions.append(link); dialog.append(actions);
    }

    overlay.append(dialog);
    document.body.append(overlay);
    remember();
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    function dismiss() {
      remember();
      overlay.remove();
      document.body.style.overflow = previousOverflow;
    }
    overlay.addEventListener('click', event => { if (event.target === overlay) dismiss(); });
    const onKey = event => { if (event.key === 'Escape') { dismiss(); document.removeEventListener('keydown', onKey); } };
    document.addEventListener('keydown', onKey);
    close.focus();
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
