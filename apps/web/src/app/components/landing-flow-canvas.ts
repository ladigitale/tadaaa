import {css, html, LitElement} from "lit";
import {customElement} from "lit/decorators.js";

type TaskCard = {
  x: number;
  y: number;
  w: number;
  h: number;
  phase: number;
  speed: number;
  checked: boolean;
  checkT: number;
  tagHue: number;
};

type Particle = {
  t: number;
  speed: number;
  outbound: boolean;
  lane: number;
};

/**
 * Soft canvas backdrop: local task cards ↔ cloud sync arcs.
 * Full-viewport fixed layer — paused when reduced-motion is preferred.
 */
@customElement("landing-flow-canvas")
export class LandingFlowCanvas extends LitElement {
  static styles = css`
    :host {
      display: block;
      position: fixed;
      inset: 0;
      width: 100%;
      height: 100%;
      pointer-events: none;
      overflow: hidden;
      z-index: 0;
    }
    canvas {
      display: block;
      width: 100%;
      height: 100%;
    }
  `;

  private canvas: HTMLCanvasElement | null = null;
  private raf = 0;
  private running = false;
  private reducedMotion = false;
  private cards: TaskCard[] = [];
  private particles: Particle[] = [];
  private t0 = 0;
  /** CSS pixel size of the viewport-backed canvas. */
  private cssW = 0;
  private cssH = 0;
  private onVisibility = () => this.syncLoop();
  private onResize = () => {
    this.fit();
    if (!this.running) this.paint(0);
  };
  private onMotion = (e: MediaQueryListEvent) => {
    this.reducedMotion = e.matches;
    this.syncLoop();
    if (this.reducedMotion) this.paint(0);
  };

  firstUpdated() {
    this.canvas = this.renderRoot.querySelector("canvas");
    this.reducedMotion = window.matchMedia(
      "(prefers-reduced-motion: reduce)",
    ).matches;
    this.seed();
    this.fit();
    window.addEventListener("resize", this.onResize);
    window.visualViewport?.addEventListener("resize", this.onResize);
    window.visualViewport?.addEventListener("scroll", this.onResize);
    window
      .matchMedia("(prefers-reduced-motion: reduce)")
      .addEventListener("change", this.onMotion);
    document.addEventListener("visibilitychange", this.onVisibility);
    this.syncLoop();
    if (this.reducedMotion) this.paint(0);
  }

  disconnectedCallback() {
    super.disconnectedCallback();
    this.stop();
    window.removeEventListener("resize", this.onResize);
    window.visualViewport?.removeEventListener("resize", this.onResize);
    window.visualViewport?.removeEventListener("scroll", this.onResize);
    document.removeEventListener("visibilitychange", this.onVisibility);
    window
      .matchMedia("(prefers-reduced-motion: reduce)")
      .removeEventListener("change", this.onMotion);
  }

  private syncLoop() {
    const shouldRun =
      !this.reducedMotion && document.visibilityState === "visible";
    if (shouldRun) this.start();
    else this.stop();
  }

  private start() {
    if (this.running) return;
    this.running = true;
    this.t0 = performance.now() / 1000;
    const tick = () => {
      if (!this.running) return;
      this.paint(performance.now() / 1000 - this.t0);
      this.raf = requestAnimationFrame(tick);
    };
    this.raf = requestAnimationFrame(tick);
  }

  private stop() {
    this.running = false;
    if (this.raf) cancelAnimationFrame(this.raf);
    this.raf = 0;
  }

  private seed() {
    this.cards = Array.from({length: 7}, (_, i) => ({
      x: 0.08 + (i % 3) * 0.12 + (i > 3 ? 0.04 : 0),
      y: 0.22 + Math.floor(i / 3) * 0.14 + (i % 2) * 0.03,
      w: 0.16 + (i % 3) * 0.02,
      h: 0.055,
      phase: Math.random() * Math.PI * 2,
      speed: 0.35 + Math.random() * 0.4,
      checked: i % 3 !== 0,
      checkT: i % 3 !== 0 ? 1 : 0,
      tagHue: (i * 47) % 360,
    }));
    this.particles = Array.from({length: 14}, (_, i) => ({
      t: Math.random(),
      speed: 0.08 + Math.random() * 0.1,
      outbound: i % 2 === 0,
      lane: (i % 5) - 2,
    }));
  }

  private viewportSize() {
    const vv = window.visualViewport;
    const w = Math.max(
      1,
      Math.floor(vv?.width ?? window.innerWidth ?? document.documentElement.clientWidth),
    );
    const h = Math.max(
      1,
      Math.floor(vv?.height ?? window.innerHeight ?? document.documentElement.clientHeight),
    );
    return {w, h};
  }

  private fit() {
    const canvas = this.canvas;
    if (!canvas) return;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    const {w, h} = this.viewportSize();
    if (w === this.cssW && h === this.cssH && canvas.width === Math.floor(w * dpr)) {
      return;
    }
    this.cssW = w;
    this.cssH = h;
    canvas.width = Math.floor(w * dpr);
    canvas.height = Math.floor(h * dpr);
    const ctx = canvas.getContext("2d");
    if (ctx) ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  }

  private cssVar(name: string, fallback: string) {
    const v = getComputedStyle(document.documentElement)
      .getPropertyValue(name)
      .trim();
    return v || fallback;
  }

  private withAlpha(color: string, alpha: number) {
    if (color.startsWith("#") && (color.length === 7 || color.length === 4)) {
      const hex =
        color.length === 4
          ? `#${color[1]}${color[1]}${color[2]}${color[2]}${color[3]}${color[3]}`
          : color;
      const r = parseInt(hex.slice(1, 3), 16);
      const g = parseInt(hex.slice(3, 5), 16);
      const b = parseInt(hex.slice(5, 7), 16);
      return `rgba(${r},${g},${b},${alpha})`;
    }
    return color;
  }

  private paint(time: number) {
    const canvas = this.canvas;
    if (!canvas) return;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;
    this.fit();
    const width = this.cssW;
    const height = this.cssH;
    if (width < 2 || height < 2) return;

    const base = this.cssVar("--sc-base", "#fafaf9");
    const primary = this.cssVar("--sc-primary", "#10298e");
    const info = this.cssVar("--sc-info", "#27b2ff");
    const success = this.cssVar("--sc-success", "#00cc57");
    const content = this.cssVar("--sc-base-content", "#1c1917");
    const muted = this.cssVar("--sc-base-300", "#bcbbba");

    ctx.clearRect(0, 0, width, height);

    /* Soft atmosphere */
    const g = ctx.createRadialGradient(
      width * 0.75,
      height * 0.18,
      0,
      width * 0.55,
      height * 0.45,
      Math.max(width, height) * 0.85,
    );
    g.addColorStop(0, this.withAlpha(info, 0.14));
    g.addColorStop(0.45, this.withAlpha(primary, 0.07));
    g.addColorStop(1, this.withAlpha(base, 0));
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, width, height);

    const localX = width * 0.22;
    const localY = height * 0.62;
    const cloudX = width * 0.78;
    const cloudY = height * 0.28;

    this.drawDevice(ctx, localX, localY, primary, muted, content);
    this.drawCloud(ctx, cloudX, cloudY, info, primary, time);

    /* Sync arcs — thin dashed lanes (mid depth, behind cards) */
    for (let i = -1; i <= 1; i++) {
      const wobble = Math.sin(time * 0.7 + i) * 8;
      ctx.beginPath();
      ctx.moveTo(localX + 40, localY - 30);
      ctx.bezierCurveTo(
        width * 0.4,
        height * 0.35 + i * 18 + wobble,
        width * 0.58,
        height * 0.22 + i * 14 - wobble,
        cloudX - 36,
        cloudY + 10,
      );
      ctx.strokeStyle = this.withAlpha(primary, 0.1 + Math.abs(i) * 0.04);
      ctx.lineWidth = 0.75;
      ctx.setLineDash([3, 7]);
      ctx.lineDashOffset = -time * 24 * (i % 2 === 0 ? 1 : -0.7);
      ctx.stroke();
      ctx.setLineDash([]);
    }

    /* Particles along sync path */
    for (const p of this.particles) {
      if (!this.reducedMotion) {
        p.t = (p.t + p.speed * 0.016) % 1;
      }
      const u = p.outbound ? p.t : 1 - p.t;
      const lane = p.lane * 10;
      const x =
        (1 - u) * (1 - u) * (localX + 40) +
        2 * (1 - u) * u * (width * 0.5) +
        u * u * (cloudX - 36);
      const y =
        (1 - u) * (1 - u) * (localY - 30 + lane) +
        2 * (1 - u) * u * (height * 0.28 + lane) +
        u * u * (cloudY + 10 + lane * 0.4);
      ctx.beginPath();
      ctx.arc(x, y, p.outbound ? 2.4 : 2, 0, Math.PI * 2);
      ctx.fillStyle = this.withAlpha(p.outbound ? info : success, 0.55);
      ctx.fill();
    }

    /* Floating task cards (local side) */
    for (const card of this.cards) {
      const bob = this.reducedMotion
        ? 0
        : Math.sin(time * card.speed + card.phase) * 6;
      const cx = width * card.x;
      const cy = height * card.y + bob;
      const cw = width * card.w;
      const ch = Math.max(28, height * card.h);

      if (!this.reducedMotion && !card.checked && Math.random() < 0.002) {
        card.checked = true;
      }
      if (!this.reducedMotion && card.checked && card.checkT < 1) {
        card.checkT = Math.min(1, card.checkT + 0.02);
      }
      if (!this.reducedMotion && card.checked && card.checkT >= 1 && Math.random() < 0.0015) {
        card.checked = false;
        card.checkT = 0;
      }

      /* Cards: fill only — edge reads via opacity vs backdrop (foreground) */
      this.roundRect(ctx, cx, cy, cw, ch, 10);
      ctx.fillStyle = this.withAlpha(base, 0.78);
      ctx.fill();

      /* checkbox */
      const bx = cx + 10;
      const by = cy + ch / 2 - 7;
      this.roundRect(ctx, bx, by, 14, 14, 4);
      ctx.strokeStyle = this.withAlpha(primary, 0.4);
      ctx.lineWidth = 0.75;
      ctx.stroke();
      if (card.checkT > 0) {
        ctx.beginPath();
        ctx.moveTo(bx + 3, by + 7);
        ctx.lineTo(bx + 6, by + 10);
        ctx.lineTo(bx + 11, by + 4);
        ctx.strokeStyle = this.withAlpha(success, 0.35 + card.checkT * 0.55);
        ctx.lineWidth = 1.15;
        ctx.lineCap = "round";
        ctx.lineJoin = "round";
        ctx.stroke();
      }

      /* title lines */
      ctx.fillStyle = this.withAlpha(content, 0.18);
      this.roundRect(ctx, cx + 32, cy + ch * 0.32, cw * 0.45, 4, 2);
      ctx.fill();
      ctx.fillStyle = this.withAlpha(content, 0.1);
      this.roundRect(ctx, cx + 32, cy + ch * 0.55, cw * 0.28, 3, 2);
      ctx.fill();

      /* tag chip */
      const tx = cx + cw - 28;
      const ty = cy + ch / 2 - 5;
      this.roundRect(ctx, tx, ty, 16, 10, 5);
      ctx.fillStyle = `hsla(${card.tagHue}, 55%, 55%, 0.35)`;
      ctx.fill();
    }

    /* Share / agent satellites */
    const sats = [
      {x: width * 0.88, y: height * 0.48, label: true},
      {x: width * 0.7, y: height * 0.12, label: false},
      {x: width * 0.92, y: height * 0.22, label: false},
    ];
    for (const s of sats) {
      ctx.beginPath();
      ctx.moveTo(cloudX + 18, cloudY + 8);
      ctx.lineTo(s.x, s.y);
      ctx.strokeStyle = this.withAlpha(info, 0.1);
      ctx.lineWidth = 0.6;
      ctx.stroke();
      ctx.beginPath();
      ctx.arc(s.x, s.y, 4.5, 0, Math.PI * 2);
      ctx.fillStyle = this.withAlpha(info, 0.35);
      ctx.fill();
    }

    /* Soft vignette so copy stays readable on the left */
    const veil = ctx.createLinearGradient(0, 0, width * 0.65, 0);
    veil.addColorStop(0, this.withAlpha(base, 0.9));
    veil.addColorStop(0.45, this.withAlpha(base, 0.55));
    veil.addColorStop(0.75, this.withAlpha(base, 0.18));
    veil.addColorStop(1, this.withAlpha(base, 0));
    ctx.fillStyle = veil;
    ctx.fillRect(0, 0, width, height);
  }

  private drawDevice(
    ctx: CanvasRenderingContext2D,
    x: number,
    y: number,
    primary: string,
    muted: string,
    content: string,
  ) {
    /* Shell: fill + hairline — midground anchor */
    this.roundRect(ctx, x - 48, y - 70, 96, 128, 14);
    ctx.fillStyle = this.withAlpha(muted, 0.28);
    ctx.fill();
    ctx.strokeStyle = this.withAlpha(primary, 0.22);
    ctx.lineWidth = 0.75;
    ctx.stroke();
    /* Screen: fill only (inner depth, no outline) */
    this.roundRect(ctx, x - 38, y - 58, 76, 96, 8);
    ctx.fillStyle = this.withAlpha(content, 0.05);
    ctx.fill();
    ctx.beginPath();
    ctx.arc(x, y + 48, 3.5, 0, Math.PI * 2);
    ctx.fillStyle = this.withAlpha(primary, 0.35);
    ctx.fill();
  }

  private drawCloud(
    ctx: CanvasRenderingContext2D,
    x: number,
    y: number,
    info: string,
    _primary: string,
    time: number,
  ) {
    const pulse = this.reducedMotion ? 1 : 1 + Math.sin(time * 1.4) * 0.04;
    ctx.save();
    ctx.translate(x, y);
    ctx.scale(pulse, pulse);
    /* Soft mass, no stroke — sits behind arcs/particles */
    ctx.beginPath();
    ctx.arc(-22, 6, 18, 0, Math.PI * 2);
    ctx.arc(0, 0, 26, 0, Math.PI * 2);
    ctx.arc(24, 8, 16, 0, Math.PI * 2);
    ctx.arc(8, 14, 14, 0, Math.PI * 2);
    ctx.fillStyle = this.withAlpha(info, 0.22);
    ctx.fill();
    ctx.restore();
  }

  private roundRect(
    ctx: CanvasRenderingContext2D,
    x: number,
    y: number,
    w: number,
    h: number,
    r: number,
  ) {
    const rr = Math.min(r, w / 2, h / 2);
    ctx.beginPath();
    ctx.moveTo(x + rr, y);
    ctx.arcTo(x + w, y, x + w, y + h, rr);
    ctx.arcTo(x + w, y + h, x, y + h, rr);
    ctx.arcTo(x, y + h, x, y, rr);
    ctx.arcTo(x, y, x + w, y, rr);
    ctx.closePath();
  }

  render() {
    return html`<canvas aria-hidden="true"></canvas>`;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "landing-flow-canvas": LandingFlowCanvas;
  }
}
