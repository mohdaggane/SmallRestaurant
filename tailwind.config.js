/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./index.php",
    "./public/**/*.php",
    "./admin/**/*.php",
    "./core/**/*.php",
    "./platform/**/*.php",
    "./assets/js/**/*.js",
  ],
  theme: {
    extend: {
      colors: {
        brand: {
          DEFAULT: "#8a4b1f",
          dark:    "#5d3113",
          light:   "#f6efe7",
        },
        accent:  "#e8a33d",
        ink:     "#2b2018",
        muted:   "#7a6a5d",
        line:    "#e6dcd1",
        ok:      "#1f7a4d",
        bad:     "#b3261e",
      },
      fontFamily: {
        sans: ["Inter", "Segoe UI", "system-ui", "-apple-system", "sans-serif"],
        mono: ["Consolas", "Courier New", "monospace"],
      },
    },
  },
  plugins: [
    require("@tailwindcss/forms"),
  ],
};
