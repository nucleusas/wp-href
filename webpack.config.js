const path = require("path");
const MiniCssExtractPlugin = require("mini-css-extract-plugin");
const { CleanWebpackPlugin } = require("clean-webpack-plugin");
const { WebpackManifestPlugin } = require("webpack-manifest-plugin");

module.exports = {
  entry: {
    post: "./src/js/post.js",
    "admin-settings": "./src/js/admin/admin-settings.js",
  },
  output: {
    filename: "js/[name].[contenthash].js",
    path: path.resolve(__dirname, "dist"),
  },
  plugins: [
    new CleanWebpackPlugin(),
    new MiniCssExtractPlugin({
      filename: "css/[name].[contenthash].css",
    }),
    new WebpackManifestPlugin({
      fileName: "manifest.json",
      publicPath: "",
      filter: (file) => file.isInitial
    }),
  ],
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
      {
        test: /\.css$/,
        use: [MiniCssExtractPlugin.loader, "css-loader"],
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
