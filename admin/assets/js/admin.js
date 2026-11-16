/**
 * Greenfield Village — Admin Panel UI Interactions
 */
(() => {
  'use strict';

  window.addEventListener('DOMContentLoaded', () => {
    // Mobile sidebar drawer
    const menuBtn = document.getElementById('adm-menu-btn');
    const sidebar = document.getElementById('adm-sidebar');
    if (menuBtn && sidebar) {
      menuBtn.addEventListener('click', () => {
        sidebar.classList.toggle('is-open');
      });
    }

    // Confirm destructive actions without window.alert
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
      form.addEventListener('submit', (e) => {
        const msg = form.getAttribute('data-confirm') || 'আপনি কি এই আইটেমটি মুছে ফেলতে চান?';
        if (!window.confirm(msg)) {
          e.preventDefault();
        }
      });
    });

    // Toggle post format fields: rich text for normal/image/video posts,
    // a single complete HTML document field for custom-code posts.
    const postTypeSelect = document.getElementById('post_type_select');
    const postMediaGroup = document.getElementById('post_media_group');
    const postCustomGroup = document.getElementById('post_custom_code_group');
    const postRichGroup = document.getElementById('post_rich_editor_group');
    if (postTypeSelect) {
      const syncPostEditor = () => {
        const type = postTypeSelect.value;
        if (postMediaGroup) postMediaGroup.style.display = (type === 'image' || type === 'video') ? '' : 'none';
        if (postCustomGroup) postCustomGroup.style.display = type === 'custom' ? '' : 'none';
        if (postRichGroup) postRichGroup.style.display = type === 'custom' ? 'none' : '';
        const codeField = document.getElementById('post_code_document');
        if (codeField) codeField.required = type === 'custom';
      };
      postTypeSelect.addEventListener('change', syncPostEditor);
      syncPostEditor();
    }

    // Simple WordPress-style rich text editor. The server applies a strict
    // HTML allowlist before content is stored or displayed publicly.
    document.querySelectorAll('[data-rich-editor]').forEach((wrapper) => {
      const surface = wrapper.querySelector('[contenteditable="true"]');
      const form = wrapper.closest('form');
      const hidden = form ? form.querySelector('textarea[name="content_html"]') : null;
      const imagePicker = wrapper.querySelector('[data-editor-image-file]');
      if (!surface || !form || !hidden) return;

      let savedRange = null;
      const saveSelection = () => {
        const selection = window.getSelection();
        if (selection && selection.rangeCount && surface.contains(selection.anchorNode)) {
          savedRange = selection.getRangeAt(0).cloneRange();
        }
      };
      const restoreSelection = () => {
        if (!savedRange) return;
        surface.focus();
        const selection = window.getSelection();
        if (selection) {
          selection.removeAllRanges();
          selection.addRange(savedRange);
        }
      };
      const insertNodeAtSelection = (node) => {
        restoreSelection();
        const selection = window.getSelection();
        if (!selection || !selection.rangeCount) {
          surface.appendChild(node);
          return;
        }
        const range = selection.getRangeAt(0);
        range.deleteContents();
        range.insertNode(node);
        range.setStartAfter(node);
        range.collapse(true);
        selection.removeAllRanges();
        selection.addRange(range);
        saveSelection();
      };
      const wrapSelection = (property, value) => {
        restoreSelection();
        const selection = window.getSelection();
        if (!selection || !selection.rangeCount) return;
        const range = selection.getRangeAt(0);
        const span = document.createElement('span');
        span.style[property] = value;
        if (range.collapsed) {
          span.textContent = '\u200B';
          range.insertNode(span);
          range.setStart(span.firstChild, 1);
          range.collapse(true);
        } else {
          span.appendChild(range.extractContents());
          range.insertNode(span);
          range.selectNodeContents(span);
        }
        selection.removeAllRanges();
        selection.addRange(range);
        saveSelection();
      };

      surface.addEventListener('keyup', saveSelection);
      surface.addEventListener('mouseup', saveSelection);
      surface.addEventListener('input', saveSelection);
      wrapper.querySelectorAll('[data-editor-command]').forEach((button) => {
        button.addEventListener('mousedown', (event) => event.preventDefault());
        button.addEventListener('click', () => {
          surface.focus();
          document.execCommand(button.getAttribute('data-editor-command'), false, null);
          saveSelection();
        });
      });
      const blockSelect = wrapper.querySelector('[data-editor-block]');
      if (blockSelect) {
        blockSelect.addEventListener('mousedown', saveSelection);
        blockSelect.addEventListener('change', () => {
          restoreSelection();
          document.execCommand('formatBlock', false, blockSelect.value);
          surface.focus();
          saveSelection();
        });
      }
      const sizeSelect = wrapper.querySelector('[data-editor-size]');
      if (sizeSelect) {
        sizeSelect.addEventListener('mousedown', saveSelection);
        sizeSelect.addEventListener('change', () => wrapSelection('fontSize', `${sizeSelect.value}px`));
      }
      const colorInput = wrapper.querySelector('[data-editor-color]');
      if (colorInput) {
        colorInput.addEventListener('mousedown', saveSelection);
        colorInput.addEventListener('input', () => wrapSelection('color', colorInput.value));
      }
      const linkButton = wrapper.querySelector('[data-editor-link]');
      if (linkButton) {
        linkButton.addEventListener('mousedown', (event) => event.preventDefault());
        linkButton.addEventListener('click', () => {
          const url = window.prompt('লিংক লিখুন (https://... অথবা /পেজের-ঠিকানা):');
          if (!url) return;
          if (!/^(https?:\/\/|\/|#)/i.test(url)) {
            window.alert('শুধু https://, http://, / দিয়ে শুরু হওয়া স্থানীয় ঠিকানা অথবা পেজের নির্দিষ্ট অংশের লিংক ব্যবহার করা যাবে।');
            return;
          }
          restoreSelection();
          document.execCommand('createLink', false, url);
          saveSelection();
        });
      }
      const imageButton = wrapper.querySelector('[data-editor-image]');
      if (imageButton && imagePicker) {
        imageButton.addEventListener('mousedown', (event) => event.preventDefault());
        imageButton.addEventListener('click', () => { saveSelection(); imagePicker.click(); });
        imagePicker.addEventListener('change', async () => {
          const file = imagePicker.files && imagePicker.files[0];
          if (!file) return;
          const token = form.querySelector('[name="csrf_token"]');
          const formData = new FormData();
          formData.append('image', file);
          if (token) formData.append('csrf_token', token.value);
          imageButton.disabled = true;
          imageButton.textContent = 'আপলোড হচ্ছে…';
          try {
            const response = await fetch('/448662/editor-image-upload.php', {
              method: 'POST',
              body: formData,
              credentials: 'same-origin',
              headers: { 'Accept': 'application/json' }
            });
            const result = await response.json();
            if (!response.ok || !result.ok || !result.url) throw new Error(result.error || 'ছবি আপলোড করা যায়নি।');
            const image = document.createElement('img');
            image.src = result.url;
            image.alt = file.name;
            image.loading = 'lazy';
            image.style.maxWidth = '100%';
            insertNodeAtSelection(image);
          } catch (error) {
            window.alert(error instanceof Error ? error.message : 'ছবি আপলোড করা যায়নি।');
          } finally {
            imageButton.disabled = false;
            imageButton.textContent = '▧ ছবি';
            imagePicker.value = '';
          }
        });
      }
      form.addEventListener('submit', () => { hidden.value = surface.innerHTML; });
    });

    // Landing-page editor mode: separate fields or one complete HTML document.
    const pageEditorMode = document.getElementById('page_editor_mode');
    const pageSeparateFields = document.getElementById('page_separate_code_fields');
    const pageSingleField = document.getElementById('page_single_code_field');
    const pageHtmlField = document.getElementById('html_content');
    if (pageEditorMode && pageSeparateFields && pageSingleField) {
      const syncPageMode = () => {
        const single = pageEditorMode.value === 'single';
        pageSeparateFields.style.display = single ? 'none' : '';
        pageSingleField.style.display = single ? '' : 'none';
        if (pageHtmlField) pageHtmlField.required = !single;
        const singleCode = document.getElementById('code_document');
        if (singleCode) singleCode.required = single;
      };
      pageEditorMode.addEventListener('change', syncPageMode);
      syncPageMode();
    }

    // Password strength feedback on security page
    const newPw = document.getElementById('new_password_input');
    const pwHint = document.getElementById('pw_strength_feedback');
    if (newPw && pwHint) {
      newPw.addEventListener('input', () => {
        const v = newPw.value;
        const checks = [
          v.length >= 10,
          /[A-Z]/.test(v),
          /[a-z]/.test(v),
          /[0-9]/.test(v),
          /[^a-zA-Z0-9]/.test(v),
        ];
        const score = checks.filter(Boolean).length;
        const labels = ['খুব দুর্বল', 'দুর্বল', 'মোটামুটি', 'ভালো', 'শক্তিশালী', 'অত্যন্ত শক্তিশালী'];
        pwHint.textContent = v.length === 0 ? '' : `পাসওয়ার্ডের শক্তি: ${labels[score]} (${score}/5 শর্ত পূরণ হয়েছে)`;
      });
    }

    // Live image and video upload previews in Admin forms
    document.querySelectorAll('input[type="file"][data-preview-target]').forEach((input) => {
      input.addEventListener('change', () => {
        const targetId = input.getAttribute('data-preview-target');
        const container = targetId ? document.getElementById(targetId) : null;
        if (!container) return;

        container.querySelectorAll('img[data-blob-preview], video[data-blob-preview]').forEach((el) => {
          if (el.src) URL.revokeObjectURL(el.src);
        });

        const files = Array.from(input.files || []);
        if (files.length === 0) return;

        container.innerHTML = '';
        const list = document.createElement('div');
        list.className = 'adm-preview-grid';

        files.slice(0, 8).forEach((file) => {
          const objectUrl = URL.createObjectURL(file);
          if (file.type.startsWith('video/')) {
            const vid = document.createElement('video');
            vid.src = objectUrl;
            vid.controls = true;
            vid.preload = 'metadata';
            vid.className = 'adm-preview-media';
            vid.setAttribute('data-blob-preview', '1');
            list.appendChild(vid);
          } else if (file.type.startsWith('image/')) {
            const img = document.createElement('img');
            img.src = objectUrl;
            img.alt = file.name;
            img.className = 'adm-preview-media';
            img.setAttribute('data-blob-preview', '1');
            list.appendChild(img);
          }
        });

        container.appendChild(list);
        if (files.length > 8) {
          const note = document.createElement('span');
          note.className = 'form-hint';
          note.textContent = `+ ${files.length - 8}টি অতিরিক্ত ফাইল আপলোডের জন্য নির্বাচন করা হয়েছে।`;
          container.appendChild(note);
        }
      });
    });

    // Multi-Admin permission presets & Super Admin role toggle
    const roleSelect = document.getElementById('admin_role_select');
    const permBox = document.getElementById('admin_permissions_box');
    const permCheckboxes = Array.from(document.querySelectorAll('.adm-perm-checkbox'));

    if (roleSelect && permBox) {
      const syncPermBox = () => {
        const isSuper = roleSelect.value === 'super_admin';
        permCheckboxes.forEach((cb) => {
          if (isSuper) cb.checked = true;
          cb.disabled = isSuper;
        });
        permBox.style.opacity = isSuper ? '0.65' : '1';
      };
      roleSelect.addEventListener('change', syncPermBox);
      syncPermBox();
    }

    document.querySelectorAll('[data-perm-preset]').forEach((btn) => {
      btn.addEventListener('click', () => {
        if (roleSelect && roleSelect.value === 'super_admin') {
          roleSelect.value = 'admin';
          roleSelect.dispatchEvent(new Event('change'));
        }
        const preset = btn.getAttribute('data-perm-preset');
        const map = {
          posts: ['manage_posts'],
          gallery: ['manage_gallery'],
          both: ['manage_posts', 'manage_gallery'],
          all: permCheckboxes.map((cb) => cb.value),
          none: []
        };
        const target = map[preset] || [];
        permCheckboxes.forEach((cb) => {
          cb.checked = target.includes(cb.value);
        });
      });
    });

    // Cryptographically strong random password generator for new/reset admin accounts
    document.querySelectorAll('[data-generate-password]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const input = document.getElementById(btn.getAttribute('data-generate-password') || '');
        if (!input || !window.crypto || !window.crypto.getRandomValues) return;

        const pick = (charset) => {
          const buf = new Uint32Array(1);
          window.crypto.getRandomValues(buf);
          return charset[buf[0] % charset.length];
        };

        const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        const lower = 'abcdefghijkmnpqrstuvwxyz';
        const digits = '23456789';
        const symbols = '!@#%^*+=';
        const all = upper + lower + digits + symbols;

        const chars = [
          pick(upper), pick(upper),
          pick(lower), pick(lower),
          pick(digits), pick(digits),
          pick(symbols), pick(symbols)
        ];
        while (chars.length < 14) {
          chars.push(pick(all));
        }
        for (let i = chars.length - 1; i > 0; i--) {
          const buf = new Uint32Array(1);
          window.crypto.getRandomValues(buf);
          const j = buf[0] % (i + 1);
          [chars[i], chars[j]] = [chars[j], chars[i]];
        }
        input.value = chars.join('');
        input.focus();
      });
    });
  });
})();
