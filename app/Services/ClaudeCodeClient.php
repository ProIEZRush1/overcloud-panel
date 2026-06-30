<?php

namespace App\Services;

use App\Contracts\Assistant;
use App\Support\Ai;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

            // Self-heal a lapsed session: a redeploy wipes the run-as user's ~/.claude, logging the
            // assistant out — which would otherwise degrade every bot reply to a canned fallback. If
            // the call fails because we're logged out, re-seed creds from CLAUDE_CREDS_JSON and retry.
            if (! $process->isSuccessful()
                && Str::contains(Str::lower($process->getErrorOutput().$process->getOutput()), ['not logged in', 'please run /login'])
                && $this->reseedCreds()) {
                $process = $this->buildProcess([
                    config('overcloud.ai.bin'), '-p', $prompt,
                    '--append-system-prompt', $system, '--model', (string) config('overcloud.ai.model', 'sonnet'),
                ]);
                $process->run();
            }

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

    /** Re-seed the run-as user's Claude creds from CLAUDE_CREDS_JSON to recover a lapsed session. */
    private function reseedCreds(): bool
    {
        $creds = (string) env('CLAUDE_CREDS_JSON', '');
        if ($creds === '') {
            return false;
        }
        $home = (string) config('overcloud.ai.home', '/home/builder');
        $runAs = (string) config('overcloud.ai.run_as', '');
        try {
            File::ensureDirectoryExists($home.'/.claude');
            File::put($home.'/.claude/.credentials.json', $creds);
            @chmod($home.'/.claude/.credentials.json', 0600);
            if ($runAs !== '') {
                Process::fromShellCommandline('chown -R '.escapeshellarg($runAs.':'.$runAs).' '.escapeshellarg($home.'/.claude'))->run();
            }
            Log::warning('assistant session lapsed — re-seeded Claude creds from CLAUDE_CREDS_JSON');

            return true;
        } catch (\Throwable $e) {
            Log::warning('assistant creds re-seed failed', ['e' => $e->getMessage()]);

            return false;
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
            $cmd .= ' && '.Ai::tokenExport().implode(' ', array_map('escapeshellarg', $args));

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
