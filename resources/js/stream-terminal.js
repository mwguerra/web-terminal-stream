// Stream Terminal - ghostty-web integration module
// Re-exports ghostty-web's Terminal, FitAddon, and init for use in the Blade view
import { init as ghosttyInit, Terminal, FitAddon } from 'ghostty-web';
import * as workspace from './terminal-workspace.js';

export { Terminal, FitAddon, workspace };

let initialized = false;

export async function init() {
    if (initialized) return;
    await ghosttyInit();
    initialized = true;
}
