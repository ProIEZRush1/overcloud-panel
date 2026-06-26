<?php

namespace App\Console\Commands;

use App\Enums\LeadStage;
use App\Enums\MessageDirection;
use App\Enums\MessageStatus;
use App\Enums\MessageType;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Service;
use App\Models\WhatsAppAccount;
use App\Services\BotResponder;
use App\Services\VisionService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Simulates real client scenarios (the messy ones, not the happy path) against the
 * live bot logic and reports pass/fail. Each scenario gets a fresh throwaway lead.
 */
class TestScenarios extends Command
{
    protected $signature = 'bot:scenarios {--image-path=}';

    protected $description = 'Run real-world client scenarios against the bot';

    public function handle(BotResponder $bot, VisionService $vision): int
    {
        $account = WhatsAppAccount::where('session_name', 'overcloud-bot')->first();
        $website = Service::where('key', 'website')->first() ?? Service::first();
        if (! $account || ! $website) {
            $this->error('missing account/service');

            return 1;
        }

        $pass = 0;
        $fail = 0;

        $cases = [
            ['saludo_sin_precio', LeadStage::New, false, ['Hola, quiero una tienda en línea para vender ropa'],
                // "precios accesibles" (marketing) is fine; flag only a real amount or pushing a quote.
                fn (?Message $r, Lead $l) => [$r && ! preg_match('/\$\s?\d|cuesta|precio de|cotiza[rt]/i', (string) $r->body), 'el saludo no pone precio ni empuja cotización']],
            ['si_procede_alcance', LeadStage::Qualifying, true, ['Sí, va'],
                fn (?Message $r, Lead $l) => [$l->specs()->exists(), 'un "sí" genera el alcance (no pide keyword)']],
            ['cotizacion_literal', LeadStage::Qualifying, true, ['cotización'],
                fn (?Message $r, Lead $l) => [$l->specs()->exists() && ! preg_match('/escribe|palabra/i', (string) $r?->body), '"cotización" literal → alcance, no pide teclear']],
            ['no_improvisa', LeadStage::Qualifying, true, ['Quiero las dos opciones que me cotices con precios'],
                fn (?Message $r, Lead $l) => [$r && ! preg_match('/asesor|te contactar|opción 1|opción 2|\$\d/i', (string) $r->body), 'no inventa cotización/opciones/asesor']],
            ['pago_alternativo', LeadStage::Quoted, true, ['No me acomodan los pagos en partes, ¿puedo pagar todo de una?'],
                fn (?Message $r, Lead $l) => [$r && preg_match('/revis|consider|equipo|confirm/i', (string) $r->body) && ! preg_match('/no podemos|no se puede|lamentablemente no/i', (string) $r->body), 'pago alterno → lo revisa, no rechaza']],
            ['imagen_vision', LeadStage::Qualifying, true, ['__IMAGE__'],
                fn (?Message $r, Lead $l) => [$r && ! preg_match('/no puedo (ver|visualiz)|no logro ver/i', (string) $r->body), 'no dice "no puedo ver la imagen"']],
        ];

        $imagePath = $this->option('image-path')
            ?: Message::where('type', MessageType::Image)->whereNotNull('media_path')->latest()->value('media_path');

        foreach ($cases as [$name, $stage, $withService, $inbounds, $assert]) {
            $lead = Lead::create([
                'name' => 'Test '.$name, 'phone' => '52155'.random_int(1000000, 9999999),
                'stage' => $stage, 'service_id' => $withService ? $website->id : null,
                'company' => 'Boutique Test', 'summary' => 'tienda en línea de ropa con catálogo y pagos',
            ]);
            $conv = \App\Models\Conversation::create([
                'whatsapp_account_id' => $account->id,
                'lead_id' => $lead->id, 'contact_jid' => $lead->phone.'@s.whatsapp.net',
                'contact_phone' => $lead->phone, 'is_group' => false, 'ai_enabled' => true,
            ]);

            try {
                foreach ($inbounds as $body) {
                    $isImage = $body === '__IMAGE__';
                    $in = $conv->messages()->create([
                        'direction' => MessageDirection::In,
                        'type' => $isImage ? MessageType::Image : MessageType::Text,
                        'body' => $isImage ? null : $body,
                        'media_path' => $isImage ? $imagePath : null,
                        'media_mime' => $isImage ? 'image/jpeg' : null,
                        'status' => MessageStatus::Delivered, 'is_from_me' => false, 'wa_timestamp' => now(),
                    ]);
                    if ($isImage) {
                        $vision->describe($in);
                    }
                    $bot->handle($conv->fresh('lead'), $in->fresh());
                }
            } catch (\Throwable $e) {
                $this->warn($name.' threw: '.Str::limit($e->getMessage(), 120));
            }

            $reply = $conv->messages()->where('is_from_me', true)->latest()->first();
            [$ok, $note] = $assert($reply, $lead->fresh());
            $tag = $ok ? '<info>PASS</info>' : '<error>FAIL</error>';
            $this->line($tag.' '.str_pad($name, 20).' '.$note);
            $this->line('     reply: '.($reply ? Str::limit(str_replace("\n", ' ', $reply->body), 110) : 'NINGUNA'));
            $ok ? $pass++ : $fail++;

            $lead->delete();
        }

        $this->line("\n<comment>{$pass} PASS · {$fail} FAIL</comment>");

        return $fail === 0 ? 0 : 1;
    }
}
