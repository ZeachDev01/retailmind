<?php

namespace App\Core;

use PDO;

abstract class Model
{
    protected PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?: Database::connection();
    }
}
