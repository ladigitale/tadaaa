import {defineConfig} from "vite";
import {resolve} from "node:path";

export default defineConfig({
  build: {
    lib: {
      entry: resolve(__dirname, "src/index.ts"),
      name: "TadaaaEmbed",
      formats: ["es"],
      fileName: () => "embed.js",
    },
    outDir: "dist",
    emptyOutDir: true,
    target: "es2022",
    minify: true,
  },
});
