import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {subscribe} from "@supersoniks/concorde/decorators";
import {t} from "@supersoniks/concorde/directives/Wording";
import {
  exportTodosSnapshot,
  importTodosSnapshot,
} from "../api/client";
import {read} from "../../utils/dataprovider";
import {appConfigKey, type AppConfigForm} from "../dp";
import {tx} from "../i18n";
import {refreshConfigAppData} from "../utils/config-refresh";
import {confirmDialog, showAlert, showError} from "../utils/modal-dialog";
import {
  P2P_BROKER_LABEL,
  TadaP2pSession,
  type P2pStatus,
} from "../utils/p2p-share";
import tailwind from "../../css/tailwind";
import {ICON_LIBRARY, ICON_PREFIX} from "../icons";
import "./config-scope-header";
import "./page-shell";

@customElement("config-p2p-page")
export class ConfigP2pPage extends LitElement {
  static styles = [tailwind];

  @subscribe(appConfigKey.p2pReceiveCode)
  @state()
  p2pReceiveCode = "";

  @state()
  private busy = false;

  @state()
  private p2pStatus: P2pStatus = "idle";

  @state()
  private p2pDetail = "";

  @state()
  private p2pHostCode = "";

  private p2pSession: TadaP2pSession | null = null;

  disconnectedCallback() {
    this.p2pSession?.destroy();
    this.p2pSession = null;
    super.disconnectedCallback();
  }

  private bindP2pSession(session: TadaP2pSession) {
    this.p2pSession?.destroy();
    this.p2pSession = session;
    session.onStatusChange = (status, detail) => {
      this.p2pStatus = status;
      this.p2pDetail = detail ?? "";
      if (status === "waiting" && session.code) {
        this.p2pHostCode = session.code;
      }
    };
  }

  private onP2pStop = () => {
    this.p2pSession?.destroy();
    this.p2pSession = null;
    this.p2pStatus = "idle";
    this.p2pDetail = "";
    this.p2pHostCode = "";
  };

  private onP2pShare = async () => {
    if (this.busy) return;
    this.busy = true;
    try {
      const session = new TadaP2pSession();
      this.bindP2pSession(session);
      session.onPackageSent = () => {
        void showAlert(tx("p2p.status.done"), tx("p2p.share"));
      };
      const code = await session.startHost(() => exportTodosSnapshot());
      this.p2pHostCode = code;
    } catch (error) {
      this.onP2pStop();
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private onP2pReceive = async () => {
    if (this.busy) return;
    const form = read(appConfigKey.path) as AppConfigForm;
    const code = form.p2pReceiveCode?.trim() ?? "";
    if (!code) {
      await showError(
        new Error(tx("dialogs.unknown_error")),
        tx("dialogs.error"),
      );
      return;
    }

    this.busy = true;
    try {
      const session = new TadaP2pSession();
      this.bindP2pSession(session);
      session.onPackageReceived = (pkg) => {
        void (async () => {
          const ok = await confirmDialog({
            title: tx("p2p.receive_title"),
            message: [
              `« ${pkg.name} »`,
              `id ${pkg.id}`,
              `format tada v${pkg.version}`,
              "",
              tx("p2p.receive_confirm"),
            ].join("\n"),
            confirmLabel: tx("data.import"),
            danger: true,
          });
          if (!ok) {
            this.onP2pStop();
            return;
          }
          try {
            await importTodosSnapshot(pkg);
            await refreshConfigAppData();
            await showAlert(tx("p2p.status.done"), tx("p2p.receive_ok"));
          } catch (error) {
            await showError(error, tx("dialogs.error"));
            console.error(error);
          } finally {
            this.onP2pStop();
          }
        })();
      };
      await session.startGuest(code);
    } catch (error) {
      this.onP2pStop();
      await showError(error, tx("dialogs.error"));
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private get p2pStatusLabel(): string {
    switch (this.p2pStatus) {
      case "connecting":
        return tx("p2p.status.connecting");
      case "waiting":
        return tx("p2p.status.waiting");
      case "transferring":
        return tx("p2p.status.transfer");
      case "done":
        return tx("p2p.status.done");
      case "error":
        return this.p2pDetail || tx("p2p.status.error");
      case "idle":
        return tx("p2p.status.idle");
      default:
        return "";
    }
  }

  render() {
    return html`
      <page-shell>
        <div
          class="space-y-3 border-b-[.18rem] border-current pb-3 sm:space-y-4 sm:pb-4"
        >
          <config-scope-header section="p2p"></config-scope-header>
        </div>

        <div class="mt-8 space-y-10">
          <p class="text-sm text-neutral-600">
            ${t("p2p.intro")}
            <code class="text-xs">${P2P_BROKER_LABEL}</code>
          </p>

          <section class="space-y-3">
            <h2 class="text-base font-semibold">${t("p2p.share")}</h2>
            ${this.p2pHostCode
              ? html`
                  <div
                    class="rounded border border-neutral-200 bg-neutral-100 px-3 py-2"
                  >
                    <p class="text-xs text-neutral-500">${t("p2p.code_label")}</p>
                    <p
                      class="font-mono text-2xl font-semibold tracking-[0.2em] text-neutral-900"
                    >
                      ${this.p2pHostCode}
                    </p>
                  </div>
                `
              : nothing}

            ${this.p2pStatus !== "idle"
              ? html`
                  <p
                    class="text-sm ${this.p2pStatus === "error"
                      ? "text-red-700"
                      : "text-neutral-600"}"
                  >
                    ${this.p2pStatusLabel}
                  </p>
                `
              : nothing}

            <div class="flex flex-wrap gap-2">
              <sonic-button
                type="primary"
                size="sm"
                ?disabled=${this.busy || this.p2pStatus === "waiting"}
                @click=${this.onP2pShare}
              >
                <sonic-icon
                  library=${ICON_LIBRARY}
                  prefix=${ICON_PREFIX}
                  name="share-android"
                  size="sm"
                ></sonic-icon>
                ${t("p2p.share")}
              </sonic-button>
              ${this.p2pStatus !== "idle"
                ? html`
                    <sonic-button
                      variant="outline"
                      size="sm"
                      @click=${this.onP2pStop}
                    >
                      ${t("p2p.stop")}
                    </sonic-button>
                  `
                : nothing}
            </div>
          </section>

          <section class="space-y-3">
            <h2 class="text-base font-semibold">${t("p2p.receive_label")}</h2>
            <div
              class="flex flex-wrap items-end gap-2"
              formDataProvider=${appConfigKey.path}
            >
              <sonic-input
                name="p2pReceiveCode"
                label=${tx("p2p.receive_label")}
                size="sm"
                placeholder=${tx("p2p.receive_ph")}
                class="min-w-[10rem] flex-1 uppercase"
              ></sonic-input>
              <sonic-button
                size="sm"
                variant="outline"
                ?disabled=${this.busy}
                @click=${this.onP2pReceive}
              >
                ${t("p2p.connect")}
              </sonic-button>
            </div>
          </section>
        </div>
      </page-shell>
    `;
  }
}

declare global {
  interface HTMLElementTagNameMap {
    "config-p2p-page": ConfigP2pPage;
  }
}
