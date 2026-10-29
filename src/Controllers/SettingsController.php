<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\SettingsService;
use App\Services\ProjectAudit;
use App\Validation\SettingsInput;

final class SettingsController
{
    public function __construct(private readonly SettingsService $service, private readonly ProjectAudit $audit)
    {
    }

    public function show(Request $request): Response
    {
        return Response::data($this->service->show());
    }

    public function update(Request $request): Response
    {
        $this->audit->bind($request);
        return Response::data($this->service->updateSettings(SettingsInput::fields($request)));
    }
}
