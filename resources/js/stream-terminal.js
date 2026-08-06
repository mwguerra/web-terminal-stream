// Stream Terminal - ghostty-web integration module
// Re-exports ghostty-web's Terminal, FitAddon, and init for use in the Blade view
import { init as ghosttyInit, Terminal, FitAddon } from 'ghostty-web';
import * as workspace from './terminal-workspace.js';

export { Terminal, FitAddon, workspace };

// Register the workspace Alpine component under a STABLE x-data name.
// Alpine may load before or after this bundle, so hook both paths.
const registerWorkspaceComponent = () => {
    if (window.Alpine && !window.Alpine.__wtsWorkspaceRegistered) {
        window.Alpine.__wtsWorkspaceRegistered = true;
        window.Alpine.data('wtsWorkspace', workspace.component);
        window.Alpine.data('wtsDashboard', workspace.dashboard);
    }
};

registerWorkspaceComponent();
document.addEventListener('alpine:init', registerWorkspaceComponent);

let initialized = false;

export async function init() {
    if (initialized) return;
    await ghosttyInit();
    initialized = true;
}
