<?php

declare(strict_types=1);

namespace MWGuerra\WebTerminalStream\Data;

use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use MWGuerra\WebTerminalStream\Enums\PaneAction;

/**
 * The keyboard shortcut map for a terminal workspace.
 *
 * Key strings are lowercase, `+`-joined modifiers (`ctrl`, `alt`,
 * `shift`, `meta`) followed by a normalized `KeyboardEvent.key` value:
 * `'ctrl+b'`, `'shift+arrowleft'`, `'%'`, `'"'`.
 *
 * The prefix is a tmux-style leader: pressing it arms the workspace,
 * and the next key is matched against the action bindings. Pressing
 * the prefix twice sends the literal prefix byte to the focused pane.
 *
 * @implements Arrayable<string, mixed>
 *
 * @example
 * Keymap::tmux()->prefix('ctrl+a')->bind(PaneAction::SplitVertical, '|')
 */
final class Keymap implements Arrayable
{
    private const MODIFIERS = ['ctrl', 'alt', 'shift', 'meta'];

    private ?string $prefix = 'ctrl+b';

    private int $prefixTimeout = 1500;

    /** @var array<string, list<string>> PaneAction value => key strings */
    private array $bindings = [];

    private function __construct() {}

    /**
     * An empty keymap — no prefix, no bindings. Start here to build a
     * fully custom map.
     */
    public static function make(): self
    {
        $keymap = new self;
        $keymap->prefix = null;
        $keymap->bindings = [];

        return $keymap;
    }

    /**
     * The package default: the tmux preset.
     */
    public static function default(): self
    {
        return self::tmux();
    }

    /**
     * tmux-flavored bindings: prefix ctrl+b, `%`/`"` splits, `x` close,
     * `z` zoom, arrows or hjkl to focus, ctrl+arrows to resize.
     */
    public static function tmux(): self
    {
        return self::make()
            ->prefix('ctrl+b')
            ->bind(PaneAction::SplitHorizontal, '%')
            ->bind(PaneAction::SplitVertical, '"')
            ->bind(PaneAction::ClosePane, 'x')
            ->bind(PaneAction::ZoomPane, 'z')
            ->bind(PaneAction::FocusLeft, 'arrowleft', 'h')
            ->bind(PaneAction::FocusRight, 'arrowright', 'l')
            ->bind(PaneAction::FocusUp, 'arrowup', 'k')
            ->bind(PaneAction::FocusDown, 'arrowdown', 'j')
            ->bind(PaneAction::ResizeLeft, 'ctrl+arrowleft')
            ->bind(PaneAction::ResizeRight, 'ctrl+arrowright')
            ->bind(PaneAction::ResizeUp, 'ctrl+arrowup')
            ->bind(PaneAction::ResizeDown, 'ctrl+arrowdown');
    }

    /**
     * Hydrate from the `workspace.shortcuts` config shape. Provided keys
     * override the defaults; omitted ones keep the tmux preset.
     *
     * @param  array{prefix?: string|null, prefix_timeout?: int, bindings?: array<string, string|list<string>>}  $config
     */
    public static function fromArray(array $config): self
    {
        $keymap = self::default();

        if (array_key_exists('prefix', $config)) {
            $keymap->prefix($config['prefix']);
        }

        if (array_key_exists('prefix_timeout', $config)) {
            $keymap->prefixTimeout((int) $config['prefix_timeout']);
        }

        foreach ($config['bindings'] ?? [] as $action => $keys) {
            $paneAction = PaneAction::tryFrom((string) $action);

            if ($paneAction === null) {
                throw new InvalidArgumentException(sprintf(
                    'Unknown pane action "%s". Valid actions: %s',
                    $action,
                    implode(', ', array_column(PaneAction::cases(), 'value')),
                ));
            }

            $keys = is_string($keys) ? [$keys] : array_values($keys);

            $keys === []
                ? $keymap->unbind($paneAction)
                : $keymap->bind($paneAction, ...$keys);
        }

        return $keymap;
    }

    /**
     * The leader key, or null for prefix-less direct chords.
     */
    public function prefix(?string $keys): self
    {
        if ($keys !== null) {
            self::assertValidKey($keys);
        }

        $this->prefix = $keys;

        return $this;
    }

    /**
     * How long (ms) the armed prefix waits for an action key.
     */
    public function prefixTimeout(int $milliseconds): self
    {
        $this->prefixTimeout = max(100, $milliseconds);

        return $this;
    }

    /**
     * Replace the bindings for an action.
     */
    public function bind(PaneAction $action, string ...$keys): self
    {
        foreach ($keys as $key) {
            self::assertValidKey($key);
        }

        $this->bindings[$action->value] = array_values($keys);

        return $this;
    }

    public function unbind(PaneAction $action): self
    {
        unset($this->bindings[$action->value]);

        return $this;
    }

    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    public function getPrefixTimeout(): int
    {
        return $this->prefixTimeout;
    }

    /**
     * @return list<string>
     */
    public function getBindings(PaneAction $action): array
    {
        return $this->bindings[$action->value] ?? [];
    }

    /**
     * The wire format consumed by the workspace frontend.
     *
     * @return array{prefix: string|null, prefix_timeout: int, bindings: array<string, list<string>>}
     */
    public function toArray(): array
    {
        return [
            'prefix' => $this->prefix,
            'prefix_timeout' => $this->prefixTimeout,
            'bindings' => $this->bindings,
        ];
    }

    private static function assertValidKey(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgumentException('Key binding cannot be empty.');
        }

        if ($key !== mb_strtolower($key)) {
            throw new InvalidArgumentException(
                "Key binding \"{$key}\" must be lowercase (e.g. 'ctrl+b', 'shift+arrowleft')."
            );
        }

        // '+' alone is a valid key; otherwise split into modifiers + final key.
        if ($key === '+') {
            return;
        }

        $parts = explode('+', $key);
        $finalKey = array_pop($parts);

        if ($finalKey === '') {
            // A trailing '+' is only valid when '+' IS the final key
            // (e.g. 'ctrl++'), i.e. the preceding segment is also empty.
            if (end($parts) !== '') {
                throw new InvalidArgumentException("Key binding \"{$key}\" is missing its key.");
            }

            array_pop($parts);
            $finalKey = '+';
        }

        foreach ($parts as $modifier) {
            if (! in_array($modifier, self::MODIFIERS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid modifier "%s" in key binding "%s". Valid modifiers: %s',
                    $modifier,
                    $key,
                    implode(', ', self::MODIFIERS),
                ));
            }
        }
    }
}
