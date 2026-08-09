/** @type {import('tailwindcss').Config} */
export default {
  content: ['./admin/src/**/*.{ts,tsx}'],
  important: '#stockino-admin-root',
  corePlugins: { preflight: false },
  theme: {
    extend: {
      fontFamily: {
        sans: ['Vazirmatn', 'Tahoma', 'Arial', 'sans-serif'],
        mono: ['Fira Code', 'ui-monospace', 'monospace'],
      },
      colors: {
        ink: '#13233b',
        canvas: '#f4f7fb',
        brand: '#2563eb',
        action: '#ea580c'
      },
      boxShadow: {
        panel: '0 0 0 1px rgba(15,23,42,.055), 0 2px 5px rgba(15,23,42,.05), 0 18px 48px rgba(15,23,42,.055)'
      }
    },
  },
  plugins: [],
};

