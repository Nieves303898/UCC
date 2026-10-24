/** Recompilar CSS: npx tailwindcss@3.4.16 -c tailwind.config.js -i resources/tailwind.css -o public/assets/tailwind.css --minify */
module.exports = {
  content: ['./app/views/**/*.php', './public/assets/app.js'],
  theme: { extend: {} },
};
