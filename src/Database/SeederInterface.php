<?php

declare(strict_types=1);

namespace Nora\Database;

use PDO;

interface SeederInterface
{
    public function run(PDO $pdo): void;
}