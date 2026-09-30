<?php

use App\Database\Schema;

return [
    'key' => '202609300005_register_paper_width',
    'description' => 'Configure thermal receipt paper width per Register',
    'up' => static function (PDO $pdo): void {
        Schema::addColumnIfMissing($pdo, 'registers', 'paper_width_mm', "ENUM('80','58') NOT NULL DEFAULT '80'");
    },
];
