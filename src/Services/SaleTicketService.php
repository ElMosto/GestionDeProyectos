<?php

declare(strict_types=1);

namespace App\Services;

use App\Policies\Authorizer;
use App\Repositories\StudioRepository;

final class SaleTicketService
{
    public function __construct(
        private readonly SaleService $sales,
        private readonly StudioRepository $studio,
        private readonly Authorizer $authorizer,
    ) {
    }

    public function data(int $id): array
    {
        $data = $this->sales->ticketData($id);
        $data['studio'] = $this->studio->ticketData();
        $data['user_timezone'] = $this->authorizer->requireUser()['timezone'] ?? $data['studio']['timezone'];
        return $data;
    }
}
