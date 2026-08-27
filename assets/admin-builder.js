(function () {
  'use strict';

  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  ready(function () {
    var form = document.getElementById('wpnc-email-builder');
    var container = document.getElementById('wpnc-builder-blocks');
    var jsonField = document.getElementById('wpnc-builder-json');
    var preview = document.getElementById('wpnc-builder-preview');
    if (!form || !container || !jsonField || !preview) return;

    var blocks = [];
    try { blocks = JSON.parse(jsonField.value || '[]'); } catch (e) { blocks = []; }
    if (!Array.isArray(blocks)) blocks = [];

    function uid() {
      return 'b' + Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
    }

    function normalize(block) {
      block = block && typeof block === 'object' ? block : {};
      if (!block.id) block.id = uid();
      if (!block.type) block.type = 'paragraph';
      return block;
    }
    blocks = blocks.map(normalize);

    var labels = {
      heading: 'Heading',
      paragraph: 'Text',
      image: 'Image',
      button: 'Button',
      divider: 'Divider',
      spacer: 'Spacer',
      html: 'Custom HTML'
    };

    function esc(value) {
      return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function field(label, control) {
      return '<label><span>' + esc(label) + '</span>' + control + '</label>';
    }

    function blockBody(block) {
      var t = block.type;
      if (t === 'heading') {
        return field('Heading text', '<input type="text" data-field="text" value="' + esc(block.text || '') + '" placeholder="Newsletter heading">') +
          field('Size', '<select data-field="size"><option value="large"' + ((block.size || 'large') === 'large' ? ' selected' : '') + '>Large</option><option value="medium"' + (block.size === 'medium' ? ' selected' : '') + '>Medium</option><option value="small"' + (block.size === 'small' ? ' selected' : '') + '>Small</option></select>');
      }
      if (t === 'paragraph') {
        return field('Text', '<textarea data-field="text" placeholder="Write email text. You can use {name}, {first_name}, {last_name} and {email}.">' + esc(block.text || '') + '</textarea>');
      }
      if (t === 'image') {
        return '<div class="wpnc-builder-media-row">' + field('Image URL', '<input type="url" data-field="url" value="' + esc(block.url || '') + '" placeholder="https://...">') + '<button type="button" class="button" data-select-media>Select image</button></div>' +
          field('Alt text', '<input type="text" data-field="alt" value="' + esc(block.alt || '') + '" placeholder="Describe the image">') +
          field('Optional link', '<input type="url" data-field="link" value="' + esc(block.link || '') + '" placeholder="https://...">');
      }
      if (t === 'button') {
        return field('Button label', '<input type="text" data-field="label" value="' + esc(block.label || 'Read more') + '">') +
          field('Button URL', '<input type="url" data-field="url" value="' + esc(block.url || '') + '" placeholder="https://...">') +
          field('Alignment', '<select data-field="align"><option value="left"' + ((block.align || 'left') === 'left' ? ' selected' : '') + '>Left</option><option value="center"' + (block.align === 'center' ? ' selected' : '') + '>Centre</option><option value="right"' + (block.align === 'right' ? ' selected' : '') + '>Right</option></select>');
      }
      if (t === 'spacer') {
        return field('Height', '<select data-field="height"><option value="16"' + (String(block.height || 24) === '16' ? ' selected' : '') + '>16 px</option><option value="24"' + (String(block.height || 24) === '24' ? ' selected' : '') + '>24 px</option><option value="32"' + (String(block.height || 24) === '32' ? ' selected' : '') + '>32 px</option><option value="48"' + (String(block.height || 24) === '48' ? ' selected' : '') + '>48 px</option><option value="64"' + (String(block.height || 24) === '64' ? ' selected' : '') + '>64 px</option></select>');
      }
      if (t === 'html') {
        return field('Custom email HTML', '<textarea data-field="html" class="code" placeholder="<table>...</table>">' + esc(block.html || '') + '</textarea>');
      }
      return '<p class="description">No settings are required for this block.</p>';
    }

    function renderBlocks() {
      container.innerHTML = '';
      blocks.forEach(function (block, index) {
        block = normalize(block);
        var el = document.createElement('div');
        el.className = 'wpnc-builder-block';
        el.dataset.id = block.id;
        el.innerHTML = '<div class="wpnc-builder-block__header"><strong><span class="dashicons dashicons-block-default"></span>' + esc(labels[block.type] || block.type) + '</strong><div class="wpnc-builder-block__actions"><button type="button" class="button" data-move="up" title="Move up">↑</button><button type="button" class="button" data-move="down" title="Move down">↓</button><button type="button" class="button" data-remove title="Remove block">Remove</button></div></div><div class="wpnc-builder-block__body">' + blockBody(block) + '</div>';
        container.appendChild(el);
        if (index === 0) el.classList.add('is-selected');
      });
      sync();
    }

    function collectBlock(el) {
      var id = el.dataset.id;
      var block = blocks.find(function (item) { return item.id === id; });
      if (!block) return;
      Array.prototype.forEach.call(el.querySelectorAll('[data-field]'), function (control) {
        block[control.dataset.field] = control.value;
      });
    }

    function collectAll() {
      Array.prototype.forEach.call(container.querySelectorAll('.wpnc-builder-block'), collectBlock);
    }

    function sync() {
      collectAll();
      jsonField.value = JSON.stringify(blocks);
      updatePreview();
    }

    function replaceTokens(text) {
      return String(text || '')
        .replace(/\{name\}|\{first_name\}/g, 'Client')
        .replace(/\{surname\}|\{last_name\}/g, 'Tester')
        .replace(/\{email\}/g, 'client@example.com')
        .replace(/\{unsubscription_url\}|\{unsubscribe_url\}/g, '#unsubscribe');
    }

    function previewBlock(block, accent) {
      if (block.type === 'heading') {
        var sizes = { large: 36, medium: 28, small: 22 };
        var size = sizes[block.size] || 36;
        return '<tr><td style="padding:18px 42px 8px;font-family:Arial,sans-serif;color:#1d2327"><h1 style="margin:0;font-size:' + size + 'px;line-height:1.15">' + esc(replaceTokens(block.text || '')) + '</h1></td></tr>';
      }
      if (block.type === 'paragraph') {
        return '<tr><td style="padding:10px 42px;font-family:Arial,sans-serif;font-size:17px;line-height:1.55;color:#2c3338">' + esc(replaceTokens(block.text || '')).replace(/\n/g, '<br>') + '</td></tr>';
      }
      if (block.type === 'image' && block.url) {
        var img = '<img src="' + esc(block.url) + '" alt="' + esc(block.alt || '') + '" style="display:block;width:100%;max-width:700px;height:auto;border:0">';
        if (block.link) img = '<a href="' + esc(block.link) + '">' + img + '</a>';
        return '<tr><td style="padding:16px 0;text-align:center">' + img + '</td></tr>';
      }
      if (block.type === 'button' && block.url) {
        var align = ['left', 'center', 'right'].indexOf(block.align) !== -1 ? block.align : 'left';
        return '<tr><td style="padding:14px 42px 20px;text-align:' + align + '"><a href="' + esc(block.url) + '" style="display:inline-block;padding:13px 22px;background:' + esc(accent) + ';color:#fff;text-decoration:none;font-family:Arial,sans-serif;font-weight:600;border-radius:3px">' + esc(block.label || 'Read more') + '</a></td></tr>';
      }
      if (block.type === 'divider') return '<tr><td style="padding:14px 42px"><div style="height:1px;background:#dcdcde"></div></td></tr>';
      if (block.type === 'spacer') {
        var h = parseInt(block.height || 24, 10);
        if (!h || h < 8) h = 24;
        return '<tr><td style="height:' + h + 'px;line-height:' + h + 'px;font-size:1px">&nbsp;</td></tr>';
      }
      if (block.type === 'html') return '<tr><td style="padding:10px 42px">' + replaceTokens(block.html || '') + '</td></tr>';
      return '';
    }

    function updatePreview() {
      var subject = form.querySelector('[name="subject"]');
      var preheader = form.querySelector('[name="preheader"]');
      var accentField = form.querySelector('[name="accent"]');
      var bgField = form.querySelector('[name="background"]');
      var accent = accentField ? accentField.value : '#2271b1';
      var bg = bgField ? bgField.value : '#f0f0f1';
      var rows = blocks.map(function (b) { return previewBlock(b, accent); }).join('');
      var doc = '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>body{margin:0;background:' + esc(bg) + '}table{border-collapse:collapse}@media(max-width:600px){.shell{width:100%!important}.pad{padding-left:22px!important;padding-right:22px!important}}</style></head><body><div style="display:none">' + esc(preheader ? preheader.value : '') + '</div><table width="100%" role="presentation"><tr><td align="center" style="padding:20px 8px"><table class="shell" width="700" role="presentation" style="width:700px;max-width:700px;background:#fff"><tr><td style="padding:22px 42px;background:' + esc(accent) + ';color:#fff;font-family:Arial,sans-serif;font-weight:600">WordPress</td></tr>' + rows + '<tr><td style="padding:24px 42px;border-top:1px solid #dcdcde;font-family:Arial,sans-serif;font-size:12px;color:#646970">WordPress newsletter footer<br><a href="#unsubscribe" style="color:' + esc(accent) + '">Unsubscribe</a></td></tr></table><div style="font:12px Arial;color:#646970;padding:8px">' + esc(subject ? subject.value : '') + '</div></td></tr></table></body></html>';
      preview.srcdoc = doc;
    }

    function addBlock(type) {
      var block = normalize({ type: type });
      if (type === 'heading') { block.text = 'New heading'; block.size = 'medium'; }
      if (type === 'paragraph') block.text = 'Write your email text here.';
      if (type === 'image') { block.url = ''; block.alt = ''; block.link = ''; }
      if (type === 'button') { block.label = 'Read more'; block.url = ''; block.align = 'left'; }
      if (type === 'spacer') block.height = 24;
      if (type === 'html') block.html = '<p>Custom HTML</p>';
      blocks.push(block);
      renderBlocks();
      var added = container.querySelector('[data-id="' + block.id + '"]');
      if (added) {
        Array.prototype.forEach.call(container.querySelectorAll('.wpnc-builder-block'), function (el) { el.classList.remove('is-selected'); });
        added.classList.add('is-selected');
        try { added.scrollIntoView({ block: 'center', behavior: 'smooth' }); } catch (e) {}
      }
    }

    document.addEventListener('click', function (event) {
      var add = event.target.closest('[data-wpnc-add-block]');
      if (add && form.contains(add)) {
        event.preventDefault();
        addBlock(add.dataset.wpncAddBlock);
        return;
      }
      var blockEl = event.target.closest('.wpnc-builder-block');
      if (blockEl && container.contains(blockEl)) {
        Array.prototype.forEach.call(container.querySelectorAll('.wpnc-builder-block'), function (el) { el.classList.toggle('is-selected', el === blockEl); });
      }
      var remove = event.target.closest('[data-remove]');
      if (remove && blockEl) {
        event.preventDefault();
        collectAll();
        blocks = blocks.filter(function (b) { return b.id !== blockEl.dataset.id; });
        renderBlocks();
        return;
      }
      var move = event.target.closest('[data-move]');
      if (move && blockEl) {
        event.preventDefault();
        collectAll();
        var index = blocks.findIndex(function (b) { return b.id === blockEl.dataset.id; });
        var next = move.dataset.move === 'up' ? index - 1 : index + 1;
        if (index >= 0 && next >= 0 && next < blocks.length) {
          var temp = blocks[index]; blocks[index] = blocks[next]; blocks[next] = temp;
          renderBlocks();
        }
        return;
      }
      var mediaButton = event.target.closest('[data-select-media]');
      if (mediaButton && blockEl && window.wp && wp.media) {
        event.preventDefault();
        var frame = wp.media({ title: 'Select email image', button: { text: 'Use this image' }, library: { type: 'image' }, multiple: false });
        frame.on('select', function () {
          var attachment = frame.state().get('selection').first().toJSON();
          var urlInput = blockEl.querySelector('[data-field="url"]');
          var altInput = blockEl.querySelector('[data-field="alt"]');
          if (urlInput) urlInput.value = attachment.url || '';
          if (altInput && !altInput.value) altInput.value = attachment.alt || attachment.title || '';
          sync();
        });
        frame.open();
      }
    });

    container.addEventListener('input', sync);
    container.addEventListener('change', sync);
    form.addEventListener('input', function (event) {
      if (!container.contains(event.target)) updatePreview();
    });
    form.addEventListener('change', function (event) {
      if (!container.contains(event.target)) updatePreview();
    });
    form.addEventListener('submit', function () { sync(); });
    var refresh = document.getElementById('wpnc-builder-refresh');
    if (refresh) refresh.addEventListener('click', function () { sync(); });

    renderBlocks();
  });
})();
