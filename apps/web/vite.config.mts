import {defineConfig} from "vite";
import concordeConf, {autoRoutesPlugin} from "@supersoniks/concorde/vite-config";
import basicSsl from "@vitejs/plugin-basic-ssl";
import path from "path";
import tsConfig from "./tsconfig.json";
import postcssLit from "rollup-plugin-postcss-lit";
import {demoApiVitePlugin} from "./scripts/demo-api-vite-plugin";

export const packages = {
  concorde: {
    outDir: "dist",
  },
};

let libName = process.env.LIB_NAME;
if (!libName) libName = "concorde";
const currentConfig = packages[libName];

const config = {
  build: {
    outDir: currentConfig.outDir,
    emptyOutDir: false,
    lib: currentConfig.lib,
  },
  server: {
    host: true,
    port: 3000,
    watch: {
      usePolling: true,
      ignored: ["**/*.svg"],
    },
    proxy: {
      // Évite le mixed content HTTPS (Vite) → HTTP (httpd devops)
      "/tada-cloud": {
        target: "https://tada-api.julien.test",
        changeOrigin: true,
        secure: false,
        rewrite: (path) => path.replace(/^\/tada-cloud/, ""),
      },
    },
  },
  plugins: [
    ...(process.env.VITE_DEV_HTTPS === "true" ? [basicSsl()] : []),
    demoApiVitePlugin(),
    autoRoutesPlugin("src/app/routes"),
    postcssLit({
      include: ["/src/**/*.css", "/src/**/*.css?*"],
    }),
  ],
  resolve: {
    alias: {
      "@tailwind": path.resolve(__dirname, "./src/css/tailwind.ts"),
    },
  },
};

export default defineConfig(
  concordeConf({
    componentPrefix: "sonic",
    tsConfig: tsConfig,
    viteConfig: config,
  }),
);
