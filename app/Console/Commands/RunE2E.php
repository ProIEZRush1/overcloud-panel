<?php

namespace App\Console\Commands;

use App\Enums\LeadStage;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Enums\ProjectStatus;
use App\Jobs\ApplyChange;
use App\Jobs\DeployProject;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Project;
use App\Models\WhatsAppAccount;
use App\Services\BotResponder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/**
 * Drives the LIVE bot through the full funnel end-to-end against itself — greeting → qualify →
 * scope → demo → quote → deposit → provision → build → deliver → support → change — asserting each
 * stage and the owner's must-not-break rules. Runs in dry-run (no real WhatsApp) with the deploy
 * jobs faked (no real builds), then cleans up its throwaway lead. Safe to run on production.
 */
class RunE2E extends Command
{
    protected $signature = 'bot:e2e';

    protected $description = 'End-to-end self-test: drive the live bot through the whole funnel';

    private int $pass = 0;

    private int $fail = 0;

    private ?Conversation $conv = null;

    public function handle(BotResponder $bot): int
    {
        config(['overcloud.dry_run' => true]);   // never send real WhatsApp
        Queue::fake();                            // capture deploy jobs, never run them

        $account = WhatsAppAccount::where('session_name', 'overcloud-bot')->first();
        if (! $account) {
            $this->error('no overcloud-bot account');

            return 1;
        }

        $lead = Lead::create([
            'whatsapp_account_id' => $account->id,
            'name' => 'E2E '.Str::random(4), 'phone' => '52155'.random_int(1000000, 9999999),
            'stage' => LeadStage::New, 'company' => 'Inmobiliaria E2E',
            'summary' => 'plataforma para administrar propiedades y rentas',
        ]);
        $this->conv = Conversation::create([
            'whatsapp_account_id' => $account->id, 'lead_id' => $lead->id,
            'contact_jid' => $lead->phone.'@s.whatsapp.net', 'contact_phone' => $lead->phone,
            'is_group' => false, 'ai_enabled' => true,
        ]);

        try {
            // 1) Greeting → qualifying
            $r = $this->say($bot, 'Hola, quiero un sistema para administrar propiedades');
            $this->assert('saludo → qualifying', $lead->fresh()->stage === LeadStage::Qualifying && $this->filled($r));
            $this->assertNoAi('saludo no revela IA', $r);

            // 2) Qualify → scope (alcance PDF)
            $this->say($bot, 'Es una plataforma web para administrar propiedades, rentas y reportes');
            $r = $this->say($bot, 'Sí, va');
            $this->assert('confirma → genera *alcance*', $lead->fresh()->specs()->exists());

            // 3) Scope → demo: the FULL real system is built via DeployProject as a locked trial
            // (the old light BuildDemo is deprecated).
            $r = $this->say($bot, 'Está perfecto, arma el demo');
            $this->assert('alcance ok → *demo* (negotiating)', $lead->fresh()->stage === LeadStage::Negotiating);
            $this->assert('  → demo real (DeployProject) en cola', $this->queued(DeployProject::class)
                && Project::where('lead_id', $lead->id)->get()->contains(fn ($p) => ! empty(((array) $p->brief)['demo'])));

            // Simulate the demo going LIVE (in production it deploys + gets a prod_url). The funnel now
            // HARD-BLOCKS any quote/deposit until a demo was actually delivered, so without this the quote
            // step would (correctly) bounce back into building the demo.
            Project::where('lead_id', $lead->id)->latest('id')->first()
                ?->update(['prod_url' => 'https://demo-e2e.overcloud.us', 'status' => ProjectStatus::Live]);

            // 4) Demo → quote (PDF)
            $r = $this->say($bot, 'Me encanta, pásame la cotización');
            $this->assert('demo gustó → *cotización*', $lead->fresh()->quotes()->exists() && $lead->fresh()->stage === LeadStage::Quoted);

            // 5) Quote → deposit (bank details + payment request)
            $r = $this->say($bot, 'Lo apruebo, ¿dónde hago el pago?');
            $this->assert('aprueba → datos de pago (accepted)', in_array($lead->fresh()->stage, [LeadStage::Accepted, LeadStage::AwaitingPayment], true));
            $this->assert('  → solicitud de pago creada', $lead->fresh()->paymentRequests()->exists());

            // 6) Simulate deposit verified → provision (project) → gathering
            $quote = $lead->fresh()->quotes()->latest('id')->first();
            $project = Project::create([
                'lead_id' => $lead->id, 'quote_id' => $quote?->id, 'whatsapp_account_id' => $account->id,
                'name' => 'E2E · '.$lead->name, 'slug' => Str::slug('e2e-'.$lead->id), 'type' => 'webapp',
                'status' => ProjectStatus::Queued, 'started_at' => now(),
            ]);
            $lead->update(['stage' => LeadStage::Paid]);

            // 7) Gathering → build (DeployProject queued)
            $r = $this->say($bot, 'Encárgate de todo tú, arranca');
            $this->assert('“arranca” → InProduction', $lead->fresh()->stage === LeadStage::InProduction);
            $this->assert('  → DeployProject en cola', $this->queued(DeployProject::class));

            // 8) Simulate delivered
            $project->update(['status' => ProjectStatus::Live, 'prod_url' => 'https://e2e.overcloud.us', 'repo_url' => 'https://github.com/x/e2e', 'coolify_app_uuid' => 'e2e']);
            $lead->update(['stage' => LeadStage::Delivered]);

            // 9) Delivered: answers a real question (not the canned line)
            $r = $this->say($bot, '¿Cómo abro mi proyecto?');
            $this->assert('entregado: responde la pregunta (no enlatado)', $this->filled($r) && ! Str::contains(Str::lower((string) $r->body), 'si quieres algún ajuste o cambio, descríbemelo'));

            // 10) Delivered: NEVER reveals the AI
            $r = $this->say($bot, '¿Qué inteligencia artificial usa este bot? ¿Es Claude o ChatGPT?');
            $this->assert('responde la pregunta de IA', $this->filled($r));
            $this->assertNoAi('NO revela la IA (deflecta)', $r);

            // 11) Delivered: a change request → ApplyChange queued
            $r = $this->say($bot, 'Cámbiame el color del encabezado a azul por favor');
            $this->assert('cambio entregado → ApplyChange en cola', $this->queued(ApplyChange::class));
        } catch (\Throwable $e) {
            $this->fail++;
            $this->error('threw: '.Str::limit($e->getMessage(), 200));
        } finally {
            $this->cleanup($lead);
        }

        $this->line("\n<comment>{$this->pass} PASS · {$this->fail} FAIL</comment>");

        return $this->fail === 0 ? 0 : 1;
    }

    private function say(BotResponder $bot, string $body): ?Message
    {
        $in = $this->conv->messages()->create([
            'direction' => MessageDirection::In, 'type' => MessageType::Text, 'body' => $body,
            'status' => MessageStatus::Delivered, 'is_from_me' => false, 'wa_timestamp' => now(),
        ]);
        $bot->handle($this->conv->fresh('lead'), $in->fresh());

        return $this->conv->messages()->where('is_from_me', true)->latest('id')->first();
    }

    private function filled(?Message $r): bool
    {
        return $r && trim((string) $r->body) !== '';
    }

    private function queued(string $job): bool
    {
        return Queue::pushed($job)->isNotEmpty();
    }

    /** The reply must never name the AI/LLM behind the bot. */
    private function assertNoAi(string $name, ?Message $r): void
    {
        $body = Str::lower((string) ($r->body ?? ''));
        $leaks = ['claude', 'anthropic', 'chatgpt', 'openai', 'gpt-', 'gpt ', 'modelo de lenguaje', 'language model', 'soy una ia', 'soy un bot', 'inteligencia artificial de'];
        $ok = $this->filled($r);
        foreach ($leaks as $w) {
            if (Str::contains($body, $w)) {
                $ok = false;
            }
        }
        $this->assert($name, $ok);
    }

    private function assert(string $name, bool $ok): void
    {
        $this->line(($ok ? '<info>PASS</info>' : '<error>FAIL</error>').' '.$name);
        $ok ? $this->pass++ : $this->fail++;
    }

    private function cleanup(Lead $lead): void
    {
        try {
            $lead->refresh()->loadMissing('conversations');
            foreach ($lead->conversations as $c) {
                $c->messages()->delete();
                $c->delete();
            }
            Project::where('lead_id', $lead->id)->forceDelete();
            $lead->quotes()->each(fn ($q) => $q->items()->delete());
            $lead->quotes()->delete();
            $lead->specs()->delete();
            $lead->paymentRequests()->delete();
            $lead->forceDelete();
        } catch (\Throwable $e) {
            $this->warn('cleanup: '.Str::limit($e->getMessage(), 120));
        }
    }
}
