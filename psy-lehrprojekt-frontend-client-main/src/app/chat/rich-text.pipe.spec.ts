import { SecurityContext } from '@angular/core';
import { TestBed } from '@angular/core/testing';
import { DomSanitizer } from '@angular/platform-browser';
import { RichTextPipe, renderRichText } from './rich-text.pipe';

// The answer from the bug report, verbatim: 20 formulas in \( \) and \[ \],
// inside paragraphs, list items and mid-sentence.
const T_TEST_ANSWER = String.raw`A t-test compares a sample mean (or difference) to a hypothesized value, scaled by an estimate of the standard error. The most common formulas are:

One-sample t-test (testing whether sample mean \(\bar{x}\) differs from population value \(\mu_0\)):
\[ t = \frac{\bar{x}-\mu_0}{s/\sqrt{n}} \]
where \(s\) is the sample standard deviation and \(n\) the sample size. Degrees of freedom (df) = \(n-1\).

Paired t-test (testing mean difference \(\bar{d}\) against 0, with \(d_i = x_{1i}-x_{2i}\)):
\[ t = \frac{\bar{d}}{s_d/\sqrt{n}} \]
where \(s_d\) is the standard deviation of the differences and df = \(n-1\).

Independent-samples t-test (two independent groups):
- If you assume equal population variances (pooled t-test):
  \[ s_p^2 = \frac{(n_1-1)s_1^2 + (n_2-1)s_2^2}{n_1+n_2-2}, \qquad t=\frac{\bar{x}_1-\bar{x}_2}{s_p\sqrt{1/n_1+1/n_2}}. \]
  df = \(n_1+n_2-2\).
- If you do NOT assume equal variances (Welch's t-test):
  \[ t=\frac{\bar{x}_1-\bar{x}_2}{\sqrt{s_1^2/n_1 + s_2^2/n_2}} , \]
  with df approximated by the Welch–Satterthwaite formula
  \[ \mathrm{df}\approx \frac{(s_1^2/n_1 + s_2^2/n_2)^2}{(s_1^4/((n_1^2)(n_1-1))) + (s_2^4/((n_2^2)(n_2-1)))}. \]

Worked brief example: one-sample case. If \(n=25\), \(\bar{x}=103\), \(s=10\), test \(\mu_0=100\):
\[ t=\frac{103-100}{10/\sqrt{25}}=\frac{3}{2}=1.5, \] df = 24. You would compare 1.5 to the t-distribution with 24 df to get a p-value.`;

function render(markdown: string): HTMLElement {
  const el = document.createElement('div');
  el.innerHTML = renderRichText(markdown);
  return el;
}

const count = (el: HTMLElement, selector: string) => el.querySelectorAll(selector).length;

describe('renderRichText', () => {

  describe('maths', () => {

    it('renders every formula of the reported t-test answer, with no errors', () => {
      const el = render(T_TEST_ANSWER);
      expect(count(el, '.katex')).toBe(20);
      expect(count(el, '.katex-error')).toBe(0);

      // No LaTeX may be left in what the student sees. The hidden MathML keeps
      // the source on purpose (screen readers, copy), so look past it.
      el.querySelectorAll('.katex-mathml').forEach((n) => n.remove());
      expect(el.textContent).not.toMatch(/\\\(|\\\[|\\frac|\\bar/);
    });

    it('renders \\( \\) inline and \\[ \\] as display maths', () => {
      const el = render(String.raw`mean \(\bar{x}\) and

\[ t = \frac{a}{b} \]`);
      expect(count(el, '.katex')).toBe(2);
      expect(count(el, '.katex-display')).toBe(1);
    });

    it('renders the $ and $$ style the course materials use, including multi-line blocks', () => {
      const el = render(String.raw`reject when $|t| > t_{1-\alpha/2}$, where

$$
t = \frac { \bar { X } - \bar { Y } } { S _ { p } }
$$`);
      expect(count(el, '.katex')).toBe(2);
      expect(count(el, '.katex-display')).toBe(1);
      expect(count(el, '.katex-error')).toBe(0);
    });

    it('renders $ formulas that end in an escaped character, like the set notation in the course materials', () => {
      const el = render(String.raw`samples $S_X = \{X_1, \dots, X_m\}$ and $S_Y = \{Y_1, \dots, Y_n\}$ with spacing $a\,$`);
      expect(count(el, '.katex')).toBe(3);
      expect(el.textContent).not.toContain('$');
    });

    it('renders a multi-line \\[ \\] block', () => {
      const el = render('\\[\nt = \\frac{a}{b}\n\\]');
      expect(count(el, '.katex-display')).toBe(1);
    });

    it('does not read _ or * inside a formula as emphasis', () => {
      const el = render(String.raw`\(\bar{x}_1 * \bar{x}_2\) and \(a_1 * b_2\)`);
      expect(count(el, '.katex')).toBe(2);
      expect(count(el, 'em')).toBe(0);
    });

    it('leaves prose dollar amounts alone', () => {
      const el = render('A coffee costs $5 and a book $10, not maths.');
      expect(count(el, '.katex')).toBe(0);
      expect(el.textContent).toContain('$5 and a book $10');
    });

    it('leaves maths inside code alone', () => {
      const el = render('Use `$x$` literally:\n\n```\n\\(x\\) and $$y$$\n```');
      expect(count(el, '.katex')).toBe(0);
    });

    it('shows a formula KaTeX cannot parse as source, and keeps the rest of the answer', () => {
      const el = render('broken \\(\\frac{1}{\\) but this survives');
      expect(count(el, '.katex-error')).toBe(1);
      expect(el.textContent).toContain('but this survives');
    });
  });

  describe('markdown', () => {

    it('renders the usual chat formatting', () => {
      const el = render('**bold**\n\n- one\n- two\n\n_Source: Type I Error (p. 4)_');
      expect(count(el, 'strong')).toBe(1);
      expect(count(el, 'li')).toBe(2);
      expect(el.querySelector('em')?.textContent).toBe('Source: Type I Error (p. 4)');
    });

    it('keeps single newlines as line breaks, as the model means them', () => {
      expect(count(render('first line\nsecond line'), 'br')).toBe(1);
    });

    it('renders code blocks escaped when highlight.js is not loaded', () => {
      const el = render('```r\nx <- c(1, 2)\n```');
      expect(el.querySelector('pre code.hljs')?.textContent).toBe('x <- c(1, 2)');
    });

    it('uses highlight.js for a known language when it is loaded', () => {
      const g = globalThis as { hljs?: unknown };
      g.hljs = {
        getLanguage: (name: string) => (name === 'r' ? {} : undefined),
        highlight: () => ({ value: '<span class="hljs-keyword">x</span>' }),
      };
      try {
        expect(count(render('```r\nx\n```'), 'pre code.hljs .hljs-keyword')).toBe(1);
        expect(count(render('```nosuchlang\nx\n```'), '.hljs-keyword')).toBe(0);
      } finally {
        delete g.hljs;
      }
    });
  });

  describe('safety', () => {

    it('shows raw HTML as text instead of interpreting it', () => {
      const el = render('hi <img src=x onerror="alert(1)"> <script>alert(2)</script> <b>bold</b>');
      expect(count(el, 'img, script, b')).toBe(0);
      expect(el.textContent).toContain('<img src=x');
    });

    it('turns Markdown images into their alt text, so no external URL is fetched', () => {
      // marked passes alt text through raw, so markup in it must not come alive either
      const el = render('![a <b>chart</b> <img src=https://example.com/x.png>](https://example.com/chart.png)');
      expect(count(el, 'img, b')).toBe(0);
      expect(el.textContent?.trim()).toBe('a <b>chart</b> <img src=https://example.com/x.png>');
    });

    it('opens links in a new tab', () => {
      const a = render('[R docs](https://www.r-project.org/)').querySelector('a');
      expect(a?.getAttribute('target')).toBe('_blank');
      expect(a?.getAttribute('rel')).toBe('noopener noreferrer');
    });

    it('drops javascript: links', () => {
      const a = render('[click](javascript:alert(1))').querySelector('a');
      expect(a?.getAttribute('href') ?? '').not.toContain('javascript');
    });
  });
});

describe('RichTextPipe', () => {

  it('hands Angular the rendered HTML as trusted, so the KaTeX layout survives', () => {
    const pipe = TestBed.runInInjectionContext(() => new RichTextPipe());
    const html = TestBed.inject(DomSanitizer).sanitize(SecurityContext.HTML, pipe.transform('\\(\\frac{1}{2}\\)')) ?? '';
    expect(html).toContain('class="katex"');
    expect(html).toContain('style=');
  });

  it('treats a missing message as empty', () => {
    const pipe = TestBed.runInInjectionContext(() => new RichTextPipe());
    expect(TestBed.inject(DomSanitizer).sanitize(SecurityContext.HTML, pipe.transform(null))).toBe('');
  });
});
