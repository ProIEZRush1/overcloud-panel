<?php

namespace App\Enums;

enum QuoteItemType: string
{
    case Service = 'service';
    case Feature = 'feature';
    case Maintenance = 'maintenance';
    case Discount = 'discount';
    case Custom = 'custom';
}
