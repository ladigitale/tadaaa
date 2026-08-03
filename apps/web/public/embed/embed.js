/**
 * @license
 * Copyright 2019 Google LLC
 * SPDX-License-Identifier: BSD-3-Clause
 */
const L = globalThis, G = L.ShadowRoot && (L.ShadyCSS === void 0 || L.ShadyCSS.nativeShadow) && "adoptedStyleSheets" in Document.prototype && "replace" in CSSStyleSheet.prototype, Q = Symbol(), st = /* @__PURE__ */ new WeakMap();
let pt = class {
  constructor(t, e, i) {
    if (this._$cssResult$ = !0, i !== Q) throw Error("CSSResult is not constructable. Use `unsafeCSS` or `css` instead.");
    this.cssText = t, this.t = e;
  }
  get styleSheet() {
    let t = this.o;
    const e = this.t;
    if (G && t === void 0) {
      const i = e !== void 0 && e.length === 1;
      i && (t = st.get(e)), t === void 0 && ((this.o = t = new CSSStyleSheet()).replaceSync(this.cssText), i && st.set(e, t));
    }
    return t;
  }
  toString() {
    return this.cssText;
  }
};
const vt = (s) => new pt(typeof s == "string" ? s : s + "", void 0, Q), P = (s, ...t) => {
  const e = s.length === 1 ? s[0] : t.reduce((i, r, a) => i + ((n) => {
    if (n._$cssResult$ === !0) return n.cssText;
    if (typeof n == "number") return n;
    throw Error("Value passed to 'css' function must be a 'css' function result: " + n + ". Use 'unsafeCSS' to pass non-literal values, but take care to ensure page security.");
  })(r) + s[a + 1], s[0]);
  return new pt(e, s, Q);
}, _t = (s, t) => {
  if (G) s.adoptedStyleSheets = t.map((e) => e instanceof CSSStyleSheet ? e : e.styleSheet);
  else for (const e of t) {
    const i = document.createElement("style"), r = L.litNonce;
    r !== void 0 && i.setAttribute("nonce", r), i.textContent = e.cssText, s.appendChild(i);
  }
}, it = G ? (s) => s : (s) => s instanceof CSSStyleSheet ? ((t) => {
  let e = "";
  for (const i of t.cssRules) e += i.cssText;
  return vt(e);
})(s) : s;
/**
 * @license
 * Copyright 2017 Google LLC
 * SPDX-License-Identifier: BSD-3-Clause
 */
const { is: bt, defineProperty: At, getOwnPropertyDescriptor: Et, getOwnPropertyNames: wt, getOwnPropertySymbols: St, getPrototypeOf: xt } = Object, V = globalThis, rt = V.trustedTypes, Ct = rt ? rt.emptyScript : "", Pt = V.reactiveElementPolyfillSupport, k = (s, t) => s, z = { toAttribute(s, t) {
  switch (t) {
    case Boolean:
      s = s ? Ct : null;
      break;
    case Object:
    case Array:
      s = s == null ? s : JSON.stringify(s);
  }
  return s;
}, fromAttribute(s, t) {
  let e = s;
  switch (t) {
    case Boolean:
      e = s !== null;
      break;
    case Number:
      e = s === null ? null : Number(s);
      break;
    case Object:
    case Array:
      try {
        e = JSON.parse(s);
      } catch {
        e = null;
      }
  }
  return e;
} }, X = (s, t) => !bt(s, t), at = { attribute: !0, type: String, converter: z, reflect: !1, useDefault: !1, hasChanged: X };
Symbol.metadata ??= Symbol("metadata"), V.litPropertyMetadata ??= /* @__PURE__ */ new WeakMap();
let w = class extends HTMLElement {
  static addInitializer(t) {
    this._$Ei(), (this.l ??= []).push(t);
  }
  static get observedAttributes() {
    return this.finalize(), this._$Eh && [...this._$Eh.keys()];
  }
  static createProperty(t, e = at) {
    if (e.state && (e.attribute = !1), this._$Ei(), this.prototype.hasOwnProperty(t) && ((e = Object.create(e)).wrapped = !0), this.elementProperties.set(t, e), !e.noAccessor) {
      const i = Symbol(), r = this.getPropertyDescriptor(t, i, e);
      r !== void 0 && At(this.prototype, t, r);
    }
  }
  static getPropertyDescriptor(t, e, i) {
    const { get: r, set: a } = Et(this.prototype, t) ?? { get() {
      return this[e];
    }, set(n) {
      this[e] = n;
    } };
    return { get: r, set(n) {
      const l = r?.call(this);
      a?.call(this, n), this.requestUpdate(t, l, i);
    }, configurable: !0, enumerable: !0 };
  }
  static getPropertyOptions(t) {
    return this.elementProperties.get(t) ?? at;
  }
  static _$Ei() {
    if (this.hasOwnProperty(k("elementProperties"))) return;
    const t = xt(this);
    t.finalize(), t.l !== void 0 && (this.l = [...t.l]), this.elementProperties = new Map(t.elementProperties);
  }
  static finalize() {
    if (this.hasOwnProperty(k("finalized"))) return;
    if (this.finalized = !0, this._$Ei(), this.hasOwnProperty(k("properties"))) {
      const e = this.properties, i = [...wt(e), ...St(e)];
      for (const r of i) this.createProperty(r, e[r]);
    }
    const t = this[Symbol.metadata];
    if (t !== null) {
      const e = litPropertyMetadata.get(t);
      if (e !== void 0) for (const [i, r] of e) this.elementProperties.set(i, r);
    }
    this._$Eh = /* @__PURE__ */ new Map();
    for (const [e, i] of this.elementProperties) {
      const r = this._$Eu(e, i);
      r !== void 0 && this._$Eh.set(r, e);
    }
    this.elementStyles = this.finalizeStyles(this.styles);
  }
  static finalizeStyles(t) {
    const e = [];
    if (Array.isArray(t)) {
      const i = new Set(t.flat(1 / 0).reverse());
      for (const r of i) e.unshift(it(r));
    } else t !== void 0 && e.push(it(t));
    return e;
  }
  static _$Eu(t, e) {
    const i = e.attribute;
    return i === !1 ? void 0 : typeof i == "string" ? i : typeof t == "string" ? t.toLowerCase() : void 0;
  }
  constructor() {
    super(), this._$Ep = void 0, this.isUpdatePending = !1, this.hasUpdated = !1, this._$Em = null, this._$Ev();
  }
  _$Ev() {
    this._$ES = new Promise((t) => this.enableUpdating = t), this._$AL = /* @__PURE__ */ new Map(), this._$E_(), this.requestUpdate(), this.constructor.l?.forEach((t) => t(this));
  }
  addController(t) {
    (this._$EO ??= /* @__PURE__ */ new Set()).add(t), this.renderRoot !== void 0 && this.isConnected && t.hostConnected?.();
  }
  removeController(t) {
    this._$EO?.delete(t);
  }
  _$E_() {
    const t = /* @__PURE__ */ new Map(), e = this.constructor.elementProperties;
    for (const i of e.keys()) this.hasOwnProperty(i) && (t.set(i, this[i]), delete this[i]);
    t.size > 0 && (this._$Ep = t);
  }
  createRenderRoot() {
    const t = this.shadowRoot ?? this.attachShadow(this.constructor.shadowRootOptions);
    return _t(t, this.constructor.elementStyles), t;
  }
  connectedCallback() {
    this.renderRoot ??= this.createRenderRoot(), this.enableUpdating(!0), this._$EO?.forEach((t) => t.hostConnected?.());
  }
  enableUpdating(t) {
  }
  disconnectedCallback() {
    this._$EO?.forEach((t) => t.hostDisconnected?.());
  }
  attributeChangedCallback(t, e, i) {
    this._$AK(t, i);
  }
  _$ET(t, e) {
    const i = this.constructor.elementProperties.get(t), r = this.constructor._$Eu(t, i);
    if (r !== void 0 && i.reflect === !0) {
      const a = (i.converter?.toAttribute !== void 0 ? i.converter : z).toAttribute(e, i.type);
      this._$Em = t, a == null ? this.removeAttribute(r) : this.setAttribute(r, a), this._$Em = null;
    }
  }
  _$AK(t, e) {
    const i = this.constructor, r = i._$Eh.get(t);
    if (r !== void 0 && this._$Em !== r) {
      const a = i.getPropertyOptions(r), n = typeof a.converter == "function" ? { fromAttribute: a.converter } : a.converter?.fromAttribute !== void 0 ? a.converter : z;
      this._$Em = r;
      const l = n.fromAttribute(e, a.type);
      this[r] = l ?? this._$Ej?.get(r) ?? l, this._$Em = null;
    }
  }
  requestUpdate(t, e, i, r = !1, a) {
    if (t !== void 0) {
      const n = this.constructor;
      if (r === !1 && (a = this[t]), i ??= n.getPropertyOptions(t), !((i.hasChanged ?? X)(a, e) || i.useDefault && i.reflect && a === this._$Ej?.get(t) && !this.hasAttribute(n._$Eu(t, i)))) return;
      this.C(t, e, i);
    }
    this.isUpdatePending === !1 && (this._$ES = this._$EP());
  }
  C(t, e, { useDefault: i, reflect: r, wrapped: a }, n) {
    i && !(this._$Ej ??= /* @__PURE__ */ new Map()).has(t) && (this._$Ej.set(t, n ?? e ?? this[t]), a !== !0 || n !== void 0) || (this._$AL.has(t) || (this.hasUpdated || i || (e = void 0), this._$AL.set(t, e)), r === !0 && this._$Em !== t && (this._$Eq ??= /* @__PURE__ */ new Set()).add(t));
  }
  async _$EP() {
    this.isUpdatePending = !0;
    try {
      await this._$ES;
    } catch (e) {
      Promise.reject(e);
    }
    const t = this.scheduleUpdate();
    return t != null && await t, !this.isUpdatePending;
  }
  scheduleUpdate() {
    return this.performUpdate();
  }
  performUpdate() {
    if (!this.isUpdatePending) return;
    if (!this.hasUpdated) {
      if (this.renderRoot ??= this.createRenderRoot(), this._$Ep) {
        for (const [r, a] of this._$Ep) this[r] = a;
        this._$Ep = void 0;
      }
      const i = this.constructor.elementProperties;
      if (i.size > 0) for (const [r, a] of i) {
        const { wrapped: n } = a, l = this[r];
        n !== !0 || this._$AL.has(r) || l === void 0 || this.C(r, void 0, a, l);
      }
    }
    let t = !1;
    const e = this._$AL;
    try {
      t = this.shouldUpdate(e), t ? (this.willUpdate(e), this._$EO?.forEach((i) => i.hostUpdate?.()), this.update(e)) : this._$EM();
    } catch (i) {
      throw t = !1, this._$EM(), i;
    }
    t && this._$AE(e);
  }
  willUpdate(t) {
  }
  _$AE(t) {
    this._$EO?.forEach((e) => e.hostUpdated?.()), this.hasUpdated || (this.hasUpdated = !0, this.firstUpdated(t)), this.updated(t);
  }
  _$EM() {
    this._$AL = /* @__PURE__ */ new Map(), this.isUpdatePending = !1;
  }
  get updateComplete() {
    return this.getUpdateComplete();
  }
  getUpdateComplete() {
    return this._$ES;
  }
  shouldUpdate(t) {
    return !0;
  }
  update(t) {
    this._$Eq &&= this._$Eq.forEach((e) => this._$ET(e, this[e])), this._$EM();
  }
  updated(t) {
  }
  firstUpdated(t) {
  }
};
w.elementStyles = [], w.shadowRootOptions = { mode: "open" }, w[k("elementProperties")] = /* @__PURE__ */ new Map(), w[k("finalized")] = /* @__PURE__ */ new Map(), Pt?.({ ReactiveElement: w }), (V.reactiveElementVersions ??= []).push("2.1.2");
/**
 * @license
 * Copyright 2017 Google LLC
 * SPDX-License-Identifier: BSD-3-Clause
 */
const Y = globalThis, nt = (s) => s, j = Y.trustedTypes, ot = j ? j.createPolicy("lit-html", { createHTML: (s) => s }) : void 0, ft = "$lit$", v = `lit$${Math.random().toFixed(9).slice(2)}$`, mt = "?" + v, Tt = `<${mt}>`, E = document, O = () => E.createComment(""), U = (s) => s === null || typeof s != "object" && typeof s != "function", tt = Array.isArray, kt = (s) => tt(s) || typeof s?.[Symbol.iterator] == "function", Z = `[ 	
\f\r]`, T = /<(?:(!--|\/[^a-zA-Z])|(\/?[a-zA-Z][^>\s]*)|(\/?$))/g, lt = /-->/g, ht = />/g, b = RegExp(`>|${Z}(?:([^\\s"'>=/]+)(${Z}*=${Z}*(?:[^ 	
\f\r"'\`<>=]|("|')|))|$)`, "g"), dt = /'/g, ct = /"/g, $t = /^(?:script|style|textarea|title)$/i, Ot = (s) => (t, ...e) => ({ _$litType$: s, strings: t, values: e }), u = Ot(1), x = Symbol.for("lit-noChange"), d = Symbol.for("lit-nothing"), ut = /* @__PURE__ */ new WeakMap(), A = E.createTreeWalker(E, 129);
function gt(s, t) {
  if (!tt(s) || !s.hasOwnProperty("raw")) throw Error("invalid template strings array");
  return ot !== void 0 ? ot.createHTML(t) : t;
}
const Ut = (s, t) => {
  const e = s.length - 1, i = [];
  let r, a = t === 2 ? "<svg>" : t === 3 ? "<math>" : "", n = T;
  for (let l = 0; l < e; l++) {
    const o = s[l];
    let c, p, h = -1, g = 0;
    for (; g < o.length && (n.lastIndex = g, p = n.exec(o), p !== null); ) g = n.lastIndex, n === T ? p[1] === "!--" ? n = lt : p[1] !== void 0 ? n = ht : p[2] !== void 0 ? ($t.test(p[2]) && (r = RegExp("</" + p[2], "g")), n = b) : p[3] !== void 0 && (n = b) : n === b ? p[0] === ">" ? (n = r ?? T, h = -1) : p[1] === void 0 ? h = -2 : (h = n.lastIndex - p[2].length, c = p[1], n = p[3] === void 0 ? b : p[3] === '"' ? ct : dt) : n === ct || n === dt ? n = b : n === lt || n === ht ? n = T : (n = b, r = void 0);
    const y = n === b && s[l + 1].startsWith("/>") ? " " : "";
    a += n === T ? o + Tt : h >= 0 ? (i.push(c), o.slice(0, h) + ft + o.slice(h) + v + y) : o + v + (h === -2 ? l : y);
  }
  return [gt(s, a + (s[e] || "<?>") + (t === 2 ? "</svg>" : t === 3 ? "</math>" : "")), i];
};
class M {
  constructor({ strings: t, _$litType$: e }, i) {
    let r;
    this.parts = [];
    let a = 0, n = 0;
    const l = t.length - 1, o = this.parts, [c, p] = Ut(t, e);
    if (this.el = M.createElement(c, i), A.currentNode = this.el.content, e === 2 || e === 3) {
      const h = this.el.content.firstChild;
      h.replaceWith(...h.childNodes);
    }
    for (; (r = A.nextNode()) !== null && o.length < l; ) {
      if (r.nodeType === 1) {
        if (r.hasAttributes()) for (const h of r.getAttributeNames()) if (h.endsWith(ft)) {
          const g = p[n++], y = r.getAttribute(h).split(v), B = /([.?@])?(.*)/.exec(g);
          o.push({ type: 1, index: a, name: B[2], strings: y, ctor: B[1] === "." ? Nt : B[1] === "?" ? Ht : B[1] === "@" ? Dt : I }), r.removeAttribute(h);
        } else h.startsWith(v) && (o.push({ type: 6, index: a }), r.removeAttribute(h));
        if ($t.test(r.tagName)) {
          const h = r.textContent.split(v), g = h.length - 1;
          if (g > 0) {
            r.textContent = j ? j.emptyScript : "";
            for (let y = 0; y < g; y++) r.append(h[y], O()), A.nextNode(), o.push({ type: 2, index: ++a });
            r.append(h[g], O());
          }
        }
      } else if (r.nodeType === 8) if (r.data === mt) o.push({ type: 2, index: a });
      else {
        let h = -1;
        for (; (h = r.data.indexOf(v, h + 1)) !== -1; ) o.push({ type: 7, index: a }), h += v.length - 1;
      }
      a++;
    }
  }
  static createElement(t, e) {
    const i = E.createElement("template");
    return i.innerHTML = t, i;
  }
}
function C(s, t, e = s, i) {
  if (t === x) return t;
  let r = i !== void 0 ? e._$Co?.[i] : e._$Cl;
  const a = U(t) ? void 0 : t._$litDirective$;
  return r?.constructor !== a && (r?._$AO?.(!1), a === void 0 ? r = void 0 : (r = new a(s), r._$AT(s, e, i)), i !== void 0 ? (e._$Co ??= [])[i] = r : e._$Cl = r), r !== void 0 && (t = C(s, r._$AS(s, t.values), r, i)), t;
}
class Mt {
  constructor(t, e) {
    this._$AV = [], this._$AN = void 0, this._$AD = t, this._$AM = e;
  }
  get parentNode() {
    return this._$AM.parentNode;
  }
  get _$AU() {
    return this._$AM._$AU;
  }
  u(t) {
    const { el: { content: e }, parts: i } = this._$AD, r = (t?.creationScope ?? E).importNode(e, !0);
    A.currentNode = r;
    let a = A.nextNode(), n = 0, l = 0, o = i[0];
    for (; o !== void 0; ) {
      if (n === o.index) {
        let c;
        o.type === 2 ? c = new H(a, a.nextSibling, this, t) : o.type === 1 ? c = new o.ctor(a, o.name, o.strings, this, t) : o.type === 6 && (c = new Rt(a, this, t)), this._$AV.push(c), o = i[++l];
      }
      n !== o?.index && (a = A.nextNode(), n++);
    }
    return A.currentNode = E, r;
  }
  p(t) {
    let e = 0;
    for (const i of this._$AV) i !== void 0 && (i.strings !== void 0 ? (i._$AI(t, i, e), e += i.strings.length - 2) : i._$AI(t[e])), e++;
  }
}
class H {
  get _$AU() {
    return this._$AM?._$AU ?? this._$Cv;
  }
  constructor(t, e, i, r) {
    this.type = 2, this._$AH = d, this._$AN = void 0, this._$AA = t, this._$AB = e, this._$AM = i, this.options = r, this._$Cv = r?.isConnected ?? !0;
  }
  get parentNode() {
    let t = this._$AA.parentNode;
    const e = this._$AM;
    return e !== void 0 && t?.nodeType === 11 && (t = e.parentNode), t;
  }
  get startNode() {
    return this._$AA;
  }
  get endNode() {
    return this._$AB;
  }
  _$AI(t, e = this) {
    t = C(this, t, e), U(t) ? t === d || t == null || t === "" ? (this._$AH !== d && this._$AR(), this._$AH = d) : t !== this._$AH && t !== x && this._(t) : t._$litType$ !== void 0 ? this.$(t) : t.nodeType !== void 0 ? this.T(t) : kt(t) ? this.k(t) : this._(t);
  }
  O(t) {
    return this._$AA.parentNode.insertBefore(t, this._$AB);
  }
  T(t) {
    this._$AH !== t && (this._$AR(), this._$AH = this.O(t));
  }
  _(t) {
    this._$AH !== d && U(this._$AH) ? this._$AA.nextSibling.data = t : this.T(E.createTextNode(t)), this._$AH = t;
  }
  $(t) {
    const { values: e, _$litType$: i } = t, r = typeof i == "number" ? this._$AC(t) : (i.el === void 0 && (i.el = M.createElement(gt(i.h, i.h[0]), this.options)), i);
    if (this._$AH?._$AD === r) this._$AH.p(e);
    else {
      const a = new Mt(r, this), n = a.u(this.options);
      a.p(e), this.T(n), this._$AH = a;
    }
  }
  _$AC(t) {
    let e = ut.get(t.strings);
    return e === void 0 && ut.set(t.strings, e = new M(t)), e;
  }
  k(t) {
    tt(this._$AH) || (this._$AH = [], this._$AR());
    const e = this._$AH;
    let i, r = 0;
    for (const a of t) r === e.length ? e.push(i = new H(this.O(O()), this.O(O()), this, this.options)) : i = e[r], i._$AI(a), r++;
    r < e.length && (this._$AR(i && i._$AB.nextSibling, r), e.length = r);
  }
  _$AR(t = this._$AA.nextSibling, e) {
    for (this._$AP?.(!1, !0, e); t !== this._$AB; ) {
      const i = nt(t).nextSibling;
      nt(t).remove(), t = i;
    }
  }
  setConnected(t) {
    this._$AM === void 0 && (this._$Cv = t, this._$AP?.(t));
  }
}
class I {
  get tagName() {
    return this.element.tagName;
  }
  get _$AU() {
    return this._$AM._$AU;
  }
  constructor(t, e, i, r, a) {
    this.type = 1, this._$AH = d, this._$AN = void 0, this.element = t, this.name = e, this._$AM = r, this.options = a, i.length > 2 || i[0] !== "" || i[1] !== "" ? (this._$AH = Array(i.length - 1).fill(new String()), this.strings = i) : this._$AH = d;
  }
  _$AI(t, e = this, i, r) {
    const a = this.strings;
    let n = !1;
    if (a === void 0) t = C(this, t, e, 0), n = !U(t) || t !== this._$AH && t !== x, n && (this._$AH = t);
    else {
      const l = t;
      let o, c;
      for (t = a[0], o = 0; o < a.length - 1; o++) c = C(this, l[i + o], e, o), c === x && (c = this._$AH[o]), n ||= !U(c) || c !== this._$AH[o], c === d ? t = d : t !== d && (t += (c ?? "") + a[o + 1]), this._$AH[o] = c;
    }
    n && !r && this.j(t);
  }
  j(t) {
    t === d ? this.element.removeAttribute(this.name) : this.element.setAttribute(this.name, t ?? "");
  }
}
class Nt extends I {
  constructor() {
    super(...arguments), this.type = 3;
  }
  j(t) {
    this.element[this.name] = t === d ? void 0 : t;
  }
}
class Ht extends I {
  constructor() {
    super(...arguments), this.type = 4;
  }
  j(t) {
    this.element.toggleAttribute(this.name, !!t && t !== d);
  }
}
class Dt extends I {
  constructor(t, e, i, r, a) {
    super(t, e, i, r, a), this.type = 5;
  }
  _$AI(t, e = this) {
    if ((t = C(this, t, e, 0) ?? d) === x) return;
    const i = this._$AH, r = t === d && i !== d || t.capture !== i.capture || t.once !== i.once || t.passive !== i.passive, a = t !== d && (i === d || r);
    r && this.element.removeEventListener(this.name, this, i), a && this.element.addEventListener(this.name, this, t), this._$AH = t;
  }
  handleEvent(t) {
    typeof this._$AH == "function" ? this._$AH.call(this.options?.host ?? this.element, t) : this._$AH.handleEvent(t);
  }
}
class Rt {
  constructor(t, e, i) {
    this.element = t, this.type = 6, this._$AN = void 0, this._$AM = e, this.options = i;
  }
  get _$AU() {
    return this._$AM._$AU;
  }
  _$AI(t) {
    C(this, t);
  }
}
const Bt = Y.litHtmlPolyfillSupport;
Bt?.(M, H), (Y.litHtmlVersions ??= []).push("3.3.3");
const Lt = (s, t, e) => {
  const i = e?.renderBefore ?? t;
  let r = i._$litPart$;
  if (r === void 0) {
    const a = e?.renderBefore ?? null;
    i._$litPart$ = r = new H(t.insertBefore(O(), a), a, void 0, e ?? {});
  }
  return r._$AI(s), r;
};
/**
 * @license
 * Copyright 2017 Google LLC
 * SPDX-License-Identifier: BSD-3-Clause
 */
const et = globalThis;
class S extends w {
  constructor() {
    super(...arguments), this.renderOptions = { host: this }, this._$Do = void 0;
  }
  createRenderRoot() {
    const t = super.createRenderRoot();
    return this.renderOptions.renderBefore ??= t.firstChild, t;
  }
  update(t) {
    const e = this.render();
    this.hasUpdated || (this.renderOptions.isConnected = this.isConnected), super.update(t), this._$Do = Lt(e, this.renderRoot, this.renderOptions);
  }
  connectedCallback() {
    super.connectedCallback(), this._$Do?.setConnected(!0);
  }
  disconnectedCallback() {
    super.disconnectedCallback(), this._$Do?.setConnected(!1);
  }
  render() {
    return x;
  }
}
S._$litElement$ = !0, S.finalized = !0, et.litElementHydrateSupport?.({ LitElement: S });
const zt = et.litElementPolyfillSupport;
zt?.({ LitElement: S });
(et.litElementVersions ??= []).push("4.2.2");
/**
 * @license
 * Copyright 2017 Google LLC
 * SPDX-License-Identifier: BSD-3-Clause
 */
const D = (s) => (t, e) => {
  e !== void 0 ? e.addInitializer(() => {
    customElements.define(s, t);
  }) : customElements.define(s, t);
};
/**
 * @license
 * Copyright 2017 Google LLC
 * SPDX-License-Identifier: BSD-3-Clause
 */
const jt = { attribute: !0, type: String, converter: z, reflect: !1, hasChanged: X }, qt = (s = jt, t, e) => {
  const { kind: i, metadata: r } = e;
  let a = globalThis.litPropertyMetadata.get(r);
  if (a === void 0 && globalThis.litPropertyMetadata.set(r, a = /* @__PURE__ */ new Map()), i === "setter" && ((s = Object.create(s)).wrapped = !0), a.set(e.name, s), i === "accessor") {
    const { name: n } = e;
    return { set(l) {
      const o = t.get.call(this);
      t.set.call(this, l), this.requestUpdate(n, o, s, !0, l);
    }, init(l) {
      return l !== void 0 && this.C(n, void 0, s, l), l;
    } };
  }
  if (i === "setter") {
    const { name: n } = e;
    return function(l) {
      const o = this[n];
      t.call(this, l), this.requestUpdate(n, o, s, !0, l);
    };
  }
  throw Error("Unsupported decorator location: " + i);
};
function m(s) {
  return (t, e) => typeof e == "object" ? qt(s, t, e) : ((i, r, a) => {
    const n = r.hasOwnProperty(a);
    return r.constructor.createProperty(a, i), n ? Object.getOwnPropertyDescriptor(r, a) : void 0;
  })(s, t, e);
}
/**
 * @license
 * Copyright 2017 Google LLC
 * SPDX-License-Identifier: BSD-3-Clause
 */
function F(s) {
  return m({ ...s, state: !0, attribute: !1 });
}
const Vt = {
  theme: "auto",
  accent: "#0d9488",
  font: "system-ui, sans-serif",
  radius: "12px",
  density: "comfortable"
}, It = {
  q: "",
  status: "active",
  tags: []
};
function Ft(s) {
  let t = {
    key: s?.key ?? "",
    apiBase: s?.apiBase ?? "",
    loading: s?.loading ?? !1,
    error: s?.error ?? null,
    feed: s?.feed ?? null,
    filter: { ...It },
    theme: { ...Vt }
  };
  const e = /* @__PURE__ */ new Set(), i = () => {
    for (const r of e) r();
  };
  return {
    get() {
      return t;
    },
    set(r) {
      t = {
        ...t,
        ...r,
        filter: r.filter ? { ...t.filter, ...r.filter } : t.filter,
        theme: r.theme ? { ...t.theme, ...r.theme } : t.theme
      }, i();
    },
    subscribe(r) {
      return e.add(r), () => e.delete(r);
    }
  };
}
function yt(s, t) {
  if (!s) return [];
  const e = t.q.trim().toLowerCase();
  return s.todos.filter((i) => !(t.status === "active" && i.done || t.status === "done" && !i.done || t.tags.length > 0 && !t.tags.some((r) => i.tagIds.includes(r)) || e && !i.text.toLowerCase().includes(e)));
}
function Wt(s) {
  if (s) return s.replace(/\/$/, "");
  const t = document.currentScript?.getAttribute("data-api");
  return t ? t.replace(/\/$/, "") : "";
}
var Zt = Object.defineProperty, Kt = Object.getOwnPropertyDescriptor, $ = (s, t, e, i) => {
  for (var r = i > 1 ? void 0 : i ? Kt(t, e) : t, a = s.length - 1, n; a >= 0; a--)
    (n = s[a]) && (r = (i ? n(t, e, r) : n(r)) || r);
  return i && r && Zt(t, e, r), r;
};
let f = class extends S {
  constructor() {
    super(...arguments), this.key = "", this.apiBase = "", this.theme = "auto", this.accent = "#0d9488", this.font = "system-ui, sans-serif", this.radius = "12px", this.density = "comfortable", this.view = "", this.poll = 60, this.locale = "", this.loading = !1, this.error = null, this.feed = null, this.store = Ft(), this.pollTimer = null, this.unsub = null, this.mq = null, this.autoViewEl = null, this.onSchemeChange = () => {
      this.theme === "auto" && this.applyTheme(this.store.get().theme);
    };
  }
  connectedCallback() {
    super.connectedCallback(), this.unsub = this.store.subscribe(() => {
      const s = this.store.get();
      this.loading = s.loading, this.error = s.error, this.feed = s.feed, this.applyTheme(s.theme);
    }), this.syncStoreFromProps(), this.ensureDefaultView(), this.refresh(), this.startPoll(), this.mq = window.matchMedia("(prefers-color-scheme: dark)"), this.mq.addEventListener("change", this.onSchemeChange);
  }
  disconnectedCallback() {
    super.disconnectedCallback(), this.unsub?.(), this.unsub = null, this.stopPoll(), this.mq?.removeEventListener("change", this.onSchemeChange), this.autoViewEl?.remove(), this.autoViewEl = null;
  }
  updated(s) {
    (s.has("key") || s.has("apiBase") || s.has("theme") || s.has("accent") || s.has("font") || s.has("radius") || s.has("density")) && this.syncStoreFromProps(), (s.has("key") || s.has("apiBase")) && this.refresh(), s.has("poll") && this.startPoll(), s.has("view") && this.ensureDefaultView(!0);
  }
  /** Mount default view in light DOM so widgets can `closest('tadaaa-embed')`. */
  ensureDefaultView(s = !1) {
    if ([...this.children].some(
      (a) => a !== this.autoViewEl && a.tagName.startsWith("TADAAA-") && a.tagName !== "TADAAA-EMBED"
    )) {
      this.autoViewEl?.remove(), this.autoViewEl = null;
      return;
    }
    const e = (this.view.trim() || "list").toLowerCase(), i = e === "kpi" || e === "agenda" || e === "list" ? `tadaaa-${e}` : "tadaaa-list";
    if (!s && this.autoViewEl?.tagName === i.toUpperCase()) return;
    this.autoViewEl?.remove();
    const r = document.createElement(i);
    r.setAttribute("data-tadaaa-auto", ""), this.appendChild(r), this.autoViewEl = r;
  }
  syncStoreFromProps() {
    this.store.set({
      key: this.key.trim(),
      apiBase: Wt(this.apiBase),
      theme: {
        theme: this.theme,
        accent: this.accent,
        font: this.font,
        radius: this.radius,
        density: this.density
      }
    });
  }
  applyTheme(s) {
    const t = s.theme === "auto" ? window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light" : s.theme;
    this.setAttribute("data-theme", t), this.style.setProperty("--tadaaa-accent", s.accent), this.style.setProperty("--tadaaa-font", s.font), this.style.setProperty("--tadaaa-radius", s.radius), this.style.setProperty(
      "--tadaaa-gap",
      s.density === "compact" ? "0.5rem" : "0.75rem"
    ), this.style.setProperty(
      "--tadaaa-font-size",
      s.density === "compact" ? "13px" : "15px"
    );
  }
  startPoll() {
    this.stopPoll(), !(this.poll <= 0) && (this.pollTimer = window.setInterval(() => {
      document.visibilityState === "visible" && this.refresh();
    }, this.poll * 1e3));
  }
  stopPoll() {
    this.pollTimer !== null && (clearInterval(this.pollTimer), this.pollTimer = null);
  }
  async refresh() {
    const { key: s, apiBase: t } = this.store.get();
    if (!s || !t) {
      this.store.set({
        error: s ? "Missing api-base" : "Missing embed key",
        loading: !1
      });
      return;
    }
    this.store.set({ loading: !0, error: null });
    try {
      const e = await fetch(`${t}/api/public/embeds/${encodeURIComponent(s)}`, {
        credentials: "omit"
      });
      if (!e.ok)
        throw new Error(`HTTP ${e.status}`);
      const i = await e.json();
      this.store.set({ feed: i, loading: !1, error: null });
    } catch (e) {
      this.store.set({
        loading: !1,
        error: e instanceof Error ? e.message : "Load failed"
      });
    }
  }
  render() {
    return u`
      <div class="shell">
        ${this.error ? u`<div class="error">${this.error}</div>` : d}
        ${this.loading && !this.feed ? u`<div class="status">Loading…</div>` : d}
        <slot></slot>
      </div>
    `;
  }
};
f.styles = P`
    :host {
      display: block;
      color: var(--tadaaa-fg, #0f172a);
      background: var(--tadaaa-bg, transparent);
      font-family: var(--tadaaa-font, system-ui, sans-serif);
      font-size: var(--tadaaa-font-size, 15px);
      line-height: 1.45;
      --tadaaa-accent: #0d9488;
      --tadaaa-muted: #64748b;
      --tadaaa-border: color-mix(in srgb, var(--tadaaa-fg) 12%, transparent);
      --tadaaa-radius: 12px;
      --tadaaa-gap: 0.75rem;
    }
    :host([data-theme="dark"]) {
      color-scheme: dark;
      --tadaaa-fg: #e2e8f0;
      --tadaaa-bg: #0f172a;
      --tadaaa-muted: #94a3b8;
    }
    :host([data-theme="light"]) {
      color-scheme: light;
      --tadaaa-fg: #0f172a;
      --tadaaa-bg: #ffffff;
      --tadaaa-muted: #64748b;
    }
    .shell {
      display: flex;
      flex-direction: column;
      gap: var(--tadaaa-gap);
    }
    .status {
      color: var(--tadaaa-muted);
      font-size: 0.9em;
    }
    .error {
      color: #b91c1c;
      font-size: 0.9em;
    }
  `;
$([
  m({ type: String })
], f.prototype, "key", 2);
$([
  m({ attribute: "api-base", type: String })
], f.prototype, "apiBase", 2);
$([
  m({ type: String })
], f.prototype, "theme", 2);
$([
  m({ type: String })
], f.prototype, "accent", 2);
$([
  m({ type: String })
], f.prototype, "font", 2);
$([
  m({ type: String })
], f.prototype, "radius", 2);
$([
  m({ type: String })
], f.prototype, "density", 2);
$([
  m({ type: String })
], f.prototype, "view", 2);
$([
  m({ type: Number })
], f.prototype, "poll", 2);
$([
  m({ type: String })
], f.prototype, "locale", 2);
$([
  F()
], f.prototype, "loading", 2);
$([
  F()
], f.prototype, "error", 2);
$([
  F()
], f.prototype, "feed", 2);
f = $([
  D("tadaaa-embed")
], f);
function Jt(s) {
  return s.closest("tadaaa-embed");
}
var Gt = Object.defineProperty, Qt = Object.getOwnPropertyDescriptor, _ = (s, t, e, i) => {
  for (var r = i > 1 ? void 0 : i ? Qt(t, e) : t, a = s.length - 1, n; a >= 0; a--)
    (n = s[a]) && (r = (i ? n(t, e, r) : n(r)) || r);
  return i && r && Gt(t, e, r), r;
};
class R extends S {
  constructor() {
    super(...arguments), this.snap = null, this.hostEl = null, this.unsub = null;
  }
  connectedCallback() {
    super.connectedCallback(), this.bindHost();
  }
  disconnectedCallback() {
    super.disconnectedCallback(), this.unsub?.(), this.unsub = null, this.hostEl = null;
  }
  bindHost() {
    if (this.unsub?.(), this.hostEl = Jt(this), !this.hostEl) {
      this.snap = null;
      return;
    }
    this.snap = this.hostEl.store.get(), this.unsub = this.hostEl.store.subscribe(() => {
      this.snap = this.hostEl?.store.get() ?? null;
    });
  }
  setFilter(t) {
    this.hostEl && this.hostEl.store.set({
      filter: { ...this.hostEl.store.get().filter, ...t }
    });
  }
}
_([
  F()
], R.prototype, "snap", 2);
const W = P`
  :host {
    display: block;
  }
  .muted {
    color: var(--tadaaa-muted, #64748b);
  }
  .card {
    border: 1px solid var(--tadaaa-border);
    border-radius: var(--tadaaa-radius, 12px);
    padding: var(--tadaaa-gap, 0.75rem);
  }
`;
let N = class extends R {
  constructor() {
    super(...arguments), this.tags = "", this.status = "";
  }
  connectedCallback() {
    super.connectedCallback(), this.applyAttrs();
  }
  updated(s) {
    (s.has("tags") || s.has("status")) && this.applyAttrs();
  }
  applyAttrs() {
    const s = {};
    this.tags.trim() && (s.tags = this.tags.split(",").map((t) => t.trim()).filter(Boolean)), this.status && (s.status = this.status), Object.keys(s).length && this.setFilter(s);
  }
  toggleTag(s) {
    const t = this.snap?.filter.tags ?? [], e = t.includes(s) ? t.filter((i) => i !== s) : [...t, s];
    this.setFilter({ tags: e });
  }
  render() {
    const s = this.snap?.feed?.tags ?? [], t = this.snap?.filter;
    return t ? u`
      <div class="row card">
        <input
          type="search"
          placeholder="Search"
          .value=${t.q}
          @input=${(e) => this.setFilter({ q: e.target.value })}
        />
        <select
          .value=${t.status}
          @change=${(e) => this.setFilter({
      status: e.target.value
    })}
        >
          <option value="active">Active</option>
          <option value="done">Done</option>
          <option value="all">All</option>
        </select>
        ${s.map(
      (e) => u`
            <button
              type="button"
              aria-pressed=${t.tags.includes(e.id) ? "true" : "false"}
              @click=${() => this.toggleTag(e.id)}
            >
              ${e.name}
            </button>
          `
    )}
      </div>
    ` : u`<div class="muted">…</div>`;
  }
};
N.styles = [
  W,
  P`
      .row {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
        align-items: center;
      }
      input,
      select {
        font: inherit;
        color: inherit;
        background: transparent;
        border: 1px solid var(--tadaaa-border);
        border-radius: calc(var(--tadaaa-radius, 12px) * 0.6);
        padding: 0.35rem 0.55rem;
      }
      button {
        font: inherit;
        cursor: pointer;
        border: 1px solid var(--tadaaa-border);
        background: transparent;
        color: inherit;
        border-radius: 999px;
        padding: 0.25rem 0.65rem;
      }
      button[aria-pressed="true"] {
        background: var(--tadaaa-accent);
        border-color: var(--tadaaa-accent);
        color: #fff;
      }
    `
];
_([
  m({ type: String })
], N.prototype, "tags", 2);
_([
  m({ type: String })
], N.prototype, "status", 2);
N = _([
  D("tadaaa-filter")
], N);
let K = class extends R {
  render() {
    const s = yt(this.snap?.feed ?? null, this.snap?.filter ?? {
      q: "",
      status: "active",
      tags: []
    });
    return this.snap?.feed ? s.length === 0 ? u`<div class="muted">No tasks</div>` : u`
      <ul>
        ${s.map(
      (t) => u`
            <li>
              <span class="dot" data-done=${t.done ? "true" : "false"}></span>
              <div>
                <div class="text" data-done=${t.done ? "true" : "false"}>
                  ${t.text}
                </div>
                ${t.startAt || t.endAt ? u`<div class="meta">
                      ${t.startAt ?? ""}${t.endAt && t.endAt !== t.startAt ? ` → ${t.endAt}` : ""}
                    </div>` : d}
              </div>
            </li>
          `
    )}
      </ul>
    ` : u`<div class="muted">…</div>`;
  }
};
K.styles = [
  W,
  P`
      ul {
        list-style: none;
        margin: 0;
        padding: 0;
        display: flex;
        flex-direction: column;
        gap: 0.35rem;
      }
      li {
        display: flex;
        gap: 0.55rem;
        align-items: flex-start;
        padding: 0.45rem 0.55rem;
        border-radius: calc(var(--tadaaa-radius, 12px) * 0.7);
        border: 1px solid var(--tadaaa-border);
      }
      .dot {
        width: 0.65rem;
        height: 0.65rem;
        border-radius: 50%;
        margin-top: 0.35rem;
        background: var(--tadaaa-accent);
        flex: 0 0 auto;
      }
      .dot[data-done="true"] {
        opacity: 0.35;
      }
      .text[data-done="true"] {
        text-decoration: line-through;
        color: var(--tadaaa-muted);
      }
      .meta {
        font-size: 0.8em;
        color: var(--tadaaa-muted);
      }
    `
];
K = _([
  D("tadaaa-list")
], K);
let J = class extends R {
  render() {
    const s = this.snap?.feed?.stats;
    if (!s) return u`<div class="muted">…</div>`;
    const t = [
      { n: s.open, l: "Open" },
      { n: s.done, l: "Done" },
      { n: s.overdue, l: "Overdue" },
      { n: s.dated, l: "Dated" }
    ];
    return u`
      <div class="grid">
        ${t.map(
      (e) => u`<div class="cell"><div class="n">${e.n}</div><div class="l">${e.l}</div></div>`
    )}
      </div>
    `;
  }
};
J.styles = [
  W,
  P`
      .grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(6.5rem, 1fr));
        gap: 0.5rem;
      }
      .cell {
        border: 1px solid var(--tadaaa-border);
        border-radius: var(--tadaaa-radius, 12px);
        padding: 0.65rem 0.75rem;
      }
      .n {
        font-size: 1.4em;
        font-weight: 700;
        color: var(--tadaaa-accent);
      }
      .l {
        font-size: 0.8em;
        color: var(--tadaaa-muted);
      }
    `
];
J = _([
  D("tadaaa-kpi")
], J);
let q = class extends R {
  constructor() {
    super(...arguments), this.days = 14;
  }
  render() {
    const s = this.snap?.filter ?? { q: "", status: "active", tags: [] }, t = yt(this.snap?.feed ?? null, s).filter((r) => r.startAt || r.endAt).sort((r, a) => (r.startAt ?? r.endAt ?? "").localeCompare(a.startAt ?? a.endAt ?? "")), e = Date.now() + this.days * 864e5, i = t.filter((r) => {
      const a = r.startAt ?? r.endAt;
      if (!a) return !1;
      const n = Date.parse(a.length === 10 ? `${a}T00:00:00Z` : a);
      return !Number.isNaN(n) && n <= e;
    });
    return this.snap?.feed ? i.length === 0 ? u`<div class="muted">No upcoming tasks</div>` : u`
      <div class="card">
        ${i.map(
      (r) => u`
            <div class="item">
              <div class="when">${r.startAt ?? r.endAt}</div>
              <div class="title">${r.text}</div>
            </div>
          `
    )}
      </div>
    ` : u`<div class="muted">…</div>`;
  }
};
q.styles = [
  W,
  P`
      .item {
        display: grid;
        grid-template-columns: 6.5rem 1fr;
        gap: 0.75rem;
        padding: 0.55rem 0;
        border-bottom: 1px solid var(--tadaaa-border);
      }
      .when {
        font-size: 0.85em;
        color: var(--tadaaa-muted);
      }
      .title {
        font-weight: 560;
      }
    `
];
_([
  m({ type: Number })
], q.prototype, "days", 2);
q = _([
  D("tadaaa-agenda")
], q);
function te(s) {
  const t = typeof s.target == "string" ? document.querySelector(s.target) : s.target;
  if (!t)
    throw new Error("Tadaaa embed target not found");
  const e = document.createElement("tadaaa-embed");
  e.key = s.key, e.apiBase = s.apiBase, s.theme?.theme && (e.theme = s.theme.theme), s.theme?.accent && (e.accent = s.theme.accent), s.theme?.font && (e.font = s.theme.font), s.theme?.radius && (e.radius = s.theme.radius), s.theme?.density && (e.density = s.theme.density), s.poll !== void 0 && (e.poll = s.poll), s.view && (e.view = s.view);
  const i = s.widgets ?? (s.view ? [] : ["list"]);
  for (const r of i)
    e.appendChild(document.createElement(`tadaaa-${r}`));
  return t.replaceChildren(e), s.filter && queueMicrotask(() => {
    e.store.set({ filter: { ...e.store.get().filter, ...s.filter } });
  }), e;
}
export {
  f as TadaaaEmbed,
  te as create
};
