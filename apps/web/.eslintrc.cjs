module.exports = {
  extends: ["eslint:recommended", "plugin:@typescript-eslint/recommended", "prettier"],
  parser: "@typescript-eslint/parser",
  plugins: ["@typescript-eslint"],
  root: true,
  ignorePatterns: ["**/*.js", "**/*.stories.ts", "**/*.config.ts", "**/*.test.ts", "**/*.d.ts", "**/docs/**/*"],
};
