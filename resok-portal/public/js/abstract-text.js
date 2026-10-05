/**
 * Formatting in abstract titles and text: italics, superscript and subscript.
 *
 * The text stays plain text in which exactly six tokens are formatting - <i> </i> <sup>
 * </sup> <sub> </sub> - the same rule as absRichClean() in api/lib/abstracts.php. Rendering
 * builds DOM nodes from those tokens and puts everything else in as text, so "p<0.05" shows
 * as typed and nothing an author writes can ever become markup.
 *
 * Also the small toolbar the author uses to add them, so nobody has to type a tag.
 */
(() => {
  const TOKEN = /(<\/?(?:i|sup|sub)>)/i;
  const TOKENS_G = /<\/?(?:i|sup|sub)>/gi;

  /** Writes the text into node with its formatting, replacing what was there. */
  function render(node, text) {
    node.textContent = '';
    const stack = [node];
    String(text || '').split(TOKEN).forEach((part, i) => {
      if (i % 2 === 0) { if (part) stack[stack.length - 1].appendChild(document.createTextNode(part)); return; }
      const m = part.toLowerCase().match(/^<(\/?)(i|sup|sub)>$/);
      const tag = m[2];
      if (!m[1]) {
        if (stack.some((n) => n.tagName && n.tagName.toLowerCase() === tag && n !== node)) return;
        const el = document.createElement(tag);
        stack[stack.length - 1].appendChild(el);
        stack.push(el);
      } else if (stack.some((n, j) => j > 0 && n.tagName.toLowerCase() === tag)) {
        while (stack.length > 1) { const top = stack.pop(); if (top.tagName.toLowerCase() === tag) break; }
      }
    });
    return node;
  }

  /** A new element of the given tag holding the formatted text. */
  function make(tag, cls, text) {
    const n = document.createElement(tag);
    if (cls) n.className = cls;
    return render(n, text);
  }

  const plain = (text) => String(text || '').replace(TOKENS_G, '');
  const words = (text) => { const t = plain(text).trim(); return t ? t.split(/\s+/u).length : 0; };
  const hasFormatting = (text) => TOKEN.test(String(text || ''));

  let styled = false;
  function addStyles() {
    if (styled) return;
    styled = true;
    const css = document.createElement('style');
    css.textContent = '.fmt-bar{display:flex;gap:4px;flex-wrap:wrap;margin:0 0 6px;position:relative}'
      + '.fmt-bar button{border:1px solid #d0d5dd;background:#fff;border-radius:6px;min-width:32px;height:28px;padding:0 8px;'
      + 'font:inherit;font-size:13px;cursor:pointer;color:#344054}'
      + '.fmt-bar button:hover{border-color:#087539;color:#087539}'
      + '.fmt-sym{position:absolute;z-index:20;top:32px;left:0;background:#fff;border:1px solid #d0d5dd;border-radius:8px;'
      + 'padding:6px;display:grid;grid-template-columns:repeat(8,30px);gap:3px;box-shadow:0 8px 24px rgba(16,24,40,.12)}'
      + '.fmt-sym button{min-width:0;width:30px;padding:0}'
      + '.fmt-pv{font-size:13.5px;color:#344054;background:#f9fafb;border:1px dashed #d0d5dd;border-radius:8px;'
      + 'padding:8px 10px;margin-top:6px;white-space:pre-wrap}'
      + '.fmt-pv b{font-size:11px;text-transform:uppercase;letter-spacing:.05em;color:#667085;display:block;margin-bottom:2px}'
      + 'sup,sub{font-size:.72em;line-height:0}';
    document.head.appendChild(css);
  }

  const SYMBOLS = ['α', 'β', 'γ', 'δ', 'ε', 'κ', 'λ', 'μ', 'σ', 'τ', 'χ', 'Δ', '±', '×', '÷', '≤',
                   '≥', '≠', '≈', '°', '→', '←', '↑', '↓', '‰', '½', '·', '–', '—', '™', '®', '©'];

  /** Wraps the selection in a tag, or unwraps it when it is already wrapped exactly so. */
  function wrap(input, tag) {
    const open = `<${tag}>`, close = `</${tag}>`;
    const { selectionStart: a, selectionEnd: b, value } = input;
    if (value.slice(a - open.length, a).toLowerCase() === open && value.slice(b, b + close.length).toLowerCase() === close) {
      input.setRangeText(value.slice(a, b), a - open.length, b + close.length, 'select');
    } else {
      input.setRangeText(open + value.slice(a, b) + close, a, b, 'end');
      // Nothing selected: leave the cursor between the tags, ready to type.
      if (a === b) input.setSelectionRange(a + open.length, a + open.length);
      else input.setSelectionRange(a + open.length, b + open.length);
    }
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.focus();
  }

  function insert(input, text) {
    input.setRangeText(text, input.selectionStart, input.selectionEnd, 'end');
    input.dispatchEvent(new Event('input', { bubbles: true }));
    input.focus();
  }

  /**
   * The formatting buttons above an input or textarea, and a line under it showing the text
   * as it will be read whenever it contains formatting. Changes fire the field's own input
   * event, so whatever already listens (word counts, autosave) sees them.
   */
  function toolbar(input) {
    addStyles();
    const bar = document.createElement('div');
    bar.className = 'fmt-bar';
    const button = (html, label, fn, cls) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.title = label;
      b.setAttribute('aria-label', label);
      if (cls) b.className = cls;
      html.forEach((x) => b.appendChild(x));
      // mousedown, not click: a click would take the focus, and the selection with it.
      b.addEventListener('mousedown', (e) => { e.preventDefault(); fn(b); });
      b.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fn(b); } });
      bar.appendChild(b);
      return b;
    };
    const t = (s) => document.createTextNode(s);
    button([make('i', null, 'I')], 'Italic (Ctrl+I)', () => wrap(input, 'i'));
    button([t('x'), make('sup', null, '2')], 'Superscript', () => wrap(input, 'sup'));
    button([t('x'), make('sub', null, '2')], 'Subscript', () => wrap(input, 'sub'));
    let menu = null;
    const sym = button([t('Ω')], 'Insert a symbol', (b) => {
      if (menu) { menu.remove(); menu = null; return; }
      menu = document.createElement('div');
      menu.className = 'fmt-sym';
      SYMBOLS.forEach((s) => {
        const x = document.createElement('button');
        x.type = 'button';
        x.textContent = s;
        x.setAttribute('aria-label', 'Insert ' + s);
        x.addEventListener('mousedown', (e) => { e.preventDefault(); insert(input, s); });
        menu.appendChild(x);
      });
      menu.style.left = b.offsetLeft + 'px';
      bar.appendChild(menu);
    }, 'sym');
    document.addEventListener('mousedown', (e) => {
      if (menu && !menu.contains(e.target) && !sym.contains(e.target)) { menu.remove(); menu = null; }
    });
    input.parentNode.insertBefore(bar, input);

    const pv = document.createElement('div');
    pv.className = 'fmt-pv';
    pv.hidden = true;
    input.insertAdjacentElement('afterend', pv);
    const refresh = () => {
      pv.hidden = !hasFormatting(input.value);
      if (pv.hidden) return;
      pv.textContent = '';
      pv.appendChild(document.createElement('b')).textContent = 'Reads as';
      pv.appendChild(make('span', null, input.value));
    };
    input.addEventListener('input', refresh);
    input.addEventListener('keydown', (e) => {
      if ((e.ctrlKey || e.metaKey) && !e.shiftKey && e.key.toLowerCase() === 'i') { e.preventDefault(); wrap(input, 'i'); }
    });
    refresh();
    return { bar, refresh };
  }

  window.AbsText = { render, make, plain, words, hasFormatting, toolbar, addStyles };
})();
