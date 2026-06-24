<?php

namespace App\Enums;

enum MessageType: string
{
    case Text = 'text';
    case Image = 'image';
    case Document = 'document';
    case Audio = 'audio';
    case Video = 'video';
    case Sticker = 'sticker';
    case Location = 'location';
    case Contact = 'contact';
    case System = 'system';

    public function isMedia(): bool
    {
        return in_array($this, [self::Image, self::Document, self::Audio, self::Video, self::Sticker], true);
    }

    /** A media message that could plausibly be a payment proof (comprobante). */
    public function couldBeProof(): bool
    {
        return in_array($this, [self::Image, self::Document], true);
    }
}
