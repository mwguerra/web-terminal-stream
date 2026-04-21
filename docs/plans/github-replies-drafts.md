# Draft Replies — Community Contributions

These are draft responses to open issue #3 and PR #4. They are **not posted** — the author will review and edit before anything goes to GitHub. Intended tone: thankful, specific about what we're doing, honest about the timing.

---

## Reply to Issue #3 — Stream SSH mode crashes/hangs

**URL:** https://github.com/mwguerra/web-terminal/issues/3
**Reporter:** @solpreneur (Priesly Enongene)

> Thanks for the detailed write-up and the proposed patch — it identifies the right failure modes.
>
> I walked through the code on your diagnosis and agree in full:
>
> - `$ssh->setTimeout(0)` at `src/WebSocket/TerminalPtyBridge.php:111` is labelled "non-blocking" but phpseclib3 treats `0` as "no timeout = block forever." It survives in practice because `read('')` finds buffered data most of the time, but it is a real event-loop-wide hazard.
> - `$ssh->enablePTY(); $ssh->exec('')` bootstraps through CHANNEL_EXEC. Some SSH servers reject or half-close an empty exec — exactly the `channel (2)` error you see. CHANNEL_SHELL is the semantically correct path.
> - `read()` and `terminate()` having no try/catch means a single failed session can crash the entire ReactPHP loop, taking down every other live Stream session on the server. Server-wide availability bug, not per-session.
>
> We're addressing this on a `feature/frameless` branch that's doing a broader Stream-lifecycle pass (there's a related client-side teardown bug where `wire:navigate` navigation doesn't trigger cleanup). Your fix lands inside that work.
>
> Two small adjustments from what you proposed:
>
> 1. In the `read()` catch, in addition to returning empty string we'll mark the bridge dead (null out `sshShell`) so `tick()` stops polling a broken session and `handleClose` fires promptly. Otherwise a session whose SSH broke mid-stream looks idle-but-alive until the outer WebSocket timeout.
> 2. Adding Pest coverage for the failure modes — a mock `SSH2` that throws on `read()` and a test that confirms `tick()` does not crash.
>
> You'll be credited in the CHANGELOG for the diagnosis and patch. I'll keep this issue open and link the PR when the branch lands.

---

## Reply to PR #4 — Added confirm page and three dots for loading

**URL:** https://github.com/mwguerra/web-terminal/pull/4
**Contributor:** @wvanderwaal-iqmessenger (Wouter van der Waal)

> Thanks, Wouter — genuinely useful catch on `confirmBeforeRun()`. That method setting a flag nobody reads is the kind of bug that slides through because the fluent API and docs make it look functional.
>
> Your confirm/cancel implementation is correct. I want to integrate it, but I'd like to hold off merging this PR as-is. Three reasons:
>
> 1. **Frameless branch in progress.** We're reworking the scripts dropdown, header chrome, and panel surfaces in an active branch (`feature/frameless`). Merging the confirmation UI now and then immediately restyling it creates churn — better to land the functional logic inside the new chrome so there's one consistent visual language.
> 2. **Internal-state visibility regression.** The PR flips `$interactiveCommand` and `$interactiveStartTime` from `protected` to `public` (WebTerminal.php). Those are internal process-tracking fields; exposing them on Livewire's client-observable surface is a small security regression. I can keep the confirm/cancel logic working without that change.
> 3. **Shimmer target in the script-panel partial.** The `.shimmer-text` class ends up on a flex row `<div>` instead of the text node. Because the CSS uses `-webkit-background-clip: text` + `color: transparent`, applying it to a block container has undefined visual behavior (you probably intended it on the running command's text itself).
>
> What I'd like to do, if you're good with it:
>
> - Cherry-pick the `$pendingScriptKey` + `confirmPendingScript` + `cancelPendingScript` + dropdown confirmation UI into the `feature/frameless` branch.
> - Drop the two visibility changes.
> - Move `.shimmer-text` to the correct DOM target.
> - Add Pest coverage for the confirm/cancel flow.
> - Decide the loading indicator (your dots vs. the current spinner) as part of a cohesive chrome decision — either way you'll be credited as the person who shipped it. If we keep your dots, I'll pull that commit from you directly.
>
> CHANGELOG entry for 2.x will credit you for the feature fix. Happy to attribute the styling either way depending on what we land on.
>
> Closing this PR isn't my intent — I'll leave it open until the frameless branch merges so there's a clear reference point.

---

## Action items after the branch lands

- [ ] Post replies above (or edited versions).
- [ ] Tag @solpreneur on the merge commit for Issue #3 fix.
- [ ] Tag @wvanderwaal-iqmessenger on the merge commit for the confirm feature.
- [ ] Add both to a new "Contributors" section in CHANGELOG for the 2.x release that lands this work.
- [ ] Link PR #4 and Issue #3 in the final release notes.
