<?php
// config/ml_config.php
// Endpoint of the Python ML microservice (see /legacy/demandForcasting/predict_api.py)

$mlConfig = require __DIR__ . '/machine_learning.php';

if (!defined('ML_API_URL')) {
    define('ML_API_URL', $mlConfig['api_url']);
}
if (!defined('ML_API_KEY')) {
    define('ML_API_KEY', $mlConfig['api_key']);
}
if (!defined('ML_API_TIMEOUT')) {
    define('ML_API_TIMEOUT', $mlConfig['timeout']);
}
if (!defined('ML_API_PREDICT_ENDPOINT')) {
    define('ML_API_PREDICT_ENDPOINT', ML_API_URL . '/predict');
}
if (!defined('ML_API_RETRAIN_ENDPOINT')) {
    define('ML_API_RETRAIN_ENDPOINT', ML_API_URL . '/retrain');
}
