# 07 — Layout engine: LayoutTree + Keymap

**Area key:** `layout-engine`

## Summary

The pure layout/keymap logic is well-factored, immutable, and heavily unit-tested (53 assertions pass on PHP 8.5.7). LayoutTree's core operations are correct: splitPane/removePane collapse semantics (last-pane→null, nested subtree promotion, deep-pane preservation, surviving-ratio preservation) all verified; arrange() even-ratio math is exact across every preset and count (the 1/n descending-chain produces perfectly equal panes at 9 panes; tiled 2/3/4 special cases and main-left/top are right); updateRatios clamps to [min,1-min], ignores unknown ids, and never mutates inputs. The PHP 8.5 spread-in-destructuring fatal ([$first, ...$rest]) that b53255d fixed with array_slice is genuinely gone — the only remaining spreads (LayoutTree.php:215, TerminalTheme.php:185) are safe array-literal spreads, and no other 8.5-sensitive destructuring lurks in these or nearby files. Keymap's grammar handles the hard cases ('+', 'ctrl++', trailing-separator rejection) correctly, and tmux() deliberately leaves the four directional-split actions unbound (documented). Weaknesses are all in the untrusted-input / footgun surface: validate() — the documented guard for malformed client trees — misses duplicate SPLIT ids (only pane ids are deduped), which lets updateRatios drive two dividers from one id; and fromArray(prefix:null) inherits tmux's single-character bindings, making the terminal untypeable. Both are low-probability in the package's own Locked-tree flow but are real gaps in the defensive/public API. Note validate() and sameTopology() are never actually called from src/ — the tree is a #[Locked] prop, so the "malformed tree from client" threat their docblocks address cannot reach them through the package.

## What exists and works

- arrange() even-ratio math is correct for all presets and counts: verified 9-pane tiled falls back to an even Horizontal chain with ratios 1/9,1/8,...,1/2 that resolve to equal panes; columns/rows/main-left/main-top all produce documented shapes (LayoutTree.php:79-146)
- removePane/collapse semantics correct across every case tested: last pane returns null (line 197-199), a two-pane split collapses to its sibling, a nested split is promoted whole, a deep pane is removed while surrounding structure and ratios survive (LayoutTree.php:191-202, 352-369)
- updateRatios clamps each applied ratio to [minRatio, 1-minRatio], ignores unknown split ids, reaches nested splits, and does not mutate the caller's array (value semantics) (LayoutTree.php:225-239, 314-317)
- validate() rejects unknown node types, missing pane/split ids, invalid orientation, out-of-range/non-numeric ratios, and duplicate PANE ids; the L302 boolean-precedence expression (!is_float && !is_int) || <= 0 || >= 1 is actually correct — comparisons only evaluate for real numbers (LayoutTree.php:265-312)
- PHP 8.5 spread-in-destructuring fatal is fully remediated: no [$x, ...$rest] destructuring remains anywhere in src/; the two surviving spreads are safe array-literal spreads (paneIds LayoutTree.php:215, TerminalTheme.php:185)
- Keymap binding-string grammar handles edge cases correctly: bare '+' key, 'ctrl++' (ctrl + '+' key), and rejection of trailing-separator-with-no-key ('ctrl+', 'x+', 'ctrl+shift+') all behave per tests (Keymap.php:204-245)
- tmux() preset binds 12 of 16 actions; the four directional splits (SplitLeft/Right/Up/Down) are intentionally unbound and documented as opt-in, not an omission (Keymap.php:65-81, PaneAction.php:21-33)
- fromArray precedence is correct: starts from tmux default, per-key overrides, empty-list unbinds, unknown action names throw with a helpful list (Keymap.php:89-120)
- StreamWorkspace keeps the tree/panes as #[Locked] props and only accepts paneId/orientation/ratio scalars from the client, each re-validated server-side before touching LayoutTree — keystrokes never round-trip (StreamWorkspace.php:36-53, 115-220)

## Gaps (missing functionality)

| Gap | Severity | Detail |
|---|:--:|---|
| validate() does not enforce unique split-node ids (only pane ids) | medium | validate() dedupes pane ids (LayoutTree.php:269-273) but never checks split ids for uniqueness. Its docblock states it guards an 'untrusted tree' against structural problems, yet a tree with two splits sharing an id passes. I confirmed empirically: a tree with two 's-dup' splits is accepted, and updateRatios(['s-dup'=>0.8]) then sets BOTH splits' ratios to 0.8 (LayoutTree.php:231 matches every node with that id). sameTopology also keys on id. In the package's own flow split ids are unique (arrange uses s-arr-N, splitPane uses s-<paneId>), so harm is confined to external consumers that build/accept trees and rely on validate(); still, it is an incompleteness in the one function whose job is to reject malformed trees. |
| validate() and sameTopology() are never invoked by the package; the threat they document cannot occur through it | low | grep shows no caller of LayoutTree::validate or sameTopology in src/ (only tests). validate()'s docblock frames it as the guard for a 'malformed tree from client', but StreamWorkspace.tree is a #[Locked] Livewire prop, so the client can never submit a tree — only scalar paneId/orientation/ratio, each re-checked in the Livewire methods (StreamWorkspace.php:115-220). The guard is therefore dead relative to its stated threat model and exercised only in unit tests, meaning regressions in it (e.g. the duplicate-split-id gap above) would never surface through package usage. This is defensible as public API for external tree builders, but the docblock overstates its role in this package. |
| No detection of the same key bound to multiple actions | low | bind() replaces a single action's keys but nothing rejects binding the same key string to two different actions (confirmed: Keymap::tmux()->bind(ZoomPane,'x') leaves 'x' on both ClosePane and ZoomPane). The frontend dispatch on that key becomes ambiguous with no server-side warning. Purely a DX safeguard gap. |
| prefixTimeout has a floor but no ceiling | low | prefixTimeout(int) clamps to max(100, …) (Keymap.php:146) but accepts arbitrarily large values (999999 verified). A very large timeout leaves the workspace armed almost indefinitely after the prefix, swallowing subsequent keystrokes. Minor. |

## Flaws (implemented but wrong/risky)

Each critical/high flaw was handed to an independent skeptic instructed to refute it.

### fromArray(prefix: null) keeps tmux's single-character bindings, making the terminal untypeable
- **Severity:** medium — **Verdict:** ⚪ unverified
- **Files:** src/Data/Keymap.php
- **Detail:** fromArray() seeds from the tmux preset then applies overrides (Keymap.php:89-120). Passing prefix:null (a documented, valid value, also reachable by setting config web-terminal-stream.workspace.shortcuts.prefix = null) sets a null prefix WITHOUT clearing the inherited single-char bindings. I confirmed Keymap::fromArray(['prefix'=>null]) returns prefix=null while ClosePane is still bound to 'x'. With a null prefix the frontend fires actions on raw keystrokes, so typing 'x', 'z', '%', or '"' triggers pane actions instead of reaching the PTY — the exact footgun the WARNING on prefix() (Keymap.php:124-129) describes, but fromArray (the config-driven path) provides no guard or notice. Requires deliberate config, hence medium.
- **Evidence:** Keymap.php:130-139 prefix(null) sets prefix without touching bindings; fromArray never re-checks. Probe: Keymap::fromArray(['prefix'=>null]) => prefix=null, getBindings(ClosePane)=['x'].

### Duplicate split ids accepted by validate() cause updateRatios to move unrelated dividers
- **Severity:** medium — **Verdict:** ⚪ unverified
- **Files:** src/Data/Layout/LayoutTree.php
- **Detail:** Because validate() omits split-id uniqueness (see gap), a malformed/duplicated tree survives validation and updateRatios(tree, ['s-dup'=>0.8]) rewrites every split node carrying that id in a single recursive pass (LayoutTree.php:231-236), silently resizing an unrelated divider. sameTopology (LayoutTree.php:254) likewise cannot distinguish colliding-id subtrees. Not reachable through the package's own id-generation, but the validation layer that exists specifically to catch bad trees does not catch this class of corruption.
- **Evidence:** Probe on a tree with two 's-dup' splits: updateRatios(['s-dup'=>0.8]) returned outer ratio 0.8 AND inner ratio 0.8. LayoutTree.php:231 array_key_exists match has no once-only guard.

### Key-binding grammar accepts a bare modifier name (or duplicate modifiers) as the key
- **Severity:** low — **Verdict:** ⚪ unverified
- **Files:** src/Data/Keymap.php
- **Detail:** assertValidKey treats the last '+'-segment as the key regardless of whether it is itself a modifier name and never rejects repeated modifiers. Confirmed: bind(ZoomPane,'alt') is accepted with key='alt'; 'ctrl+shift' is accepted as modifier=[ctrl], key='shift'; 'ctrl+ctrl+b' is accepted with duplicate ctrl. These map to real KeyboardEvent.key values (Alt/Shift) so they are not outright invalid, but 'ctrl+shift'-style bindings that a user intends as a modifier-only chord silently bind to the physical Shift key instead, a likely-surprising outcome with no diagnostic.
- **Evidence:** Keymap.php:221-244 pops the final segment as the key and validates only the preceding segments against MODIFIERS; no check that the final key isn't a modifier and no dedupe. Probes: bind 'alt' => ['alt']; bind 'ctrl+shift' => ['ctrl+shift']; bind 'ctrl+ctrl+b' => ['ctrl+ctrl+b'] all OK.

