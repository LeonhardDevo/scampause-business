<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reports\Contracts\DashboardData;
use App\Models\User;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(Request $request, CurrentWorkspace $current, DashboardData $data): Response
    {
        $workspace = $current->get();
        abort_if($workspace === null, 404);
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Inertia::render('Dashboard', $data->props($workspace, $user));
    }
}
