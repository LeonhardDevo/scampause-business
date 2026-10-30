<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final class UserDeleting
{
    use Dispatchable;

    public function __construct(public readonly User $user) {}
}
