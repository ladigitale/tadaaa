/** Tiny SVG helpers for usage charts (no chart library). */

export function clamp(n: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, n));
}

export function niceMax(n: number): number {
  if (n <= 0) return 1;
  const exp = Math.floor(Math.log10(n));
  const f = n / 10 ** exp;
  const nice = f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10;
  return nice * 10 ** exp;
}

export function sparkPath(
  values: number[],
  width: number,
  height: number,
  pad = 2,
): string {
  if (values.length === 0) return "";
  const max = Math.max(...values, 1);
  const step = values.length > 1 ? (width - pad * 2) / (values.length - 1) : 0;
  return values
    .map((v, i) => {
      const x = pad + i * step;
      const y = height - pad - (v / max) * (height - pad * 2);
      return `${i === 0 ? "M" : "L"}${x.toFixed(1)},${y.toFixed(1)}`;
    })
    .join(" ");
}

export function areaPath(
  values: number[],
  width: number,
  height: number,
  max: number,
  padX = 0,
  padY = 4,
): {line: string; area: string} {
  if (values.length === 0) return {line: "", area: ""};
  const m = max > 0 ? max : 1;
  const step = values.length > 1 ? (width - padX * 2) / (values.length - 1) : 0;
  const pts = values.map((v, i) => {
    const x = padX + i * step;
    const y = height - padY - (v / m) * (height - padY * 2);
    return {x, y};
  });
  const line = pts
    .map((p, i) => `${i === 0 ? "M" : "L"}${p.x.toFixed(1)},${p.y.toFixed(1)}`)
    .join(" ");
  const first = pts[0]!;
  const last = pts[pts.length - 1]!;
  const area = `${line} L${last.x.toFixed(1)},${(height - padY).toFixed(1)} L${first.x.toFixed(1)},${(height - padY).toFixed(1)} Z`;
  return {line, area};
}

export function polar(cx: number, cy: number, r: number, angleDeg: number) {
  const a = ((angleDeg - 90) * Math.PI) / 180;
  return {x: cx + r * Math.cos(a), y: cy + r * Math.sin(a)};
}

/** Donut / arc sector path from startAngle to endAngle (degrees, 0 = top). */
export function arcPath(
  cx: number,
  cy: number,
  rOuter: number,
  rInner: number,
  startDeg: number,
  endDeg: number,
): string {
  const sweep = endDeg - startDeg;
  if (Math.abs(sweep) < 0.01) return "";
  const large = Math.abs(sweep) > 180 ? 1 : 0;
  const sweepFlag = sweep >= 0 ? 1 : 0;
  const so = polar(cx, cy, rOuter, startDeg);
  const eo = polar(cx, cy, rOuter, endDeg);
  const si = polar(cx, cy, rInner, endDeg);
  const ei = polar(cx, cy, rInner, startDeg);
  return [
    `M${so.x},${so.y}`,
    `A${rOuter},${rOuter} 0 ${large} ${sweepFlag} ${eo.x},${eo.y}`,
    `L${si.x},${si.y}`,
    `A${rInner},${rInner} 0 ${large} ${sweepFlag === 1 ? 0 : 1} ${ei.x},${ei.y}`,
    "Z",
  ].join(" ");
}

export function ringArc(
  cx: number,
  cy: number,
  r: number,
  thickness: number,
  ratio: number,
  startDeg = -90,
): {track: string; value: string} {
  const rOuter = r;
  const rInner = r - thickness;
  const clamped = clamp(ratio, 0, 1);
  const end = startDeg + 360 * clamped;
  return {
    track: arcPath(cx, cy, rOuter, rInner, startDeg, startDeg + 359.9),
    value:
      clamped <= 0
        ? ""
        : arcPath(cx, cy, rOuter, rInner, startDeg, Math.min(end, startDeg + 359.9)),
  };
}
