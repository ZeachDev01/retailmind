<?php

$minimum = (int)env('GCASH_REFERENCE_MIN_LENGTH', '13');
$maximum = (int)env('GCASH_REFERENCE_MAX_LENGTH', '13');
if ($minimum < 6 || $maximum > 32 || $minimum > $maximum) {
    throw new RuntimeException('GCash reference lengths must be between 6 and 32, with minimum <= maximum.');
}

return ['min_length' => $minimum, 'max_length' => $maximum];
