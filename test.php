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

// Function to extract product IDs using DOMDocument and XPath
function extractProductIDs($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $productIDs = [];

    $queries = [
        '//a[contains(@href, "add-to-cart=")]/@href',
        '//input[@name="add-to-cart" or @name="product_id"]/@value',
        '//*[@data-product_id or @data-product-id]/@data-product_id | //*[@data-product_id or @data-product-id]/@data-product-id',
        '//form[contains(@action, "add-to-cart=")]/@action',
        '//script[@type="application/ld+json"]'
    ];

    foreach ($queries as $query) {
        foreach ($xpath->query($query) as $node) {
            if ($query === '//script[@type="application/ld+json"]') {
                $data = json_decode(trim($node->textContent), true);
                if (isset($data['@type']) && $data['@type'] === 'Product' && isset($data['sku'])) {
                    $productIDs[] = $data['sku'];
                }
            } elseif (preg_match('/add-to-cart=(\d+)/', $node->nodeValue, $matches)) {
                $productIDs[] = $matches[1];
            } else {
                $productIDs[] = trim($node->nodeValue);
            }
        }
    }

    return array_unique(array_filter($productIDs));
}

// Function to extract payment methods from the checkout page
function extractPaymentMethods($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $queries = [
        '//*[@id="payment"]//ul[contains(@class, "wc_payment_methods")]//input[@type="radio"]/@value',
        '//*[@id="payment"]//input[@type="radio" and @name="payment_method"]/@value',
        '//*[contains(@id, "payment")]//input[@type="radio" and @name="payment_method"]/@value',
        '//input[@type="radio" and @name="payment_method"]/@value',
        
        '//*[@id="payment"]//input[contains(@name, "payment") and @type="radio"]/@value',
        '//*[contains(@id, "payment")]//input[contains(@name, "payment") and @type="radio"]/@value',
        '//input[contains(@name, "payment") and @type="radio"]/@value',
        '//*[contains(@id, "payment")]//input[contains(@name, "method") and @type="radio"]/@value',
        
        '//*[contains(@class, "wc_payment_methods")]//input[@type="radio" and contains(@name, "payment")]/@value',
        '//*[@id="payment"]//div[contains(@class, "payment_method")]//input[@type="radio"]/@value',
        '//*[contains(@class, "woocommerce-checkout-payment")]//input[@type="radio" and @name]/@value',
        '//*[contains(@class, "woocommerce-payment-methods")]//input[@type="radio" and @name]/@value',
        
        '//input[@type="radio" and contains(@id, "payment_method")]/@value',
        '//input[@type="radio" and contains(@name, "payment")]/@value',
        '//input[@type="radio" and contains(@name, "method")]/@value',
        '//input[@type="radio" and contains(@class, "payment")]/@value',
        '//input[@type="radio" and contains(@class, "method")]/@value'
    ];

    $methods = [];
    foreach ($queries as $query) {
        foreach ($xpath->query($query) as $method) {
            $value = trim($method->nodeValue);
            if ($value && !in_array($value, ['new', 'true'])) {
                $methods[] = $value;
            }
        }
    }

    return array_values(array_unique($methods));
}

// Function to detect CAPTCHA on a webpage
function detectCaptcha($html) {
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);

    $captchaIndicators = [
        '//iframe[contains(@src, "recaptcha")]',
        '//div[contains(@class, "g-recaptcha")]',
        '//div[contains(@class, "h-captcha")]',
        '//script[contains(@src, "recaptcha")]',
        '//script[contains(@src, "hcaptcha")]',
        '//noscript[contains(text(), "captcha")]',
        '//input[@name="g-recaptcha-response"]',
        '//input[@name="h-captcha-response"]',
        '//div[@id="px-captcha"]',
        '//div[contains(@class, "captcha")]',
        '//input[contains(@id, "captcha")]',
        '//div[contains(@class, "cf-captcha-container")]',
        '//input[@type="hidden" and @name="cf-turnstile-response"]',
        '//input[@type="hidden" and @name="captcha"]',
    ];

    foreach ($captchaIndicators as $indicator) {
        if ($xpath->query($indicator)->length > 0) {
            return true;
        }
    }

    return false;
}

// Function to add a product to the cart by sending a POST request
function addProductToCart($initialUrl, $fullUrl, $productID) {
    // AJAX request URL
    $ajaxUrl = "$fullUrl/?wc-ajax=add_to_cart";
    $postData = ['product_id' => $productID, 'quantity' => 1];

    // Initialize cURL for AJAX request
    $ch = initCurl($ajaxUrl, $postData, true);
    $response = executeCurl($ch);
    curl_close($ch);

    // Fallback to standard request if AJAX fails
    if ($response === false || strpos($response, 'cart') === false) {
        $standardUrl = "$initialUrl/?add-to-cart=$productID";
        $postData = ['add-to-cart' => $productID];

        // Initialize cURL for standard request
        $ch = initCurl($standardUrl, $postData, true);
        $response = executeCurl($ch);
        curl_close($ch);
    }

    return $response;
}

// Access the checkout page to scrape payment methods
function getCheckoutPage($checkoutUrl) {
    $ch = initCurl($checkoutUrl);
    $html = executeCurl($ch);
    curl_close($ch);

    if ($html === false) {
        return false;
    }

    return $html;
}

// Main workflow
$checkUrl = isset($_GET['check']) ? $_GET['check'] : die('No check URL provided');
$parsedUrl = parse_url($checkUrl);
$fullUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
$initialUrl = rtrim($checkUrl, '/');

$ch = initCurl($checkUrl);
$html = executeCurl($ch);
curl_close($ch);

$response = [];

$response['captcha'] = detectCaptcha($html) ? 'yes' : 'no';

$productIDs = extractProductIDs($html);
$response['productid'] = $productIDs;

if (empty($productIDs)) {
    $pagesToTry = [
        "$fullUrl/shop/", "$fullUrl/product-category/", "$fullUrl/category/", "$fullUrl/products/",
        "$fullUrl/store/", "$fullUrl/collections/", "$fullUrl/items/", "$fullUrl/catalog/",
        "$fullUrl/products-page/", "$fullUrl/product/", "$fullUrl/our-products/", "$fullUrl/shop-all/",
        "$fullUrl/shop-by-category/", "$fullUrl/all-products/", "$fullUrl/product-list/", "$fullUrl/sale/",
        "$fullUrl/new-arrivals/", "$fullUrl/top-rated/", "$fullUrl/best-sellers/", "$fullUrl/featured/",
        "$fullUrl/brands/", "$fullUrl/vendors/", "$fullUrl/promotions/", "$fullUrl/deals/",
        "$fullUrl/discounts/", "$fullUrl/offers/", "$fullUrl/collections/all/", "$fullUrl/our-range/",
        "$fullUrl/exclusive/", "$fullUrl/seasonal/", "$fullUrl/limited-edition/", "$fullUrl/special-edition/",
        "$fullUrl/catalogue/", "$fullUrl/shop-now/", "$fullUrl/shop-by-brand/", "$fullUrl/shop-by-type/",
        "$fullUrl/shop-by-price/", "$fullUrl/clearance/", "$fullUrl/outlet/", "$fullUrl/promo-items/"
    ];

    foreach ($pagesToTry as $pageUrl) {
        $ch = initCurl($pageUrl);
        $html = executeCurl($ch);
        curl_close($ch);

        $productIDs = extractProductIDs($html);
        if (!empty($productIDs)) {
            $response['productid'] = $productIDs;
            break;
        }
    }
}

if (!empty($productIDs)) {
    $productID = reset($productIDs);
    $cartResponse = addProductToCart($initialUrl, $fullUrl, $productID);

    if ($cartResponse !== false) {
        $checkoutUrl = "$fullUrl/checkout/";
        $checkoutPage = getCheckoutPage($checkoutUrl);

        if ($checkoutPage !== false) {
            $response['captcha'] = detectCaptcha($checkoutPage) ? 'yes' : 'no';
            $paymentMethods = extractPaymentMethods($checkoutPage);
            $response['paymentmethod'] = $paymentMethods;
        }
    }
}

// Output the response as JSON
header('Content-Type: application/json');
echo json_encode($response);
?>
