<?php

use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Project;
use App\Services\BotResponder;
use App\Services\DeployService;

/** Invoke a private method without booting the service's dependencies. */
function callPrivate(object $obj, string $method, ...$args): mixed
{
    $m = new ReflectionMethod($obj, $method);
    $m->setAccessible(true);

    return $m->invoke($obj, ...$args);
}

function bot(): BotResponder
{
    return (new ReflectionClass(BotResponder::class))->newInstanceWithoutConstructor();
}

function deploy(): DeployService
{
    return (new ReflectionClass(DeployService::class))->newInstanceWithoutConstructor();
}

// H2/H6 — a question or a negation must NOT be auto-applied to a live site.
it('does not treat questions or negations as a change order', function () {
    $bot = bot();
    expect(callPrivate($bot, 'looksLikeChange', '¿se puede cambiar el color?'))->toBeFalse();
    expect(callPrivate($bot, 'looksLikeChange', 'cuánto cuesta cambiar el logo'))->toBeFalse();
    expect(callPrivate($bot, 'looksLikeChange', 'me gusta mucho la foto'))->toBeFalse();
    expect(callPrivate($bot, 'looksLikeChange', 'no le cambies nada al texto'))->toBeFalse();
    // A real imperative change still passes.
    expect(callPrivate($bot, 'looksLikeChange', 'cambia el color del header a azul'))->toBeTrue();
    expect(callPrivate($bot, 'looksLikeChange', 'ponle el logo más grande'))->toBeTrue();
});

// H4 — an explicit decline must NOT be read as approval.
it('does not treat a decline as a yes', function () {
    $bot = bot();
    expect(callPrivate($bot, 'isYes', 'no apruebo todavía'))->toBeFalse();
    expect(callPrivate($bot, 'isYes', 'todavía no'))->toBeFalse();
    expect(callPrivate($bot, 'isYes', 'mejor no'))->toBeFalse();
    expect(callPrivate($bot, 'isYes', 'no acepto'))->toBeFalse();
    // A clean approval still passes.
    expect(callPrivate($bot, 'isYes', 'sí, dale'))->toBeTrue();
    expect(callPrivate($bot, 'isYes', 'claro, lo apruebo'))->toBeTrue();
});

// H3 — two same-named clients must get different, stable URLs.
it('builds unique, stable subdomains per project and per lead', function () {
    $deploy = deploy();

    $lead1 = new Lead;
    $lead1->company = 'Taco';
    $lead1->uuid = 'aaaaaa11-1111-1111-1111-111111111111';
    $lead2 = new Lead;
    $lead2->company = 'Taco';
    $lead2->uuid = 'bbbbbb22-2222-2222-2222-222222222222';

    expect($deploy->demoSubdomain($lead1))->toBe('taco-demo-aaaaaa');
    expect($deploy->demoSubdomain($lead1))->not->toBe($deploy->demoSubdomain($lead2));

    $p1 = new Project;
    $p1->uuid = 'cccccc33-3333-3333-3333-333333333333';
    $p1->setRelation('lead', $lead1);
    $p2 = new Project;
    $p2->uuid = 'dddddd44-4444-4444-4444-444444444444';
    $p2->setRelation('lead', $lead1); // same client, different project

    expect($deploy->subdomainFor($p1))->toBe('taco-cccccc');
    expect($deploy->subdomainFor($p1))->not->toBe($deploy->subdomainFor($p2));
});

// H1 — a pending (voice-note) change is held, readable once, and expires.
it('holds a pending change and expires it after 30 minutes', function () {
    $c = new Conversation;

    $c->meta = ['pending_change' => ['instruction' => 'pon el logo más grande', 'at' => now()->toIso8601String()]];
    expect($c->pendingChange())->toBe('pon el logo más grande');

    $c->meta = ['pending_change' => ['instruction' => 'pon el logo más grande', 'at' => now()->subMinutes(31)->toIso8601String()]];
    expect($c->pendingChange())->toBeNull();

    $c->meta = [];
    expect($c->pendingChange())->toBeNull();
});
