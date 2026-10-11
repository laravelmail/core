<?php

declare(strict_types=1);

namespace LaravelMail\Services\Templates;

use Symfony\Component\Process\Process;

/** Server-side MJML -> HTML. Optional: needs node and `npm install` in resources/mjml. mj-include is ignored. */
class MjmlCompiler
{
    public const MAX_BYTES = 1_000_000;

    public function available(): bool
    {
        $script = $this->script();

        return is_file($script) && is_dir(dirname($script).'/node_modules/mjml') && $this->node() !== null;
    }

    /** @return array{html: string, errors: list<string>} */
    public function compile(string $mjml): array
    {
        if (strlen($mjml) > self::MAX_BYTES) {
            throw new MjmlCompileException('MJML is too large (1 MB max).');
        }
        if (! str_contains($mjml, '<mjml')) {
            throw new MjmlCompileException('Not MJML: the source must contain <mjml>.');
        }
        if (! $this->available()) {
            throw new MjmlCompileException('The MJML compiler is not installed (needs node and npm install in resources/mjml).');
        }

        $p = new Process([$this->node(), $this->script()], dirname($this->script()), null, $mjml, 20);
        $p->run();
        $out = json_decode($p->getOutput(), true);
        if (! is_array($out) || ! is_string($out['html'] ?? null) || $out['html'] === '') {
            $errors = is_array($out['errors'] ?? null) ? implode('; ', $out['errors']) : trim($p->getErrorOutput());
            throw new MjmlCompileException('MJML compile failed: '.($errors ?: 'no output'));
        }

        return ['html' => $out['html'], 'errors' => array_values(array_map('strval', (array) ($out['errors'] ?? [])))];
    }

    /** Compile when possible, otherwise return the input unchanged (raw HTML templates keep working). */
    public function compileOrPassThrough(string $source): string
    {
        if (! str_contains($source, '<mjml') || ! $this->available()) {
            return $source;
        }

        return $this->compile($source)['html'];
    }

    private function script(): string
    {
        return (string) (config('laravelmail.mjml.script') ?: dirname(__DIR__, 3).'/resources/mjml/compile.cjs');
    }

    private function node(): ?string
    {
        $configured = config('laravelmail.mjml.node');
        if ($configured) {
            return is_executable($configured) ? $configured : null;
        }
        foreach (['/usr/local/bin/node', '/usr/bin/node'] as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
