<?php
// Function to initialize and configure cURL
function initCurl($url, $postData = null, $isPost = false) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_COOKIEJAR => 'cookie.txt',
        CURLOPT_COOKIEFILE => 'cookie.txt',
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2
    ]);
    if ($isPost) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
    }
    return $ch;
}

// Function to execute cURL and handle errors
function executeCurl($ch) {
    $response = curl_exec($ch);
    if ($response === false) {
        throw new Exception('cURL error: ' . curl_error($ch));
    }
    return $response;
}

// Function to check for CAPTCHA presence
function detectCaptcha($html) {
    $captchaIndicators = [
        '//iframe[contains(@src, "recaptcha")]',
        '//div[contains(@class, "g-recaptcha")]',
        '//div[contains(@class, "h-captcha")]',
        '//script[contains(@src, "recaptcha")]',
        '//script[contains(@src, "hcaptcha")]',
        '//noscript[contains(text(), "captcha")]'
    ];
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    foreach ($captchaIndicators as $indicator) {
        if ($xpath->query($indicator)->length > 0) {
            return true;
        }
    }
    return false;
}

// Function to extract data using XPath
function extractData($html, $queries) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $data = [];
    foreach ($queries as $query) {
        foreach ($xpath->query($query) as $node) {
            if ($query === '//script[@type="application/ld+json"]') {
                $json = json_decode(trim($node->textContent), true);
                if (isset($json['@type']) && $json['@type'] === 'Product' && isset($json['sku'])) {
                    $data[] = $json['sku'];
                }
            } elseif (preg_match('/add-to-cart=(\d+)/', $node->nodeValue, $matches)) {
                $data[] = $matches[1];
            } else {
                $data[] = $node->nodeValue;
            }
        }
    }
    return array_unique(array_filter($data));
}

// Get the URL from the query parameter
if (!isset($_GET['check']) || empty($_GET['check'])) {
    echo '<pre>Error: No URL provided in query parameter.</pre>';
    exit;
}

$initialUrl = filter_var($_GET['check'], FILTER_VALIDATE_URL);
if ($initialUrl === false) {
    echo '<pre>Error: Invalid URL provided in query parameter.</pre>';
    exit;
}

$parsedUrl = parse_url($initialUrl);
$baseUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];

// Main workflow
$start_time = microtime(true);

try {
    // Fetch initial page
    $ch = initCurl($initialUrl);
    $html = executeCurl($ch);
    curl_close($ch);

    // Detect CAPTCHA on initial page
    $captchaStart = microtime(true);
    $captchaDetected = detectCaptcha($html);
    $captchaEnd = microtime(true);

    echo '<pre>', $captchaDetected ? 'Captcha Detected on Product Page.' : 'No Captcha on Product Page.', '</pre>';

    // Extract product IDs
    $productQueries = [
        '//a[contains(@href, "add-to-cart=")]/@href',
        '//input[@name="add-to-cart" or @name="product_id"]/@value',
        '//*[@data-product_id or @data-product-id]/@data-product_id | //*[@data-product_id or @data-product-id]/@data-product-id',
        '//form[contains(@action, "add-to-cart=")]/@action',
        '//script[@type="application/ld+json"]'
    ];
    $productIDsStart = microtime(true);
    $productIDs = extractData($html, $productQueries);
    $productIDsEnd = microtime(true);
    echo '<pre>Product IDs: ', print_r($productIDs, true), '</pre>';

    if (!empty($productIDs)) {
        $productID = reset($productIDs);

        // Add product to cart
        $addToCartStart = microtime(true);
        $ch = initCurl("$baseUrl/?wc-ajax=add_to_cart", ['product_id' => $productID, 'quantity' => 1], true);
        $response = executeCurl($ch);
        curl_close($ch);
        $addToCartEnd = microtime(true);

        echo '<pre>', $response !== false ? 'Product added to cart successfully.' : 'Failed to add product to cart.', '</pre>';

        if ($response !== false) {
            // Fetch checkout page
            $checkoutPageStart = microtime(true);
            $checkoutUrl = $baseUrl . "/checkout/";
            $ch = initCurl($checkoutUrl);
            $checkoutPage = executeCurl($ch);
            curl_close($ch);
            $checkoutPageEnd = microtime(true);

            if ($checkoutPage === false) {
                echo '<pre>Failed to load checkout page.</pre>';
            } else {
                // Detect CAPTCHA on checkout page
                $checkoutCaptchaStart = microtime(true);
                $checkoutCaptchaDetected = detectCaptcha($checkoutPage);
                $checkoutCaptchaEnd = microtime(true);
                echo '<pre>', $checkoutCaptchaDetected ? 'Captcha Detected on Checkout Page.' : 'No Captcha on Checkout Page.', '</pre>';

                // Extract payment methods
                $paymentMethodsStart = microtime(true);
                $paymentMethods = extractData($checkoutPage, ['//*[@id="payment"]//input[@name="payment_method"]/@value']);
                $paymentMethodsEnd = microtime(true);
                echo '<pre>Payment Methods: ', print_r($paymentMethods, true), '</pre>';
            }
        }
    } else {
        echo '<pre>No product IDs found.</pre>';
    }

    // Calculate total execution time
    $end_time = microtime(true);
    echo 'Execution time: ', round($end_time - $start_time, 4), " seconds\n";
    echo 'Captcha check time: ', round($captchaEnd - $captchaStart, 4), " seconds\n";
    echo 'Product ID extraction time: ', round($productIDsEnd - $productIDsStart, 4), " seconds\n";
    echo 'Add to cart time: ', round($addToCartEnd - $addToCartStart, 4), " seconds\n";
    echo 'Checkout page fetch time: ', round($checkoutPageEnd - $checkoutPageStart, 4), " seconds\n";
    echo 'Checkout CAPTCHA check time: ', round($checkoutCaptchaEnd - $checkoutCaptchaStart, 4), " seconds\n";
    echo 'Payment methods extraction time: ', round($paymentMethodsEnd - $paymentMethodsStart, 4), " seconds\n";

} catch (Exception $e) {
    echo '<pre>Error: ', $e->getMessage(), '</pre>';
}
?>
