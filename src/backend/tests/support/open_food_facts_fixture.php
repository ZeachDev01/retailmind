<?php

return static function (string $url): array {
    if (!preg_match('~^https://world\.openfoodfacts\.org/api/v3/product/([0-9]+)\?fields=code,product_name,brands,quantity,categories_tags,image_front_url$~D', $url, $parts)) {
        throw new RuntimeException('Unexpected Open Food Facts request.');
    }

    $code = $parts[1];
    $product = match ($code) {
        '11111115', '22222220', '33333335', '44444440', '55555555' => [
            'code' => $code,
            'product_name' => 'Fixture Tea ' . $code,
            'image_front_url' => 'https://images.openfoodfacts.org/images/products/' . $code . '/front_en.4.400.jpg',
        ],
        '012345678905' => [
            'code' => '0012345678905',
            'product_name' => 'Fixture Tea',
            'brands' => 'Fixture Brand',
            'quantity' => '330 mL',
            'categories_tags' => ['en:teas'],
            'image_front_url' => 'https://images.openfoodfacts.org/images/products/001/234/567/8905/front_en.4.400.jpg',
        ],
        '87654328' => ['code' => $code, 'product_name' => 'Partial Fixture'],
        '23456789' => ['code' => '012345678905', 'product_name' => 'Wrong Barcode'],
        default => null,
    };
    if ($code === '34567890') return [503, 'unavailable'];
    $id = $product === null ? 'product_not_found' : 'product_found';
    return [$product === null ? 404 : 200, json_encode(['result' => ['id' => $id], 'product' => $product])];
};
