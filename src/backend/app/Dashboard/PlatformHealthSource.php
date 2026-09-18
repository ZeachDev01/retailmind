<?php

namespace App\Dashboard;

interface PlatformHealthSource
{
    /** @return array<int, array{name:string,status:string,detail:string,category:string}> */
    public function checks(): array;
}
