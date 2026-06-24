<?php

namespace App\Enums;

enum AgentRunKind: string
{
    case Reply = 'reply';
    case Qualify = 'qualify';
    case Spec = 'spec';
    case Quote = 'quote';
    case Build = 'build';
    case Change = 'change';
}
