<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\PaymentProof;
use App\Models\Spec;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Streams user-content files (spec PDFs, payment proofs, WhatsApp media) through
 * the authenticated web routes instead of exposing them via public storage URLs.
 * Works regardless of the underlying disk (local / S3 / MinIO).
 */
class FileController extends Controller
{
    public function spec(Spec $spec): Response
    {
        return $this->stream($spec->pdf_path, 'application/pdf', ($spec->title ?? 'alcance').'.pdf');
    }

    public function proof(PaymentProof $proof): Response
    {
        return $this->stream($proof->file_path, $proof->file_mime ?? 'application/octet-stream', $proof->file_name ?? 'comprobante');
    }

    public function media(Message $message): Response
    {
        return $this->stream($message->media_path, $message->media_mime ?? 'application/octet-stream', 'media');
    }

    private function stream(?string $path, string $mime, string $filename): Response
    {
        abort_unless($path && Storage::exists($path), 404);

        return response(Storage::get($path), 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
