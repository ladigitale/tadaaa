#!/usr/bin/env node
/**
 * Émetteur de démonstration du flux en direct d'un run d'artefact (io.stream).
 *
 * N'importe quel programme peut piloter un artefact en direct : celui-ci n'a besoin que de Node 18+
 * (fetch) et du jeton d'émission rendu par le run. Il pilote l'artefact « Régie en direct »
 * (document : docs/examples/regie-en-direct.json ; docs/artifact-io.md, « Flux en direct ») :
 *
 *   - une partition de 60 s en boucle (énergie, teinte, flashs, densité) jouée par chaque viewer ;
 *   - des chapitres, annonces et salves de réactions (actions du store) ;
 *   - des cartes ajoutées sous la scène et un titre réécrit (document.patch, state.delta) ;
 *   - une seconde partition (« Rappel ») à mi-parcours, puis `end`.
 *
 * Usage :
 *   node scripts/demo-direct-stream.mjs --events <eventsUrl absolue> --token <emitToken> [--minutes 3] [--speed 1]
 *
 *   eventsUrl et emitToken viennent de `stream` dans la réponse de run_artifact (mode interactive) ;
 *   eventsUrl est relative : la préfixer par l'origine de l'API (https://api.tadaaa.space…).
 *   --speed 4 joue le scénario 4 fois plus vite (essais). Ctrl-C termine proprement (message end).
 */
const args = Object.fromEntries(
  process.argv.slice(2).reduce((acc, a, i, all) => (a.startsWith("--") ? [...acc, [a.slice(2), all[i + 1]]] : acc), []),
);
const EVENTS = args.events;
const TOKEN = args.token;
const MINUTES = Number(args.minutes ?? 3);
const SPEED = Math.max(0.1, Number(args.speed ?? 1));
if (!EVENTS || !TOKEN) {
  console.error("Usage : node demo-direct-stream.mjs --events <eventsUrl absolue> --token <emitToken> [--minutes 3] [--speed 1]");
  process.exit(2);
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms / SPEED));
const pick = (list) => list[Math.floor(Math.random() * list.length)];
const CARTES = "/views/0/root/nodes/2/nodes/-";

async function send(events) {
  const res = await fetch(EVENTS, {
    method: "POST",
    headers: {Authorization: `Bearer ${TOKEN}`, "Content-Type": "application/json", Accept: "application/json"},
    body: JSON.stringify({events}),
  });
  const body = await res.json().catch(() => ({}));
  if (!res.ok) {
    const msg = body.detail || body.error || res.statusText;
    if (res.status === 429) {
      await sleep(1000);
      return send(events);
    }
    throw new Error(`${res.status} ${msg}`);
  }
  console.log(`→ ${events.map((e) => e.type === "action" ? e.name : e.type).join(", ")}  (seq ${body.seq})`);
  return body;
}

/** Partition : temps en ms (à vitesse normale), divisés par --speed pour rester synchrone avec le scénario. */
function score(base) {
  const k = (at, value, ease = "inOut") => ({at: Math.round(at / SPEED), value, ease});
  const flashes = [];
  for (const at of base.drops) flashes.push(k(at, 0, "step"), k(at + 40, 1, "linear"), k(at + 700, 0, "out"));
  return {
    duration: Math.round(60000 / SPEED),
    loop: true,
    tracks: [
      {path: "/param0", keys: base.energy.map(([at, v]) => k(at, v))},
      {path: "/param1", keys: base.hue.map(([at, v]) => k(at, v))},
      {path: "/param2", keys: [k(0, 0, "step"), ...flashes, k(60000, 0, "step")]},
      {path: "/param3", keys: base.density.map(([at, v]) => k(at, v))},
    ],
    events: base.chapters.map(([at, nom, soustitre]) => ({at: Math.round(at / SPEED), action: "chapitre", payload: {nom, soustitre}})),
  };
}

const SET = score({
  energy: [[0, 0.15], [15000, 0.35], [29000, 0.85], [30000, 1], [45000, 0.9], [58000, 0.15], [60000, 0.15]],
  hue: [[0, 0.55], [15000, 0.62], [30000, 0.9], [45000, 1.05], [60000, 1.55]],
  density: [[0, 0.3], [15000, 0.5], [30000, 0.9], [45000, 0.7], [60000, 0.3]],
  drops: [30000, 32000, 34000, 36000, 38000],
  chapters: [
    [0, "Ouverture", "Le ciel s'allume doucement."],
    [15000, "Montée", "L'énergie grimpe…"],
    [30000, "Drop !", "Plein régime."],
    [45000, "Retombée", "On respire."],
  ],
});

const RAPPEL = score({
  energy: [[0, 0.6], [8000, 1], [30000, 1], [52000, 0.7], [60000, 0.6]],
  hue: [[0, 0.05], [20000, 0.25], [40000, 0.4], [60000, 1.05]],
  density: [[0, 0.8], [30000, 1], [60000, 0.8]],
  drops: [4000, 8000, 12000, 16000, 20000, 24000, 28000, 44000, 46000, 48000],
  chapters: [
    [0, "Rappel", "Une seconde partition, plus chaude."],
    [30000, "Final", "Tout le monde debout."],
  ],
});

const ANNONCES = [
  "Bienvenue à celles et ceux qui nous rejoignent.",
  "Salut depuis Tours !",
  "Le son est parfait ce soir.",
  "Quelqu'un a vu passer une étoile filante ?",
  "On monte encore d'un cran.",
  "Merci à la régie.",
  "Magnifique cette couleur.",
  "Ça vibre jusqu'au fond de la salle.",
  "Spectateurs connectés depuis trois villes.",
  "Une partition est jouée par chaque écran : tout le monde voit la même chose au même instant.",
];

const carte = (titre, texte) => ({
  type: "document.patch",
  patch: [{op: "add", path: CARTES, value: {
    tagName: "div",
    attributes: {style: "border:1px solid var(--sc-base-200);border-radius:.8rem;padding:.9rem 1rem"},
    nodes: [
      {tagName: "div", attributes: {style: "font-weight:700;margin-bottom:.3rem"}, textContent: titre},
      {tagName: "div", attributes: {style: "font-size:.9rem;opacity:.85"}, textContent: texte},
    ],
  }}],
});

const scenario = [
  [0, [{type: "action", name: "annonce", payload: {texte: "Signal reçu : la régie prend la main."}}, {type: "score", score: SET}]],
  [12000, [carte("Ce qui arrive par le flux", "Actions (chapitres, annonces, réactions), état, partitions et ces cartes : quatre sortes de messages, un seul canal.")]],
  [35000, [carte("Rejoindre en cours", "Un spectateur qui arrive maintenant reçoit l'état courant, les cartes et la partition au bon endroit.")]],
  [50000, [{type: "state.delta", patch: [{op: "replace", path: "/titre", value: "Régie en direct · ce soir"}]}]],
  [Math.round(MINUTES * 60000 * 0.55), [{type: "score", score: RAPPEL}, carte("Rappel", "Nouvelle partition envoyée à mi-parcours : les écrans changent tous ensemble.")]],
];

let ended = false;
async function end() {
  if (ended) return;
  ended = true;
  try {
    await send([
      {type: "action", name: "chapitre", payload: {nom: "Fin", soustitre: "Merci d'avoir suivi le direct."}},
      {type: "score.stop"},
      {type: "state.delta", patch: [{op: "replace", path: "/param0", value: 0.1}, {op: "replace", path: "/param2", value: 0}]},
      {type: "end"},
    ]);
  } catch (e) {
    console.error("fin :", e.message);
  }
}
process.on("SIGINT", async () => {
  await end();
  process.exit(0);
});

const start = Date.now();
const elapsed = () => (Date.now() - start) * SPEED;
const total = MINUTES * 60000;
let next = 0;
let chatter = 4000;
try {
  while (elapsed() < total) {
    while (next < scenario.length && scenario[next][0] <= elapsed()) await send(scenario[next++][1]);
    if (elapsed() >= chatter) {
      const batch = [{type: "action", name: "reaction", payload: {n: 1 + Math.floor(Math.random() * 25)}}];
      if (Math.random() < 0.6) batch.unshift({type: "action", name: "annonce", payload: {texte: pick(ANNONCES)}});
      await send(batch);
      chatter += 2500 + Math.random() * 3500;
    }
    await sleep(250);
  }
} catch (e) {
  console.error("Arrêt :", e.message);
  process.exit(1);
}
await end();
