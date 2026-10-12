<?php
require_once __DIR__ . '/support/test_bootstrap.php';
require_once __DIR__ . '/../app/Forecasting/DemandHistoryService.php';

// Contract for Demand Forecast worker output and Store overview seams.
// TDD rule: One slice at a time. Red before green.
// Test exactly 28 target-day values for each eligible product.
function test_forecast_generation_persists_28_days() {
    $pdo = require __DIR__ . '/support/test_db.php';
    
    // Set up test data...
    
    // Execute worker (or service)
    
    // Assert 28 days
}
