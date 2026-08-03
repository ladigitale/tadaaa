/** One-liner / composition presets for the public embed bundle. */

export type EmbedPresetId = "list" | "agenda" | "kpi" | "board";

export type EmbedPreset = {
  id: EmbedPresetId;
  /** i18n key for label */
  labelKey: string;
  /** i18n key for short help */
  helpKey: string;
};

export const EMBED_PRESETS: readonly EmbedPreset[] = [
  {
    id: "list",
    labelKey: "embeds.preset.list",
    helpKey: "embeds.preset.list.help",
  },
  {
    id: "agenda",
    labelKey: "embeds.preset.agenda",
    helpKey: "embeds.preset.agenda.help",
  },
  {
    id: "kpi",
    labelKey: "embeds.preset.kpi",
    helpKey: "embeds.preset.kpi.help",
  },
  {
    id: "board",
    labelKey: "embeds.preset.board",
    helpKey: "embeds.preset.board.help",
  },
] as const;

export function buildEmbedSnippet(opts: {
  key: string;
  apiBase: string;
  scriptSrc: string;
  preset: EmbedPresetId;
  accent?: string;
}): string {
  const accent = opts.accent ?? "#0d9488";
  const head = `<script type="module" src="${opts.scriptSrc}"></script>`;
  const attrs = `key="${opts.key}"
  api-base="${opts.apiBase}"
  theme="auto"
  accent="${accent}"`;

  switch (opts.preset) {
    case "agenda":
      return `${head}
<tadaaa-embed
  ${attrs}
  view="agenda"
>
  <tadaaa-agenda days="14"></tadaaa-agenda>
</tadaaa-embed>`;
    case "kpi":
      return `${head}
<tadaaa-embed
  ${attrs}
  view="kpi"
></tadaaa-embed>`;
    case "board":
      return `${head}
<tadaaa-embed
  ${attrs}
>
  <tadaaa-filter></tadaaa-filter>
  <tadaaa-kpi></tadaaa-kpi>
  <tadaaa-list></tadaaa-list>
  <tadaaa-agenda days="14"></tadaaa-agenda>
</tadaaa-embed>`;
    case "list":
    default:
      return `${head}
<tadaaa-embed
  ${attrs}
  view="list"
></tadaaa-embed>`;
  }
}
