import Peer, {type DataConnection, type PeerError} from "peerjs";
import type {TadaDataPackage} from "../api/data-package";

/** Broker PeerJS Cloud — gratuit, sans compte (`0.peerjs.com`). */
export const P2P_BROKER_LABEL = "PeerJS Cloud (0.peerjs.com)";

const PEER_PREFIX = "tada";
const CODE_ALPHABET = "ABCDEFGHJKLMNPQRSTUVWXYZ23456789";

type HelloMessage = {type: "tada-hello"; role: "host" | "guest"};
type PackageMessage = {type: "tada-package"; package: TadaDataPackage};
type RequestMessage = {type: "tada-request"};
type DoneMessage = {type: "tada-done"};
type P2pMessage =
  | HelloMessage
  | PackageMessage
  | RequestMessage
  | DoneMessage;

export type P2pStatus =
  | "idle"
  | "connecting"
  | "waiting"
  | "transferring"
  | "done"
  | "error";

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

function isP2pMessage(value: unknown): value is P2pMessage {
  if (!isRecord(value) || typeof value.type !== "string") return false;
  return value.type.startsWith("tada-");
}

export function generateShareCode(length = 6): string {
  const bytes = crypto.getRandomValues(new Uint8Array(length));
  let code = "";
  for (const byte of bytes) {
    code += CODE_ALPHABET[byte % CODE_ALPHABET.length];
  }
  return code;
}

export function normalizeShareCode(code: string): string {
  return code.trim().toUpperCase().replace(/[^A-Z0-9]/g, "");
}

export function peerIdFromCode(code: string): string {
  return `${PEER_PREFIX}-${normalizeShareCode(code)}`;
}

function peerErrorMessage(error: unknown): string {
  if (error instanceof Error) return error.message;
  if (isRecord(error) && typeof error.type === "string") {
    if (error.type === "unavailable-id") {
      return "Ce code est déjà pris, réessayez.";
    }
    if (error.type === "peer-unavailable") {
      return "Aucun hôte avec ce code (vérifiez le code / le réseau).";
    }
    if (error.type === "network") {
      return "Erreur réseau vers le broker PeerJS.";
    }
    return error.type;
  }
  return "Connexion P2P impossible.";
}

export class TadaP2pSession {
  private peer: Peer | null = null;
  private conn: DataConnection | null = null;
  code = "";
  status: P2pStatus = "idle";
  lastError = "";

  onStatusChange?: (status: P2pStatus, detail?: string) => void;
  onPackageReceived?: (pkg: TadaDataPackage) => void;
  onPackageSent?: () => void;

  private setStatus(status: P2pStatus, detail = "") {
    this.status = status;
    if (status === "error") this.lastError = detail;
    this.onStatusChange?.(status, detail);
  }

  destroy() {
    try {
      this.conn?.close();
    } catch {
      /* ignore */
    }
    try {
      this.peer?.destroy();
    } catch {
      /* ignore */
    }
    this.conn = null;
    this.peer = null;
    this.setStatus("idle");
  }

  /** Héberge un partage : affiche un code, envoie le package au premier pair. */
  async startHost(getPackage: () => Promise<TadaDataPackage>): Promise<string> {
    this.destroy();
    this.code = generateShareCode();
    this.setStatus("connecting");

    const peer = new Peer(peerIdFromCode(this.code));
    this.peer = peer;

    await new Promise<void>((resolve, reject) => {
      const onOpen = () => {
        cleanup();
        resolve();
      };
      const onError = (error: PeerError<"unavailable-id" | string>) => {
        cleanup();
        reject(new Error(peerErrorMessage(error)));
      };
      const cleanup = () => {
        peer.off("open", onOpen);
        peer.off("error", onError);
      };
      peer.on("open", onOpen);
      peer.on("error", onError);
    });

    this.setStatus("waiting", this.code);

    peer.on("connection", (conn) => {
      if (this.conn) {
        conn.close();
        return;
      }
      this.conn = conn;
      this.wireHostConnection(conn, getPackage);
    });

    peer.on("error", (error) => {
      this.setStatus("error", peerErrorMessage(error));
    });

    peer.on("disconnected", () => {
      if (this.status !== "done") {
        this.setStatus("error", "Déconnecté du broker PeerJS.");
      }
    });

    return this.code;
  }

  private wireHostConnection(
    conn: DataConnection,
    getPackage: () => Promise<TadaDataPackage>,
  ) {
    conn.on("open", () => {
      void (async () => {
        try {
          this.setStatus("transferring");
          conn.send({type: "tada-hello", role: "host"} satisfies HelloMessage);
          const pkg = await getPackage();
          conn.send({
            type: "tada-package",
            package: pkg,
          } satisfies PackageMessage);
          this.setStatus("done");
          this.onPackageSent?.();
        } catch (error) {
          this.setStatus("error", peerErrorMessage(error));
        }
      })();
    });

    conn.on("data", (data) => {
      if (!isP2pMessage(data)) return;
      if (data.type === "tada-request") {
        void (async () => {
          try {
            this.setStatus("transferring");
            const pkg = await getPackage();
            conn.send({
              type: "tada-package",
              package: pkg,
            } satisfies PackageMessage);
            this.setStatus("done");
            this.onPackageSent?.();
          } catch (error) {
            this.setStatus("error", peerErrorMessage(error));
          }
        })();
      }
      if (data.type === "tada-done") {
        this.setStatus("done");
      }
    });

    conn.on("error", (error) => {
      this.setStatus("error", peerErrorMessage(error));
    });

    conn.on("close", () => {
      if (this.status === "transferring") {
        this.setStatus("error", "Connexion fermée pendant le transfert.");
      }
    });
  }

  /** Reçoit un partage : se connecte au code hôte. */
  async startGuest(code: string): Promise<void> {
    this.destroy();
    const normalized = normalizeShareCode(code);
    if (normalized.length < 4) {
      throw new Error("Code trop court.");
    }
    this.code = normalized;
    this.setStatus("connecting");

    const peer = new Peer();
    this.peer = peer;

    await new Promise<void>((resolve, reject) => {
      const onOpen = () => {
        cleanup();
        resolve();
      };
      const onError = (error: PeerError<string>) => {
        cleanup();
        reject(new Error(peerErrorMessage(error)));
      };
      const cleanup = () => {
        peer.off("open", onOpen);
        peer.off("error", onError);
      };
      peer.on("open", onOpen);
      peer.on("error", onError);
    });

    const hostId = peerIdFromCode(normalized);
    const conn = peer.connect(hostId, {reliable: true});
    this.conn = conn;
    this.setStatus("waiting");

    await new Promise<void>((resolve, reject) => {
      const onOpen = () => {
        cleanup();
        resolve();
      };
      const onError = (error: unknown) => {
        cleanup();
        reject(new Error(peerErrorMessage(error)));
      };
      const cleanup = () => {
        conn.off("open", onOpen);
        conn.off("error", onError);
      };
      conn.on("open", onOpen);
      conn.on("error", onError);
      peer.on("error", onError);
    });

    conn.send({type: "tada-hello", role: "guest"} satisfies HelloMessage);
    conn.send({type: "tada-request"} satisfies RequestMessage);
    this.setStatus("transferring");

    conn.on("data", (data) => {
      if (!isP2pMessage(data)) return;
      if (data.type === "tada-package") {
        this.setStatus("done");
        this.onPackageReceived?.(data.package);
        conn.send({type: "tada-done"} satisfies DoneMessage);
      }
    });

    conn.on("error", (error) => {
      this.setStatus("error", peerErrorMessage(error));
    });

    conn.on("close", () => {
      if (this.status === "transferring") {
        this.setStatus("error", "Connexion fermée pendant le transfert.");
      }
    });
  }
}
