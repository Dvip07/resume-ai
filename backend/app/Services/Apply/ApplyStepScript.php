<?php

namespace App\Services\Apply;

/**
 * A fluent builder for the automation worker's declarative apply script.
 *
 * Exists so adapters read like the form they are filling in rather than like
 * JSON assembly, and so the step kinds the worker accepts (`goto`, `fill`,
 * `select`, `check`, `click`, `upload`, `waitFor`, `readText`, `screenshot`)
 * are spelled out in exactly one place. Validation stays in the worker, which
 * rejects a malformed script all-or-nothing before any browser work.
 *
 * Empty values are skipped rather than filled blank: a profile with no phone
 * number should leave the field alone, not type "" into it and trip the form's
 * own validation.
 */
final class ApplyStepScript
{
    /** @var array<int, array<string, mixed>> */
    private array $steps = [];

    public static function make(): self
    {
        return new self;
    }

    /** Navigate. The initial URL is the request's own `url`, so this is for later pages. */
    public function goto(string $url, ?int $timeoutMs = null): self
    {
        return $this->push(['kind' => 'goto', 'url' => $url], $timeoutMs);
    }

    /** Type into a field. A null/empty value is a no-op. */
    public function fill(string $selector, ?string $value, ?int $timeoutMs = null): self
    {
        if ($value === null || $value === '') {
            return $this;
        }

        return $this->push(['kind' => 'fill', 'selector' => $selector, 'value' => $value], $timeoutMs);
    }

    /**
     * Choose one or more options. A null/empty value is a no-op.
     *
     * @param  string|array<int, string>|null  $value
     */
    public function select(string $selector, string|array|null $value, ?int $timeoutMs = null): self
    {
        if ($value === null || $value === '' || $value === []) {
            return $this;
        }

        return $this->push(['kind' => 'select', 'selector' => $selector, 'value' => $value], $timeoutMs);
    }

    public function check(string $selector, bool $checked = true, ?int $timeoutMs = null): self
    {
        return $this->push(['kind' => 'check', 'selector' => $selector, 'checked' => $checked], $timeoutMs);
    }

    public function click(string $selector, ?int $timeoutMs = null): self
    {
        return $this->push(['kind' => 'click', 'selector' => $selector], $timeoutMs);
    }

    /**
     * Attach a document. `$url` must be an https signed URL — the worker never
     * takes inline bytes, and the request body is capped far below a PDF.
     */
    public function upload(string $selector, string $url, ?string $filename = null, ?int $timeoutMs = null): self
    {
        $step = ['kind' => 'upload', 'selector' => $selector, 'url' => $url];

        if (is_string($filename) && $filename !== '') {
            $step['filename'] = $filename;
        }

        return $this->push($step, $timeoutMs);
    }

    public function waitForSelector(string $selector, string $state = 'visible', ?int $timeoutMs = null): self
    {
        return $this->push(['kind' => 'waitFor', 'selector' => $selector, 'state' => $state], $timeoutMs);
    }

    public function waitForMs(int $ms): self
    {
        return $this->push(['kind' => 'waitFor', 'ms' => $ms], null);
    }

    /** Read text back under `$name`, e.g. a validation message or a confirmation banner. */
    public function readText(string $selector, string $name, ?int $timeoutMs = null): self
    {
        return $this->push(['kind' => 'readText', 'selector' => $selector, 'name' => $name], $timeoutMs);
    }

    /** Capture the audit trail (Req 9.5). `name` is required by the worker. */
    public function screenshot(string $name, bool $fullPage = true): self
    {
        return $this->push(['kind' => 'screenshot', 'name' => $name, 'fullPage' => $fullPage], null);
    }

    public function isEmpty(): bool
    {
        return $this->steps === [];
    }

    public function count(): int
    {
        return count($this->steps);
    }

    /** @return array<int, array<string, mixed>> */
    public function toArray(): array
    {
        return $this->steps;
    }

    /** @param array<string, mixed> $step */
    private function push(array $step, ?int $timeoutMs): self
    {
        if ($timeoutMs !== null && $timeoutMs > 0) {
            $step['timeoutMs'] = $timeoutMs;
        }

        $this->steps[] = $step;

        return $this;
    }
}
