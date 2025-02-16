import colors from 'tailwindcss/colors';
/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./resources/**/*.blade.php",
    "./resources/**/*.js",
    "./resources/**/*.vue",
    "./vendor/livewire/flux-pro/stubs/**/*.blade.php",
    "./vendor/livewire/flux/stubs/**/*.blade.php",
  ],
  theme: {
    extend: {
        colors: {
            // Re-assign Flux's gray of choice...
            zinc: colors.gray,

            // Accent variables are defined in resources/css/app.css...
            accent: {
                DEFAULT: 'var(--color-accent)',
                content: 'var(--color-accent-content)',
                foreground: 'var(--color-accent-foreground)',
            },
        },
    },
  },
  plugins: [],
}
