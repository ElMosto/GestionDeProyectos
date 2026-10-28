<?php

declare(strict_types=1);

// §12 / §19.E: reuse phase-3's READY/GO/DISPATCH worker and strict gestion_test guard.
// It already accepts method/path/body; no second implementation or alternative database.
require __DIR__ . '/financial-worker.php';
