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

        $process = new Process(
            [
                config('overcloud.ai.bin'),
                '-p', $prompt,
                '--append-system-prompt', $system,
                '--model', (string) config('overcloud.ai.model', 'sonnet'),
            ],
            base_path(),
            $this->processEnv(),
            null,
            (float) config('overcloud.ai.timeout', 90),
        );

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
