<?php

namespace App\Support;

class Ai
{
    /**
     * Shell snippet that authenticates the builder's `claude` via the long-lived subscription token.
     *
     * Every `claude` call runs through `su builder -c '…'`, which RESETS the environment — so the
     * container's CLAUDE_CODE_OAUTH_TOKEN never reaches it on its own. Prepend this to the inner command
     * to export the token inside that fresh shell. Returns '' when no token is configured (falls back to
     * whatever ~/.claude credentials exist), so it's always safe to prepend.
     */
    public static function tokenExport(): string
    {
        $token = (string) config('overcloud.ai.oauth_token');

        return $token !== ''
            ? 'export CLAUDE_CODE_OAUTH_TOKEN='.escapeshellarg($token).'; '
            : '';
    }
}
