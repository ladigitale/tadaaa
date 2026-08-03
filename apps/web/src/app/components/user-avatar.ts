import "@supersoniks/concorde/tooltip";
import {css, html, LitElement, nothing} from "lit";
import {customElement, property, state} from "lit/decorators.js";
import {
  emailAvatarColor,
  emailInitials,
  gravatarUrl,
} from "../utils/avatar";
import tailwind from "../../css/tailwind";

/**
 * Avatar utilisateur : Gravatar si dispo, sinon pastille d’initiales.
 */
@customElement("user-avatar")
export class UserAvatar extends LitElement {
  static styles = [
    tailwind,
    css`
      :host {
        display: inline-flex;
        flex-shrink: 0;
        line-height: 0;
      }

      .avatar {
        display: grid;
        place-items: center;
        overflow: hidden;
        border-radius: 9999px;
        font-weight: 600;
        color: #fff;
        user-select: none;
        background: var(--avatar-bg, hsl(210 20% 45%));
      }

      .avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
        display: block;
      }
    `,
  ];

  @property()
  email = "";

  /** Taille CSS en pixels. */
  @property({type: Number})
  size = 32;

  @state()
  private gravatarSrc: string | null = null;

  @state()
  private gravatarFailed = false;

  private resolveToken = 0;

  protected updated(changed: Map<string, unknown>): void {
    if (changed.has("email") || changed.has("size")) {
      void this.resolveGravatar();
    }
  }

  private async resolveGravatar() {
    const email = this.email.trim();
    const token = ++this.resolveToken;
    this.gravatarSrc = null;
    this.gravatarFailed = false;
    if (!email || !globalThis.crypto?.subtle) {
      this.gravatarFailed = true;
      return;
    }
    try {
      const src = await gravatarUrl(email, Math.max(this.size * 2, 80));
      if (token !== this.resolveToken) return;
      this.gravatarSrc = src;
    } catch {
      if (token !== this.resolveToken) return;
      this.gravatarFailed = true;
    }
  }

  private onImageError = () => {
    this.gravatarFailed = true;
  };

  render() {
    const email = this.email.trim();
    if (!email) return nothing;

    const initials = emailInitials(email);
    const bg = emailAvatarColor(email);
    const showImage = this.gravatarSrc !== null && !this.gravatarFailed;
    const fontSize = Math.max(10, Math.round(this.size * 0.38));

    return html`
      <sonic-tooltip label=${email} placement="bottom">
        <span
          class="avatar"
          style="width:${this.size}px;height:${this.size}px;font-size:${fontSize}px;--avatar-bg:${bg}"
          aria-hidden="true"
        >
          ${showImage
            ? html`<img
                src=${this.gravatarSrc!}
                alt=""
                width=${this.size}
                height=${this.size}
                @error=${this.onImageError}
              />`
            : initials}
        </span>
      </sonic-tooltip>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "user-avatar": UserAvatar;
  }
}
