/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./src/Views/**/*.php",
    "./public/**/*.html",
    "./src/assets/js/**/*.js"
  ],
  darkMode: 'class',
  theme: {
    extend: {
      colors: {
        // Security Scanner Brand Colors
        primary: {
          50: '#eff6ff',
          100: '#dbeafe',
          200: '#bfdbfe',
          300: '#93c5fd',
          400: '#60a5fa',
          500: '#3b82f6',
          600: '#2563eb',
          700: '#1d4ed8',
          800: '#1e40af',
          900: '#1e3a8a',
          950: '#172554'
        },
        secondary: {
          50: '#f8fafc',
          100: '#f1f5f9',
          200: '#e2e8f0',
          300: '#cbd5e1',
          400: '#94a3b8',
          500: '#64748b',
          600: '#475569',
          700: '#334155',
          800: '#1e293b',
          900: '#0f172a',
          950: '#020617'
        },
        // Status Colors for Security Results
        success: {
          50: '#f0fdf4',
          100: '#dcfce7',
          200: '#bbf7d0',
          300: '#86efac',
          400: '#4ade80',
          500: '#22c55e',
          600: '#16a34a',
          700: '#15803d',
          800: '#166534',
          900: '#14532d',
          950: '#052e16'
        },
        warning: {
          50: '#fffbeb',
          100: '#fef3c7',
          200: '#fde68a',
          300: '#fcd34d',
          400: '#fbbf24',
          500: '#f59e0b',
          600: '#d97706',
          700: '#b45309',
          800: '#92400e',
          900: '#78350f',
          950: '#451a03'
        },
        danger: {
          50: '#fef2f2',
          100: '#fee2e2',
          200: '#fecaca',
          300: '#fca5a5',
          400: '#f87171',
          500: '#ef4444',
          600: '#dc2626',
          700: '#b91c1c',
          800: '#991b1b',
          900: '#7f1d1d',
          950: '#450a0a'
        },
        info: {
          50: '#f0f9ff',
          100: '#e0f2fe',
          200: '#bae6fd',
          300: '#7dd3fc',
          400: '#38bdf8',
          500: '#0ea5e9',
          600: '#0284c7',
          700: '#0369a1',
          800: '#075985',
          900: '#0c4a6e',
          950: '#082f49'
        }
      },
      fontFamily: {
        sans: [
          'Inter',
          '-apple-system',
          'BlinkMacSystemFont',
          'Segoe UI',
          'Roboto',
          'Helvetica Neue',
          'Arial',
          'sans-serif'
        ],
        mono: [
          'JetBrains Mono',
          'SF Mono',
          'Monaco',
          'Inconsolata',
          'Roboto Mono',
          'monospace'
        ]
      },
      fontSize: {
        '2xs': ['0.625rem', { lineHeight: '0.75rem' }],
        xs: ['0.75rem', { lineHeight: '1rem' }],
        sm: ['0.875rem', { lineHeight: '1.25rem' }],
        base: ['1rem', { lineHeight: '1.5rem' }],
        lg: ['1.125rem', { lineHeight: '1.75rem' }],
        xl: ['1.25rem', { lineHeight: '1.75rem' }],
        '2xl': ['1.5rem', { lineHeight: '2rem' }],
        '3xl': ['1.875rem', { lineHeight: '2.25rem' }],
        '4xl': ['2.25rem', { lineHeight: '2.5rem' }],
        '5xl': ['3rem', { lineHeight: '1' }],
        '6xl': ['3.75rem', { lineHeight: '1' }],
        '7xl': ['4.5rem', { lineHeight: '1' }],
        '8xl': ['6rem', { lineHeight: '1' }],
        '9xl': ['8rem', { lineHeight: '1' }]
      },
      spacing: {
        18: '4.5rem',
        88: '22rem',
        112: '28rem',
        128: '32rem'
      },
      minHeight: {
        screen: '100vh',
        '1/2': '50vh',
        '3/4': '75vh'
      },
      maxWidth: {
        '8xl': '88rem',
        '9xl': '96rem'
      },
      animation: {
        'fade-in': 'fadeIn 0.5s ease-in-out',
        'slide-in-up': 'slideInUp 0.5s ease-out',
        'slide-in-down': 'slideInDown 0.5s ease-out',
        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
        'bounce-subtle': 'bounceSubtle 2s infinite'
      },
      keyframes: {
        fadeIn: {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' }
        },
        slideInUp: {
          '0%': { transform: 'translateY(100%)', opacity: '0' },
          '100%': { transform: 'translateY(0)', opacity: '1' }
        },
        slideInDown: {
          '0%': { transform: 'translateY(-100%)', opacity: '0' },
          '100%': { transform: 'translateY(0)', opacity: '1' }
        },
        bounceSubtle: {
          '0%, 100%': { transform: 'translateY(0)' },
          '50%': { transform: 'translateY(-5px)' }
        }
      },
      backdropBlur: {
        xs: '2px'
      },
      boxShadow: {
        'inner-lg': 'inset 0 10px 15px -3px rgba(0, 0, 0, 0.1), inset 0 4px 6px -2px rgba(0, 0, 0, 0.05)',
        'glow': '0 0 15px rgba(59, 130, 246, 0.5)',
        'glow-sm': '0 0 10px rgba(59, 130, 246, 0.3)'
      }
    }
  },
  plugins: [
    require('@tailwindcss/forms')({
      strategy: 'class'
    }),
    require('@tailwindcss/typography'),
    require('@tailwindcss/aspect-ratio'),
    function({ addUtilities, addComponents, theme }) {
      // Custom utility classes for Security Scanner
      addUtilities({
        '.text-gradient': {
          'background': 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
          '-webkit-background-clip': 'text',
          '-webkit-text-fill-color': 'transparent',
          'background-clip': 'text'
        },
        '.bg-glass': {
          'backdrop-filter': 'blur(10px)',
          'background': 'rgba(255, 255, 255, 0.1)',
          'border': '1px solid rgba(255, 255, 255, 0.2)'
        },
        '.bg-glass-dark': {
          'backdrop-filter': 'blur(10px)',
          'background': 'rgba(0, 0, 0, 0.1)',
          'border': '1px solid rgba(255, 255, 255, 0.1)'
        }
      });

      // Custom component classes
      addComponents({
        '.btn': {
          '@apply inline-flex items-center justify-center px-4 py-2 text-sm font-medium rounded-lg border transition-all duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-not-allowed': {}
        },
        '.btn-primary': {
          '@apply bg-primary-600 border-primary-600 text-white hover:bg-primary-700 hover:border-primary-700 focus:ring-primary-500': {}
        },
        '.btn-secondary': {
          '@apply bg-secondary-600 border-secondary-600 text-white hover:bg-secondary-700 hover:border-secondary-700 focus:ring-secondary-500': {}
        },
        '.btn-success': {
          '@apply bg-success-600 border-success-600 text-white hover:bg-success-700 hover:border-success-700 focus:ring-success-500': {}
        },
        '.btn-warning': {
          '@apply bg-warning-600 border-warning-600 text-white hover:bg-warning-700 hover:border-warning-700 focus:ring-warning-500': {}
        },
        '.btn-danger': {
          '@apply bg-danger-600 border-danger-600 text-white hover:bg-danger-700 hover:border-danger-700 focus:ring-danger-500': {}
        },
        '.btn-outline': {
          '@apply bg-transparent border-secondary-300 text-secondary-700 hover:bg-secondary-50 focus:ring-secondary-500': {}
        },
        '.btn-ghost': {
          '@apply bg-transparent border-transparent text-secondary-600 hover:bg-secondary-100 focus:ring-secondary-500': {}
        },
        '.card': {
          '@apply bg-white rounded-lg shadow-sm border border-secondary-200 overflow-hidden': {}
        },
        '.card-dark': {
          '@apply bg-secondary-800 border-secondary-700': {}
        },
        '.form-input': {
          '@apply block w-full rounded-lg border-secondary-300 text-secondary-900 placeholder-secondary-400 focus:border-primary-500 focus:ring-primary-500 disabled:bg-secondary-50 disabled:text-secondary-500': {}
        },
        '.form-input-dark': {
          '@apply bg-secondary-800 border-secondary-600 text-white placeholder-secondary-400 focus:border-primary-400 focus:ring-primary-400': {}
        },
        '.status-badge': {
          '@apply inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium': {}
        },
        '.status-success': {
          '@apply bg-success-100 text-success-800': {}
        },
        '.status-warning': {
          '@apply bg-warning-100 text-warning-800': {}
        },
        '.status-danger': {
          '@apply bg-danger-100 text-danger-800': {}
        },
        '.status-info': {
          '@apply bg-info-100 text-info-800': {}
        },
        '.loading-spinner': {
          '@apply inline-block h-4 w-4 animate-spin rounded-full border-2 border-solid border-current border-r-transparent': {}
        }
      });
    }
  ]
};