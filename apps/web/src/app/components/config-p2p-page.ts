import "@supersoniks/concorde/input";
import "@supersoniks/concorde/button";
import "@supersoniks/concorde/icon";
import {html, LitElement, nothing} from "lit";
import {customElement, state} from "lit/decorators.js";
import {subscribe} from "@supersoniks/concorde/decorators";
import {
  exportTodosSnapshot,
  importTodosSnapshot,
} from "../api/client";
import {read} from "../../utils/dataprovider";
import {appConfigKey, type AppConfigForm} from "../dp";
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
        void showAlert(
          "Partage envoyé",
          "Le jeu actif a été transmis en P2P.",
        );
      };
      const code = await session.startHost(() => exportTodosSnapshot());
      this.p2pHostCode = code;
    } catch (error) {
      this.onP2pStop();
      await showError(error, "Impossible de démarrer le partage");
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
        new Error("Saisissez le code de partage."),
        "Code manquant",
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
            title: "Recevoir via P2P",
            message: [
              `« ${pkg.name} »`,
              `id ${pkg.id}`,
              `format tada v${pkg.version}`,
              "",
              "Remplacer le jeu actif par ces données ?",
            ].join("\n"),
            confirmLabel: "Importer",
            danger: true,
          });
          if (!ok) {
            this.onP2pStop();
            return;
          }
          try {
            await importTodosSnapshot(pkg);
            await refreshConfigAppData();
            await showAlert("Réception OK", `Jeu « ${pkg.name} » importé.`);
          } catch (error) {
            await showError(error, "Import P2P impossible");
            console.error(error);
          } finally {
            this.onP2pStop();
          }
        })();
      };
      await session.startGuest(code);
    } catch (error) {
      this.onP2pStop();
      await showError(error, "Connexion P2P impossible");
      console.error(error);
    } finally {
      this.busy = false;
    }
  };

  private get p2pStatusLabel(): string {
    switch (this.p2pStatus) {
      case "connecting":
        return "Connexion au broker…";
      case "waiting":
        return this.p2pHostCode
          ? `En attente d’un pair (code ${this.p2pHostCode})…`
          : "En attente…";
      case "transferring":
        return "Transfert en cours…";
      case "done":
        return "Terminé.";
      case "error":
        return this.p2pDetail || "Erreur.";
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

        <div class="mt-6 space-y-3">
          <p class="text-sm text-neutral-600">
            WebRTC direct. Rendez-vous gratuit sans compte :
            <code class="text-xs">${P2P_BROKER_LABEL}</code>
            (signaling uniquement).
          </p>

          ${this.p2pHostCode
            ? html`
                <div
                  class="rounded border border-neutral-200 bg-neutral-100 px-3 py-2"
                >
                  <p class="text-xs text-neutral-500">Code à transmettre</p>
                  <p
                    class="font-mono text-2xl font-semibold tracking-[0.2em] text-neutral-900"
                  >
                    ${this.p2pHostCode}
                  </p>
                </div>
              `
            : nothing}

          ${this.p2pStatusLabel
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
              Partager le jeu actif
            </sonic-button>
            ${this.p2pStatus !== "idle"
              ? html`
                  <sonic-button
                    variant="outline"
                    size="sm"
                    @click=${this.onP2pStop}
                  >
                    Arrêter
                  </sonic-button>
                `
              : nothing}
          </div>

          <div
            class="flex flex-wrap items-end gap-2"
            formDataProvider=${appConfigKey.path}
          >
            <sonic-input
              name="p2pReceiveCode"
              label="Recevoir (code)"
              size="sm"
              placeholder="Ex. K7M2PQ"
              class="min-w-[10rem] flex-1 uppercase"
            ></sonic-input>
            <sonic-button
              size="sm"
              variant="outline"
              ?disabled=${this.busy}
              @click=${this.onP2pReceive}
            >
              Se connecter
            </sonic-button>
          </div>
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
