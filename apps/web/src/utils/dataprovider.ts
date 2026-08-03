/**
 * Accès programmatique aux DataProviders Concorde.
 *
 * Importer depuis ce fichier ou directement depuis Concorde :
 *
 * ```ts
 * import { get, set, dp } from "@supersoniks/concorde/utils";
 * // ou
 * import { get, set } from "src/utils/dataprovider";
 * ```
 *
 * | Export | Rôle |
 * |--------|------|
 * | **`get(chemin)`** | Instance **DataProvider** (alias Concorde `dp` / `dataProvider`) — `.set()`, sous-chemins… |
 * | **`set(chemin, valeur)`** | Remplace la valeur à la racine |
 * | **`read(chemin)`** | Snapshot de la valeur racine (Concorde `get` d’origine) |
 *
 * Ne pas utiliser `PublisherManager` ni créer de wrapper `getDataProvider`.
 */
export {
  dp as get,
  dp,
  dataProvider,
  set,
  get as read,
  type DataProvider,
} from "@supersoniks/concorde/utils";
