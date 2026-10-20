/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      screens: {
        'custom': '920px',


      },
      // The dashboard's own teal, named.
      //
      // Every value here is a hex this codebase already ships — #007980 is the primary
      // (Colors.jsx `bgprimary`, and some four hundred literals besides), #85cbcf the
      // secondary it pairs with, and the rest are the tints already in use around them.
      // Naming them changes nothing that renders today; it only means new markup can say
      // `brand-600` instead of `bg-[#007980]`, and that a future change of brand is one
      // edit rather than a find-and-replace across the app.
      colors: {
        brand: {
          50: '#f0fbfb',
          100: '#d6eff0',
          200: '#85cbcf',
          300: '#63c2c7',
          400: '#48bcc2',
          500: '#0a9aa3',
          600: '#007980', // primary
          700: '#006670',
          800: '#08535a',
          900: '#0b4349',
          DEFAULT: '#007980',
        },
      },
      // Type that settles between phone and desktop on its own, so a heading does not have
      // to be sized twice. Used for the headings and ledes of the migration flow, where the
      // same sentence has to read on a 360px screen and a wide admin column.
      fontSize: {
        'fluid-xs':   ['clamp(0.70rem, 0.66rem + 0.18vw, 0.78rem)', { lineHeight: '1.4' }],
        'fluid-sm':   ['clamp(0.80rem, 0.74rem + 0.28vw, 0.92rem)', { lineHeight: '1.45' }],
        'fluid-base': ['clamp(0.875rem, 0.80rem + 0.35vw, 1.00rem)', { lineHeight: '1.5' }],
        'fluid-lg':   ['clamp(1.00rem, 0.90rem + 0.50vw, 1.20rem)', { lineHeight: '1.4' }],
        'fluid-xl':   ['clamp(1.15rem, 1.00rem + 0.70vw, 1.50rem)', { lineHeight: '1.3' }],
      },
    },
  },
  plugins: [],
}


