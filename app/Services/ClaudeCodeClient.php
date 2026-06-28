<?php

namespace App\Services;

use App\Contracts\Assistant;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Drives the local Claude Code CLI (`claude -p`) using the user's subscription —
 * no API key, no per-token billing. Falls back to null on any failure so the
 * deterministic script can take over.
 */
class ClaudeCodeClient implements Assistant
{
    public function isEnabled(): bool
    {
        return (bool) config('overcloud.ai.enabled')
            && config('overcloud.ai.driver') === 'claude_code'
            && filled(config('overcloud.ai.bin'));
    }

    public function message(string $system, array $messages, ?int $maxTokens = null): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $transcript = collect($messages)->map(function (array $m): string {
            $role = ($m['role'] ?? 'user') === 'assistant' ? 'Asistente' : 'Cliente';

            return $role.': '.($m['content'] ?? '');
        })->implode("\n");

        $prompt = "Conversación de WhatsApp hasta ahora:\n\n".$transcript
            ."\n\nEscribe ÚNICAMENTE el texto de la próxima respuesta del Asistente "
            .'(sin prefijos como "Asistente:", sin comillas, sin explicaciones).';

        $process = $this->buildProcess([
            config('overcloud.ai.bin'),
            '-p', $prompt,
            '--append-system-prompt', $system,
            '--model', (string) config('overcloud.ai.model', 'sonnet'),
        ]);

        try {
            $process->run();

            if (! $process->isSuccessful()) {
                Log::warning('Claude Code reply failed', ['err' => mb_substr($process->getErrorOutput(), 0, 400)]);

                return null;
            }

            $out = trim($process->getOutput());

            return $out !== '' ? $out : null;
        } catch (\Throwable $e) {
            Log::warning('Claude Code reply errored', ['e' => $e->getMessage()]);

            return null;
        }
    }

    /** Raw completion for structured output (JSON, extraction) — no conversational wrapper. */
    public function complete(string $prompt, ?int $maxTokens = null): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $process = $this->buildProcess([
            config('overcloud.ai.bin'),
            '-p', $prompt,
            '--model', (string) config('overcloud.ai.model', 'sonnet'),
        ]);

        try {
            $process->run();
            if (! $process->isSuccessful()) {
                Log::warning('Claude Code complete failed', ['err' => mb_substr($process->getErrorOutput(), 0, 400)]);

                return null;
            }
            $out = trim($process->getOutput());

            return $out !== '' ? $out : null;
        } catch (\Throwable $e) {
            Log::warning('Claude Code complete errored', ['e' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Build the claude process. In production we run it as the non-root user that owns the
     * PERSISTENT, auto-refreshing credentials volume (config ai.run_as + ai.home) so the OAuth
     * token is refreshed in place and survives restarts/deploys — instead of the worker using an
     * ephemeral ~/.claude re-seeded from a stale env snapshot (which silently logs the bot out).
     * Locally (no run_as), it runs claude directly with the developer's own HOME.
     */
    private function buildProcess(array $args): Process
    {
        $timeout = (float) config('overcloud.ai.timeout', 120);
        $runAs = (string) config('overcloud.ai.run_as');
        $home = (string) config('overcloud.ai.home');

        if ($runAs !== '') {
            $cmd = 'cd '.escapeshellarg(base_path());
            if ($home !== '') {
                $cmd .= ' && export HOME='.escapeshellarg($home);
            }
            $cmd .= ' && '.implode(' ', array_map('escapeshellarg', $args));

            return new Process(['su', $runAs, '-c', $cmd], base_path(), null, null, $timeout);
        }

        return new Process($args, base_path(), $this->processEnv(), null, $timeout);
    }

    /** Ensure HOME (for ~/.claude credentials) and PATH are present even under php-fpm. */
    private function processEnv(): array
    {
        $home = $_SERVER['HOME'] ?? (getenv('HOME') ?: '/Users/'.get_current_user());
        $binDir = dirname((string) config('overcloud.ai.bin'));
        $path = getenv('PATH') ?: '';

        return [
            'HOME' => $home,
            'PATH' => $binDir.':/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin'.($path ? ':'.$path : ''),
        ];
    }
}
