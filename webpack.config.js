const path = require("path");

module.exports = {
  entry: "./src/admin.js",
  output: {
    filename: "admin.js",
    path: path.resolve(__dirname, "dist/js"),
  },
  module: {
    rules: [
      {
        test: /\.js$/,
        exclude: /node_modules/,
        use: {
          loader: "babel-loader",
          options: {
            presets: ["@babel/preset-env", "@babel/preset-react"],
          },
        },
      },
    ],
  },
  externals: {
    react: "React",
    "react-dom": "ReactDOM",
    "@wordpress/components": "wp.components",
    "@wordpress/element": "wp.element",
    "@wordpress/data": "wp.data",
    "@wordpress/i18n": "wp.i18n",
    "@wordpress/plugins": "wp.plugins",
    "@wordpress/editor": "wp.editor",
  },
};
