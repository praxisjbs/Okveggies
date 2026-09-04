/**
 * tailwind.config.js
 * OK Veggies design tokens, straight from the Brand Architecture bible v1.0.
 * Colours, type, radii, shadows and the two motion curves live here so nothing
 * in the app uses an arbitrary value. Gold is never a button fill and never
 * carries its own text (enforced in review, see CLAUDE.md).
 */
module.exports = {
  content: [
    './index.php',
    './*.php',
    './admin/**/*.php',
    './pro/**/*.php',
    './public/**/*.php',
    './includes/components/**/*.php',
    './assets/js/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        forest: {
          DEFAULT: '#0F5132',
          hover:   '#0D472C',
          active:  '#0C3F27',
          tint:    '#E7EEEA',
          tint2:   '#DBE5E0',
        },
        gold: {
          DEFAULT: '#C9922B',
          hover:   '#B18026',
          active:  '#9D7222',
          tint:    '#FAF4EA',
          tint2:   '#F7EFDF',
          ink:     '#7A5A18',
        },
        tomato: {
          DEFAULT: '#C8321E',
          hover:   '#B02C1A',
          active:  '#9C2717',
          tint:    '#FAEAE8',
        },
        foliage: {
          DEFAULT: '#3E8B4A',
          hover:   '#377A41',
          tint:    '#ECF3ED',
        },
        // Clay Terracotta, the bible's tertiary accent. It was already being
        // asked for by name in admin/payments.php and across the M6 order
        // screens, but it had never been added here, so every `border-clay` and
        // `bg-clay-tint` in the markup compiled to nothing and a payment
        // problem looked exactly like a neutral note. The tint is mixed to the
        // same 90% white as tomato and foliage above, so the family stays even.
        clay: {
          DEFAULT: '#B85C3E',
          hover:   '#A45237',
          tint:    '#F8EFEC',
          ink:     '#7A3B28',
        },
        ink: {
          DEFAULT: '#03100A',
          60:      'rgba(3,16,10,0.62)',
          40:      'rgba(3,16,10,0.40)',
        },
        mist: '#EAE8E8',
      },
      fontFamily: {
        // Hanken Grotesk is the workhorse for body, UI and strong headings; it
        // carries the 400 to 800 weights the interface leans on. DM Serif Display
        // is the editorial voice (headlines, manifesto, pull quotes, combo names),
        // opted in with `font-editorial`. JetBrains Mono lines up figures. Bible 5.1.
        sans:      ['"Hanken Grotesk"', 'Segoe UI', '-apple-system', 'sans-serif'],
        display:   ['"Hanken Grotesk"', 'Segoe UI', '-apple-system', 'sans-serif'],
        editorial: ['"DM Serif Display"', 'Georgia', 'Cambria', 'serif'],
        mono:      ['"JetBrains Mono"', '"SF Mono"', 'Consolas', 'monospace'],
      },
      fontSize: {
        // The bible 5.2 type scale: a 1.25 ratio on a 16px base, computed and
        // rounded to the whole pixel, each step carrying its own line height.
        // Named tokens sit beside Tailwind's own steps rather than replacing
        // them, so the storefront can move onto the real scale without
        // resizing admin and Pro, which land with the back-office pass.
        'okv-display': ['5.9375rem', { lineHeight: '1.05' }], // 95px
        'okv-h1':      ['4.75rem',   { lineHeight: '1.1' }],  // 76px
        'okv-h2':      ['3.8125rem', { lineHeight: '1.15' }], // 61px
        'okv-h3':      ['3.0625rem', { lineHeight: '1.2' }],  // 49px
        'okv-h4':      ['2.4375rem', { lineHeight: '1.25' }], // 39px
        'okv-h5':      ['1.9375rem', { lineHeight: '1.3' }],  // 31px
        'okv-h6':      ['1.5625rem', { lineHeight: '1.35' }], // 25px
        'okv-lead':    ['1.25rem',   { lineHeight: '1.5' }],  // 20px
        'okv-body':    ['1rem',      { lineHeight: '1.7' }],  // 16px
        'okv-label':   ['0.8125rem', { lineHeight: '1.5' }],  // 13px
        'okv-caption': ['0.625rem',  { lineHeight: '1.4' }],  // 10px
      },
      borderRadius: {
        sm: '3px',
        md: '6px',
        lg: '12px',
        full: '9999px',
      },
      boxShadow: {
        'okv-1': '0 1px 2px rgba(3,16,10,0.07)',
        'okv-2': '0 2px 8px rgba(3,16,10,0.08), 0 1px 2px rgba(3,16,10,0.06)',
        'okv-3': '0 12px 32px rgba(3,16,10,0.14), 0 2px 8px rgba(3,16,10,0.08)',
      },
      maxWidth: {
        content: '1280px',
      },
      transitionTimingFunction: {
        botanical: 'cubic-bezier(0.4, 0.0, 0.2, 1)',
        bounce:    'cubic-bezier(0.34, 1.56, 0.64, 1)',
      },
      transitionDuration: {
        botanical: '240ms',
        bounce:    '320ms',
      },
      keyframes: {
        'okv-rise': {
          '0%':   { opacity: '0', transform: 'translateY(8px)' },
          '100%': { opacity: '1', transform: 'translateY(0)' },
        },
        'okv-pop': {
          '0%':   { transform: 'scale(0.9)' },
          '60%':  { transform: 'scale(1.06)' },
          '100%': { transform: 'scale(1)' },
        },
      },
      animation: {
        'okv-rise': 'okv-rise 240ms cubic-bezier(0.4,0,0.2,1)',
        'okv-pop':  'okv-pop 320ms cubic-bezier(0.34,1.56,0.64,1)',
      },
    },
  },
  plugins: [
    require('@tailwindcss/forms'),
    require('@tailwindcss/typography'),
  ],
};
