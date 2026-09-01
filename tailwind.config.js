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
