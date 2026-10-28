<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\NotificationService;
use App\Validation\MeetingInput;
use App\Validation\ProjectInput;

final class NotificationsController
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function index(Request $request): Response
    {
        $page = $this->notifications->list(MeetingInput::notificationQuery($request->query));
        return Response::data($page['data'], $page['meta']);
    }

    public function read(Request $request): Response
    {
        ProjectInput::fields($request->body, []);
        return Response::data($this->notifications->read(ProjectInput::id($request->param('id'))));
    }
}
