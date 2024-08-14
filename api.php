<?php

function run($url, $options = [], $cookieJar = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTPHEADER => array_merge(
            [
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/114.0.0.0 Safari/537.36',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
                'Accept-Language: en-US,en;q=0.9',
                'Connection: keep-alive'
            ],
            $options['httpheader'] ?? []
        )
    ]);
    
    if ($cookieJar) {
        curl_setopt_array($ch, [
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_COOKIEJAR => $cookieJar
        ]);
    }
    
    if (isset($options['postfields'])) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options['postfields']);
    }
    
    $result = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return json_encode(['status' => 'error', 'message' => $error]);
    }
    
    return $result;
}

function extractProductID($html) {
    if (preg_match('/(?:\?add-to-cart|<input type="hidden" name="add-to-cart" value=")(\d+)/', $html, $matches)) {
        return $matches[1];
    }
    return null;
}

function extractProductIDsFromCategory($html) {
    preg_match_all('/\?add-to-cart=(\d+)/', $html, $matches);
    return array_unique($matches[1]);
}

function extractPaymentMethodsFromHtml($html) {
    $patterns = [
        '/class="wc_payment_method\s+payment_method_([^"\s]+)"/i',
        '/id="payment_method_([^"\s]+)"/i',
        '/name="payment_method"[^>]*value="([^"\s]+)"/i'
    ];
    
    $paymentMethods = [];
    foreach ($patterns as $pattern) {
        preg_match_all($pattern, $html, $matches);
        $paymentMethods = array_merge($paymentMethods, array_unique($matches[1]));
    }
    
    return array_unique($paymentMethods);
}

function findProductIDOnSite($baseUrl, $initialUrl) {
    $pagesToTry = [
        "$baseUrl/shop/", "$baseUrl/product-category/", "$baseUrl/category/", "$baseUrl/products/",
        "$baseUrl/store/", "$baseUrl/collections/", "$baseUrl/items/", "$baseUrl/catalog/",
        "$baseUrl/products-page/", "$baseUrl/product/", "$baseUrl/our-products/", "$baseUrl/shop-all/",
        "$baseUrl/shop-by-category/", "$baseUrl/all-products/", "$baseUrl/product-list/", "$baseUrl/sale/",
        "$baseUrl/new-arrivals/", "$baseUrl/top-rated/", "$baseUrl/best-sellers/", "$baseUrl/featured/",
        "$baseUrl/brands/", "$baseUrl/vendors/", "$baseUrl/promotions/", "$baseUrl/deals/",
        "$baseUrl/discounts/", "$baseUrl/offers/", "$baseUrl/collections/all/", "$baseUrl/our-range/",
        "$baseUrl/exclusive/", "$baseUrl/seasonal/", "$baseUrl/limited-edition/", "$baseUrl/special-edition/",
        "$baseUrl/catalogue/", "$baseUrl/shop-now/", "$baseUrl/shop-by-brand/", "$baseUrl/shop-by-type/",
        "$baseUrl/shop-by-price/", "$baseUrl/clearance/", "$baseUrl/outlet/", "$baseUrl/promo-items/"
    ];

    $page = run($initialUrl);
    if ($page && ($productID = extractProductID($page))) {
        return $productID;
    }

    foreach ($pagesToTry as $url) {
        $page = run($url);
        if ($page && ($productIDs = extractProductIDsFromCategory($page))) {
            return $productIDs[0];
        }
    }

    return null;
}

function processUrl($initialUrl) {
    $parsedUrl = parse_url($initialUrl);
    $baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

    $cookieJar = tempnam(sys_get_temp_dir(), 'cookie');

    $productID = findProductIDOnSite($baseUrl, $initialUrl);
    if ($productID) {
        $addToCartUrl = "$initialUrl?add-to-cart=$productID";
        
        $addToCartPage = run($addToCartUrl, [], $cookieJar);
        
        if ($addToCartPage && strpos($addToCartPage, 'added_to_cart') !== false) {
        } else {
            $ajaxAddToCartUrl = "$baseUrl/?wc-ajax=add_to_cart";
            $addToCartResponse = run($ajaxAddToCartUrl, [
                'postfields' => http_build_query([
                    'product_id' => $productID,
                    'quantity' => 1
                ]),
                'httpheader' => [
                    'Accept: application/json, text/javascript, */*; q=0.01',
                    'Content-Type: application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With: XMLHttpRequest'
                ]
            ], $cookieJar);
            
            if (!$addToCartResponse) {
                return json_encode(['status' => 'error', 'message' => 'Failed to add product.']);
            }
            $addToCartData = json_decode($addToCartResponse, true);
            if (!isset($addToCartData['fragments'])) {
                return json_encode(['status' => 'error', 'message' => 'Failed to add product.']);
            }
        }
        
        $checkoutPage = run("$baseUrl/checkout/", [], $cookieJar);
        
        $captchaPatterns = [
            'g-recaptcha', 'h-captcha', 'captcha', 'recaptcha', 'g-captcha',
            'recaptcha.js', 'hcaptcha.com', 'captcha.com'
        ];

        $captchaDetected = false;
        foreach ($captchaPatterns as $pattern) {
            if (strpos($checkoutPage, $pattern) !== false) {
                $captchaDetected = true;
                break;
            }
        }
        
        $captchaStatus = $captchaDetected ? "YES" : "NO";
        
        $paymentMethods = extractPaymentMethodsFromHtml($checkoutPage);
        $paymentMethodsStatus = !empty($paymentMethods) ? implode(', ', $paymentMethods) : "No payment methods found.";
        
        unlink($cookieJar);
        return json_encode([
            'status' => 'success',
            'url' => $initialUrl,
            'paymentMethods' => $paymentMethodsStatus,
            'captcha' => $captchaStatus
        ]);
    } else {
        return json_encode([
            'status' => 'no_product_id',
            'url' => $initialUrl,
            'captcha' => 'NO'
        ]);
    }
}

// Check if URL is provided
if (isset($_POST['url'])) {
    header('Content-Type: application/json');
    echo processUrl(trim($_POST['url']));
}
?>
