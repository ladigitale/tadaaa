module.exports = {
  content: ["./src/**/*.{js,ts,css}", "./index.html"],
  theme: {
    borderRadius: {
      none: "0",
      sm: "var(--sc-rounded-sm)",
      DEFAULT: "var(--sc-rounded)",
      md: "var(--sc-rounded-md)",
      lg: "var(--sc-rounded-lg)",
      xl: "var(--sc-rounded-xl)",
      full: "9999px",
    },
    colors: {
      transparent: "transparent",
      current: "currentColor",
      content: "var(--sc-base-content)",
      neutral: {
        0: "var(--sc-base)",
        100: "var(--sc-base-100)",
        200: "var(--sc-base-200)",
        300: "var(--sc-base-300)",
        400: "var(--sc-base-400)",
        500: "var(--sc-base-500)",
        600: "var(--sc-base-600)",
        700: "var(--sc-base-700)",
        800: "var(--sc-base-800)",
        900: "var(--sc-base-900)",
        content: "var(--sc-base-content)",
      },
      primary: {
        DEFAULT: "var(--sc-primary)",
        content: "var(--sc-primary-content)",
      },
      success: {
        DEFAULT: "var(--sc-success)",
        content: "var(--sc-success-content)",
      },
      danger: {
        DEFAULT: "var(--sc-danger)",
        content: "var(--sc-danger-content)",
      },
      warning: {
        DEFAULT: "var(--sc-warning)",
        content: "var(--sc-warning-content)",
      },
      info: {
        DEFAULT: "var(--sc-info)",
        content: "var(--sc-info-content)",
      },
      contrast: {
        DEFAULT: "var(--sc-contrast)",
        content: "var(--sc-contrast-content)",
      },
    },
    extend: {
      borderWidth: {
        DEFAULT: "var(--sc-border-width)",
      },
      fontFamily: {
        headings: ["var(--sc-font-family-headings)"],
        body: ["var(--sc-font-family-base)"],
      },
      fontWeight: {
        headings: "var(--sc-headings-font-weight)",
      },
    },
  },
  plugins: [require("@tailwindcss/container-queries")],
};
