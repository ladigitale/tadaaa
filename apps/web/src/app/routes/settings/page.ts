import {redirectPage} from "../../utils/legacy-redirect";
import {SETTINGS_DEFAULT} from "../../utils/config-paths";

export default function SettingsRoutePage() {
  return redirectPage(SETTINGS_DEFAULT);
}
