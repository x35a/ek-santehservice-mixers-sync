<?php
declare(strict_types=1);

// Return associative array with configuration values.
// Fill in the required credentials below.
return [
    // Required – базовий URL вашого сайту WooCommerce (наприклад, https://example.com)
    'WC_SITE_URL' => '',

    // Required – WooCommerce Consumer Key (починається з ck_)
    'WC_API_USERNAME' => '',

    // Required – WooCommerce Consumer Secret (починається з cs_)
    'WC_API_PASSWORD' => '',

    // Optional – кількість товарів на сторінці (1..100). За замовчуванням: 100
    'WC_PER_PAGE' => 100,

    // Optional – форсувати авторизацію через query params навіть на HTTPS (1 увімкнути).
    // За замовчуванням: автоматично вмикається лише для HTTP
    // 'WC_QUERY_STRING_AUTH' => '1',

    // Optional – повний шлях до конкретного лог-файлу (перекриває директорію та автогенерацію імені)
    // 'WC_LOG_FILE' => '',

    // Optional – директорія для логів. За замовчуванням: ./logs поруч зі скриптами
    // 'WC_LOG_DIR' => '',

    // Optional – додавати у кожен рядок логу ідентифікатор запуску (1 увімкнути). За замовчуванням: вимкнено
    // 'WC_LOG_INCLUDE_RUN_ID' => '1',

    // Optional – додавати у кожен рядок логу назву скрипта (1 увімкнути). За замовчуванням: вимкнено
    // 'WC_LOG_INCLUDE_SCRIPT' => '1',

    // Optional – email для отримання сповіщень у разі помилок або нестандартної роботи
    // Залиште порожнім, щоб вимкнути email-сповіщення
    'ALERT_EMAIL_TO' => '',

    // Optional – ім'я відправника для листів-сповіщень (щоб підвищити шанси не потрапити у спам)
    'ALERT_EMAIL_FROM_NAME' => '',

    // Optional – email-адреса відправника. Якщо не вказано, буде використано no-reply@<домен_сайту>
    // 'ALERT_EMAIL_FROM_EMAIL' => 'noreply@ekoplast.org',

    // Santehservice XML feed URL (set the real URL here in your config.php)
    'SANTEHSERVICE_XML_URL' => '',
];


