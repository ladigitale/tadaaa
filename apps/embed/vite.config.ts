import {defineConfig} from "vite";
import {resolve} from "node:path";

// Vite 8 (Oxc) : décorateurs Lit « legacy » et champs de classe en [[Set]], dits ici plutôt
// que déduits du tsconfig. Un champ compilé en [[Define]] masquerait l'accesseur posé par
// @property / @state : le composant ne se mettrait plus à jour.
const decorators = {
  decorator: {legacy: true},
  typescript: {removeClassFieldsWithoutInitializer: true},
  assumptions: {setPublicClassFields: true},
};

export default defineConfig({
  oxc: decorators,
  build: {
    lib: {
      entry: resolve(import.meta.dirname, "src/index.ts"),
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
