<?php

declare(strict_types=1);

namespace App\Privacy;

use App\Models\Workspace;

interface ExportAttachmentContributor
{
    /** @return iterable<string, string> */
    public function workspaceAttachments(Workspace $workspace): iterable;
}
