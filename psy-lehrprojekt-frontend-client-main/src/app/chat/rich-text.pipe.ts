import { Pipe, PipeTransform, inject } from '@angular/core';
import { DomSanitizer, SafeHtml } from '@angular/platform-browser';
import DOMPurify from 'dompurify';
import katex from 'katex';
import { Marked, TokenizerAndRendererExtension } from 'marked';

/*
 * Turns a tutor answer - Markdown with LaTeX maths in it - into display HTML.
 *
 * Display only: history.received keeps the raw text, which is what the
 * scientific analysis reads, and old conversations render the same way when
 * they are reloaded from history.
 *
 * Three decisions here are load-bearing and easy to undo by "simplifying":
 *
 * 1. Maths is tokenised BY marked, as an extension - not rendered afterwards.
 *    Markdown treats \( and \[ as escaped brackets and drops the backslash, and
 *    reads the _ and * inside formulas as emphasis. Rendering maths after the
 *    Markdown pass (KaTeX auto-render) finds no delimiters left to render.
 *
 * 2. All four delimiter styles are accepted. The model writes \( \) and \[ \]
 *    on its own; the course materials use $ and $$, and grounded answers may
 *    mirror them. Supporting only one style would render one study arm worse
 *    than the other.
 *
 * 3. The HTML is cleaned with DOMPurify and only then marked as trusted.
 *    Angular's own [innerHTML] sanitizer strips the style attributes and SVG
 *    that KaTeX positions fractions and roots with, so it cannot be used here.
 *    But model output is not trusted input either, so it never goes in raw.
 */

const tex = (source: string, displayMode: boolean): string =>
  katex.renderToString(source, {
    displayMode,
    // a formula KaTeX cannot parse shows its source in red instead of breaking the answer
    throwOnError: false,
    // e.g. umlauts inside \text{} in German answers
    strict: 'ignore',
  });

const escapeHtml = (text: string): string =>
  text.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');

// Tried in order at each position, so the two-character delimiters come before a single $.
const INLINE_MATH: { pattern: RegExp; display: boolean }[] = [
  { pattern: /^\\\[([\s\S]+?)\\\]/, display: true },
  { pattern: /^\$\$([\s\S]+?)\$\$/, display: true },
  { pattern: /^\\\(([\s\S]+?)\\\)/, display: false },
  // $...$ only where it cannot be prose: no space just inside either dollar, and
  // no digit straight after the closing one - so "costs $5 and $10" stays text.
  // The last character may be an escaped one, as in $\{X_1, \dots, X_m\}$.
  // (No lookbehind: older iOS Safari cannot even parse it, and would fail the whole bundle.)
  { pattern: /^\$(?!\s)((?:\\.|[^\\$\n])*?(?:\\.|[^\\\s$]))\$(?!\d)/, display: false },
];

const mathExtensions: TokenizerAndRendererExtension[] = [
  {
    // a formula on a line of its own, possibly spanning several lines
    name: 'mathBlock',
    level: 'block',
    start: (src) => src.match(/^ {0,3}(?:\$\$|\\\[)/m)?.index,
    tokenizer(src) {
      const m = /^ {0,3}(?:\$\$([\s\S]+?)\$\$|\\\[([\s\S]+?)\\\])[ \t]*(?:\n+|$)/.exec(src);
      if (m) {
        return { type: 'mathBlock', raw: m[0], text: (m[1] ?? m[2]).trim() };
      }
      return undefined;
    },
    renderer: (token) => tex(token['text'], true),
  },
  {
    // a formula inside a sentence; \[ \] and $$ $$ still render as display maths
    name: 'math',
    level: 'inline',
    start: (src) => src.match(/\\[([]|\$/)?.index,
    tokenizer(src) {
      for (const { pattern, display } of INLINE_MATH) {
        const m = pattern.exec(src);
        if (m) {
          return { type: 'math', raw: m[0], text: m[1].trim(), display };
        }
      }
      return undefined;
    },
    renderer: (token) => tex(token['text'], token['display']),
  },
];

// highlight.js is loaded globally by index.html (public/highlight.min.js);
// its colour theme lives in styles.css.
interface HighlightJs {
  getLanguage(name: string): unknown;
  highlight(code: string, options: { language: string }): { value: string };
}

const markdown = new Marked({
  gfm: true,
  // the model writes chat-style text, where a single newline means a new line
  breaks: true,
  extensions: mathExtensions,
  renderer: {
    // Raw HTML in an answer is shown as text, never interpreted. A tutor never
    // needs it, and it is the obvious route for injected markup.
    html: ({ text }) => escapeHtml(text),

    // Images would make the student's browser fetch an arbitrary URL the model
    // chose. Show the alt text instead - escaped, because marked passes it raw.
    image: ({ text }) => escapeHtml(text),

    code({ text, lang }) {
      const language = (lang ?? '').trim().split(/\s+/)[0];
      const hljs = (globalThis as { hljs?: HighlightJs }).hljs;
      const body = hljs && language && hljs.getLanguage(language)
        ? hljs.highlight(text, { language }).value
        : escapeHtml(text);
      return `<pre><code class="hljs">${body}</code></pre>\n`;
    },
  },
});

// A private DOMPurify instance, so the hook below cannot leak into anything else.
const purifier = DOMPurify(window);

// Links open in a new tab, so following one does not navigate away from the chat.
purifier.addHook('afterSanitizeAttributes', (node) => {
  if (node instanceof HTMLAnchorElement) {
    node.setAttribute('target', '_blank');
    node.setAttribute('rel', 'noopener noreferrer');
  }
});

// KaTeX's accessible MathML carries the TeX source in <annotation>, which
// DOMPurify would otherwise drop.
const PURIFY_CONFIG = { ADD_TAGS: ['semantics', 'annotation'] };

export function renderRichText(text: string): string {
  return purifier.sanitize(markdown.parse(text, { async: false }), PURIFY_CONFIG);
}

@Pipe({
  name: 'richText',
  standalone: true,
})
export class RichTextPipe implements PipeTransform {

  private readonly sanitizer = inject(DomSanitizer);

  transform(text: string | null | undefined): SafeHtml {
    // Trusting is safe only because renderRichText() has already run DOMPurify.
    return this.sanitizer.bypassSecurityTrustHtml(renderRichText(text ?? ''));
  }
}
