import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            // Tailwind's default scale jumps 4 -> 5, but the portal uses `h-4.5`
            // in several places (18px icons). Without this the class compiles to
            // nothing and the icon renders unsized — which is how the "add"
            // buttons on the vendor profile became invisible.
            spacing: {
                4.5: '1.125rem',
            },
        },
    },

    plugins: [forms],
};
