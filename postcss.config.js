module.exports = {
  plugins: [
    require("postcss-import")({
      path: ["assets/css"], // Hier zoekt postcss-import naar bestanden
    }),
    require("postcss-nested"),
    require("autoprefixer"),
  ],
};
