(() => {
  'use strict';
  const init = () => {
    const type = document.getElementById('popup_type');
    const text = document.getElementById('popup-text-field');
    const image = document.getElementById('popup-image-field');
    const body = document.getElementById('popup_body');
    const file = document.getElementById('popup_image');
    if (!type || !text || !image || !body || !file) return;

    const sync = () => {
      const isImage = type.value === 'image';
      text.hidden = isImage;
      image.hidden = !isImage;
      body.required = !isImage;
      file.required = isImage && !image.querySelector('img');
    };
    type.addEventListener('change', sync);
    sync();

    file.addEventListener('change', () => {
      const preview = document.getElementById('popup-image-preview');
      if (!preview) return;
      preview.replaceChildren();
      if (file.files && file.files[0]) {
        const img = document.createElement('img');
        img.src = URL.createObjectURL(file.files[0]);
        img.alt = 'Selected popup image preview';
        img.addEventListener('load', () => URL.revokeObjectURL(img.src), { once: true });
        preview.append(img);
      }
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
