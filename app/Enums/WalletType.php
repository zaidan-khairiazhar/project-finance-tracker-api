<?php

namespace App\Enums;

enum WalletType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Ewallet = 'ewallet';
}
