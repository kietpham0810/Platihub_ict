<?php
// api/lib/anphatpc_crawler.php
// Logic cào dữ liệu An Phát PC dùng chung giữa endpoint HTTP (bot_sync_anphatpc.php,
// admin dán link cào thủ công) và script CLI tự động (cli/auto_crawl_anphat.php,
// chạy theo lịch mỗi ngày). Tách ra đây để không lặp code giữa 2 nơi gọi.

// CHỐNG SSRF: chỉ chấp nhận URL thuộc đúng domain An Phát PC (kể cả subdomain
// www). Trước đây $target_url do client gửi lên được dùng thẳng cho curl mà
// không kiểm tra, cho phép ép server fetch bất kỳ URL nào (mạng nội bộ,
// localhost, endpoint metadata cloud...).
function is_allowed_crawl_host(string $url): bool {
    $host = parse_url($url, PHP_URL_HOST);
    if (!$host) return false;
    $host = strtolower($host);
    return $host === 'anphatpc.com.vn' || $host === 'www.anphatpc.com.vn';
}

// CHỐNG SSRF (lớp 2): áp dụng cho MỌI url trước khi cURL fetch, kể cả những
// url được phát hiện gián tiếp trong lúc cào (ảnh, link nội bộ trang, hoặc
// địa chỉ mà server bị redirect tới) - không chỉ url gốc client gửi lên.
// Chỉ cho scheme http/https và IP đã resolve KHÔNG được là địa chỉ riêng tư/
// loopback/link-local (chặn cả trường hợp domain trỏ hoặc bị redirect vào
// 127.0.0.1, 169.254.169.254 - cloud metadata, mạng LAN nội bộ...).
function is_safe_public_url($url): bool {
    if (!is_string($url) || $url === '') return false;
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) return false;
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return false;

    $host = $parts['host'];
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);
    if ($ip === $host && !filter_var($host, FILTER_VALIDATE_IP)) {
        // gethostbyname không resolve được (trả nguyên hostname khi lỗi)
        return false;
    }

    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
}

// Kiểm tra AN TOÀN trước khi fetch: chặn cả trường hợp bị 3xx redirect sang
// host không an toàn (curl_getinfo EFFECTIVE_URL phản ánh URL cuối cùng sau
// khi đã follow redirect).
function curl_result_is_from_safe_url($ch): bool {
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    return $effectiveUrl ? is_safe_public_url($effectiveUrl) : false;
}

// HÀM LẤY HTML CHỐNG BLOCK
function fetchHTML($url) {
    if (!is_safe_public_url($url)) {
        error_log('[SSRF blocked] fetchHTML từ chối url không an toàn: ' . $url);
        return false;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

    // VŨ KHÍ MỚI: Yêu cầu server trả về file nén GZIP/Deflate để tăng tốc độ tải lên x5 lần
    curl_setopt($ch, CURLOPT_ENCODING, "");

    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
        "Accept-Language: vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
    ]);

    // Nới lỏng sức chịu đựng lên 45 giây để vượt qua các trang nặng
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);

    $html = curl_exec($ch);

    // Bắt lỗi thô để dễ debug nếu tiếp tục tịt ngòi
    if (curl_errno($ch)) {
        error_log('Lỗi cURL: ' . curl_error($ch));
    }

    // Chặn SSRF qua redirect: nếu server bị 3xx dẫn sang IP nội bộ/loopback,
    // loại bỏ kết quả thay vì trả về nội dung đã fetch được.
    if ($html !== false && !curl_result_is_from_safe_url($ch)) {
        error_log('[SSRF blocked] fetchHTML bị redirect sang url không an toàn: ' . $url);
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    return $html;
}

// Tải nhiều URL SONG SONG (curl_multi) thay vì tuần tự - dùng cho bước quét
// nhiều trang chi tiết sản phẩm cùng lúc khi cào theo bộ lọc, giảm đáng kể
// thời gian chờ khi phải quét qua nhiều sản phẩm không khớp bộ lọc.
// Trả về mảng [url => html|false].
function fetchHTMLMulti(array $urls) {
    if (empty($urls)) {
        return [];
    }

    $mh = curl_multi_init();
    $handles = [];
    $results = [];

    foreach ($urls as $url) {
        if (!is_safe_public_url($url)) {
            error_log('[SSRF blocked] fetchHTMLMulti từ chối url không an toàn: ' . $url);
            $results[$url] = false;
            continue;
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_ENCODING, "");
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
            "Accept-Language: vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7",
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_multi_add_handle($mh, $ch);
        $handles[$url] = $ch;
    }

    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    foreach ($handles as $url => $ch) {
        $content = curl_multi_getcontent($ch);
        // Chặn SSRF qua redirect: nếu bị dẫn sang host không an toàn, coi như fetch lỗi.
        $results[$url] = ($content !== null && $content !== '' && curl_result_is_from_safe_url($ch))
            ? $content
            : false;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);

    return $results;
}

function fetchUrl($url, $headers = []) {
    if (!is_safe_public_url($url)) {
        error_log('[SSRF blocked] fetchUrl từ chối url không an toàn: ' . $url);
        return false;
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
        "Accept: */*",
        "Accept-Language: vi-VN,vi;q=0.9,en-US;q=0.8,en;q=0.7",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
    ], $headers));
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    $result = curl_exec($ch);

    if ($result !== false && !curl_result_is_from_safe_url($ch)) {
        error_log('[SSRF blocked] fetchUrl bị redirect sang url không an toàn: ' . $url);
        curl_close($ch);
        return false;
    }

    curl_close($ch);
    return $result;
}

function fetchJSON($url, $headers = []) {
    $result = fetchUrl($url, $headers);
    if ($result === false || $result === null) {
        return null;
    }
    $json = json_decode($result, true);
    return is_array($json) ? $json : null;
}

function resolveAbsoluteUrl($url, $baseUrl) {
    $url = trim($url);
    if (empty($url)) {
        return '';
    }
    if (strpos($url, '//') === 0) {
        return 'https:' . $url;
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    $baseParts = parse_url($baseUrl);
    if (empty($baseParts['scheme']) || empty($baseParts['host'])) {
        return $url;
    }
    $scheme = $baseParts['scheme'];
    $host = $baseParts['host'];
    $port = isset($baseParts['port']) ? ':' . $baseParts['port'] : '';

    if (strpos($url, '/') === 0) {
        return "$scheme://$host$port" . $url;
    }

    $path = isset($baseParts['path']) ? $baseParts['path'] : '/';
    $dir = preg_replace('#/[^/]*$#', '/', $path);
    return "$scheme://$host$port" . rtrim($dir, '/') . '/' . ltrim($url, '/');
}

function parseAnPhatCategoryParams($html) {
    $category = '';
    $collection = '';
    $show = 30;

    // Cập nhật regex: tham số category đầu tiên có thể là số (ID) hoặc chữ (slug).
    // Ví dụ: show_more_product('1858', 'pcap-gaming') hoặc show_more_product('pcap-gaming', '')
    if (preg_match('/show_more_product\(\s*[\'"]([^"\']+)[\'"]\s*,\s*[\'"]([^"\']*)[\'"]\s*\)/i', $html, $matches)) {
        $category = trim($matches[1]);
        $collection = trim($matches[2]);
    }

    if (preg_match('/Module\s*=\s*\{[^}]*\bid\s*:\s*[\'"](\d+)[\'"]/i', $html, $matches) && $category === '') {
        $category = trim($matches[1]);
    }

    if (preg_match('/const\s+CATEGORY_ID\s*=\s*[\'"](\d+)[\'"]/i', $html, $matches) && $category === '') {
        $category = trim($matches[1]);
    }

    if (preg_match('/product_per_page\s*=\s*(\d+)/i', $html, $matches)) {
        $show = intval($matches[1]);
        if ($show <= 0) {
            $show = 30;
        }
    }

    return [
        'category' => $category,
        'collection' => $collection,
        'show' => $show
    ];
}

function buildAnPhatCategoryApiUrl($baseUrl, $category, $collection, $show, $page) {
    $baseParts = parse_url($baseUrl);
    if (empty($baseParts['scheme']) || empty($baseParts['host'])) {
        return '';
    }

    $baseHost = $baseParts['scheme'] . '://' . $baseParts['host'];
    return $baseHost . '/ajax/get_json.php?action=product&action_type=product-list&category=' . urlencode($category) . '&collection=' . urlencode($collection) . '&sort=order&show=' . intval($show) . '&page=' . intval($page);
}

function fetchAnPhatCategoryProductLinks($targetUrl, $html, $offset, $batchSize, &$totalLinks, &$apiImageMap) {
    $apiImageMap = [];
    $params = parseAnPhatCategoryParams($html);
    if (empty($params['category'])) {
        return [];
    }

    $perPage = max(5, intval($params['show']));
    $startPage = intdiv($offset, $perPage) + 1;
    $pageOffset = $offset % $perPage;
    $currentPage = $startPage;
    $required = $batchSize;
    $links = [];
    $totalLinks = 0;

    while ($required > 0) {
        $apiUrl = buildAnPhatCategoryApiUrl($targetUrl, $params['category'], $params['collection'], $perPage, $currentPage);
        if (empty($apiUrl)) {
            break;
        }

        $json = fetchJSON($apiUrl, [
            'X-Requested-With: XMLHttpRequest',
            'Referer: ' . $targetUrl
        ]);

        if (!is_array($json) || empty($json['list']) || !is_array($json['list'])) {
            break;
        }

        if ($totalLinks === 0 && isset($json['total'])) {
            $totalLinks = intval($json['total']);
        }

        $items = $json['list'];
        if ($currentPage === $startPage && $pageOffset > 0) {
            $items = array_slice($items, $pageOffset);
        }

        foreach ($items as $item) {
            if ($required <= 0) {
                break;
            }
            $href = '';
            if (!empty($item['productUrl'])) {
                $href = trim($item['productUrl']);
            } elseif (!empty($item['url'])) {
                $href = trim($item['url']);
            }

            if ($href === '') {
                continue;
            }

            $href = resolveAbsoluteUrl($href, $targetUrl);
            $links[] = $href;
            if (!empty($item['productImage']['large'])) {
                $apiImageMap[$href] = normalizeImageUrl($item['productImage']['large']);
            } elseif (!empty($item['productImage'])) {
                $apiImageMap[$href] = normalizeImageUrl($item['productImage']);
            }

            $required--;
        }

        if ($required <= 0) {
            break;
        }

        if (count($items) < ($perPage - ($currentPage === $startPage ? $pageOffset : 0))) {
            break;
        }

        $currentPage++;
        if ($totalLinks > 0) {
            $maxPages = (int) ceil($totalLinks / $perPage);
            if ($currentPage > $maxPages) {
                break;
            }
        }
    }

    return array_unique($links);
}

function extractImageUrlFromNode($node, $baseUrl) {
    $attrs = ['content', 'data-src', 'data-original', 'data-lazy-src', 'data-lazy', 'data-srcset', 'srcset', 'src'];
    foreach ($attrs as $attr) {
        if (!$node->hasAttribute($attr)) {
            continue;
        }
        $value = trim($node->getAttribute($attr));
        if ($value === '') {
            continue;
        }
        if (stripos($value, 'data:image') === 0) {
            continue;
        }
        if ($attr === 'srcset' || $attr === 'data-srcset') {
            $parts = preg_split('/\s*,\s*/', $value);
            foreach ($parts as $part) {
                $candidate = trim(explode(' ', $part)[0]);
                if ($candidate !== '') {
                    $value = $candidate;
                    break;
                }
            }
        }
        return resolveAbsoluteUrl($value, $baseUrl);
    }
    return '';
}

function findProductImageUrl($xpath, $baseUrl) {
    $queries = [
        "//meta[@property='og:image']",
        "//meta[@property='og:image:secure_url']",
        "//meta[@name='twitter:image']",
        "//meta[@name='og:image']",
        "//div[contains(@class, 'img-large')]//img", // An Phat
        "//div[contains(@class, 'product-image')]//img", // Hoang Ha
        "//div[contains(@class, 'gallery')]//img",
        "//div[contains(@class, 'thumb')]//img",
        "//figure//img",
        "//picture//img",
        "//img[contains(@class, 'p-image') or contains(@class, 'product-image') or contains(@class, 'p-picture') or contains(@class, 'product-gallery') or contains(@class, 'p-thumb') or contains(@class, 'slider') or contains(@class, 'lazy') or contains(@class, 'thumbnail')]",
        "//img"
    ];

    foreach ($queries as $query) {
        $nodes = $xpath->query($query);
        foreach ($nodes as $node) {
            $image = extractImageUrlFromNode($node, $baseUrl);
            if (!empty($image)) {
                return $image;
            }
        }
    }

    $styleNodes = $xpath->query("//*[contains(@style, 'background-image')]");
    foreach ($styleNodes as $node) {
        $style = $node->getAttribute('style');
        if (preg_match('/background-image\s*:\s*url\(([^)]+)\)/i', $style, $m)) {
            $url = trim($m[1], "'\" ");
            if ($url !== '' && stripos($url, 'data:image') !== 0) {
                return resolveAbsoluteUrl($url, $baseUrl);
            }
        }
    }

    return '';
}

function normalizeTextValue($text) {
    $value = trim(preg_replace('/\s+/u', ' ', $text));
    return $value;
}

// Bỏ dấu tiếng Việt một cách xác định (không phụ thuộc locale như iconv//TRANSLIT)
function removeVietnameseTones($str) {
    $map = [
        'à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ',
        'è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ',
        'ì','í','ị','ỉ','ĩ',
        'ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
        'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ',
        'ỳ','ý','ỵ','ỷ','ỹ','đ'
    ];
    $repl = [
        'a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
        'e','e','e','e','e','e','e','e','e','e','e',
        'i','i','i','i','i',
        'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
        'u','u','u','u','u','u','u','u','u','u','u',
        'y','y','y','y','y','d'
    ];
    $str = mb_strtolower($str, 'UTF-8');
    return str_replace($map, $repl, $str);
}

// Phân loại sản phẩm vào đúng danh mục FE (PC, Laptop, CPU, Mainboard, VGA,
// Linh kiện, Màn hình, HDD-SSD, Tản Nhiệt) dựa trên link danh mục đang cào và
// tên sản phẩm. Trả về giá trị product_type khớp với bộ lọc ở frontend.
function classifyAnPhatProductType($productName, $targetUrl) {
    $urlKey = removeVietnameseTones((string) $targetUrl);
    $nameKey = ' ' . removeVietnameseTones((string) $productName) . ' ';

    if ((strpos($urlKey, 'laptop') !== false || strpos($urlKey, 'macbook') !== false) && strpos($urlKey, 'phu-kien') === false && strpos($urlKey, 'linh-kien') === false) {
        $isAccessoryUrl = false;
        $urlComponentKeywords = ['de-tan-nhiet', 'tan-nhiet', 'caddy', 'gia-do', 'adapter', 'pin', 'sac'];
        foreach ($urlComponentKeywords as $keyword) {
            if (strpos($urlKey, $keyword) !== false) {
                $isAccessoryUrl = true;
                break;
            }
        }
        if (!$isAccessoryUrl) {
            return 'Laptop';
        }
    }
    if (strpos($urlKey, 'vga-') !== false) {
        return 'VGA';
    }
    if (strpos($urlKey, 'man-hinh-may-tinh') !== false) {
        return 'Màn hình';
    }
    if (strpos($urlKey, 'tai-nghe') !== false) {
        return 'Tai nghe';
    }
    if (strpos($urlKey, 'cooling-tan-nhiet') !== false) {
        return 'Tản Nhiệt';
    }
    if (strpos($urlKey, 'o-cung-ssd') !== false || strpos($urlKey, 'o-cung-desktop') !== false) {
        return 'HDD-SSD';
    }
    if (strpos($urlKey, 'bo-mach-chu') !== false) {
        return 'Mainboard';
    }
    if (strpos($urlKey, 'cpu-bo-vi-xu-ly') !== false) {
        return 'CPU';
    }
    if (strpos($urlKey, 'pc-') !== false || strpos($urlKey, 'may-tinh-dong-bo') !== false || strpos($urlKey, 'pcap-') !== false || strpos($urlKey, 'workstation') !== false) {
        return 'PC';
    }

    if (strpos($nameKey, ' laptop ') !== false || strpos($nameKey, ' macbook ') !== false) {
        $componentKeywords = [
            ' ram ', ' ssd ', ' hdd ', ' o cung ', ' pin ', ' sac ', ' adapter ', ' cap ', ' ban phim ', ' man hinh ', ' tan nhiet ', ' cpu ', ' vga ', ' mainboard ',
            ' gia do ', ' de tan ', ' de dung ', ' tui chong soc ', ' tui ', ' cap chuyen ', ' hub ', ' kep ',
            ' caddy '
        ];
        $isComponent = false;
        foreach ($componentKeywords as $keyword) {
            if (strpos($nameKey, $keyword) !== false) {
                $isComponent = true;
                break;
            }
        }
        if (!$isComponent) {
            return 'Laptop';
        }
    }
    if (strpos($nameKey, ' pc ') !== false || strpos($nameKey, ' may bo ') !== false || strpos($nameKey, ' may tinh de ban ') !== false || strpos($nameKey, ' pcap ') !== false || strpos($nameKey, ' workstation ') !== false) {
        return 'PC';
    }
    if (strpos($nameKey, ' vga ') !== false || strpos($nameKey, ' card man hinh ') !== false || strpos($nameKey, ' card do hoa ') !== false || strpos($nameKey, ' rtx ') !== false || strpos($nameKey, ' gtx ') !== false) {
        return 'VGA';
    }
    if (strpos($nameKey, ' man hinh ') !== false || strpos($nameKey, ' monitor ') !== false) {
        $monitorAccessoryKeywords = [
            ' gia do ', ' tay do ', ' arm ', ' de ', ' kep ', ' treo tuong '
        ];
        $isAccessory = false;
        foreach ($monitorAccessoryKeywords as $keyword) {
            if (strpos($nameKey, $keyword) !== false) {
                $isAccessory = true;
                break;
            }
        }
        if (!$isAccessory) {
            return 'Màn hình';
        }
    }
    if (strpos($nameKey, ' tai nghe ') !== false || strpos($nameKey, ' micro ') !== false || strpos($nameKey, ' earphone ') !== false || strpos($nameKey, ' headphone ') !== false) {
        return 'Tai nghe';
    }
    if (strpos($nameKey, ' tan nhiet ') !== false || strpos($nameKey, ' cooling ') !== false) {
        return 'Tản Nhiệt';
    }
    if (strpos($nameKey, ' ssd ') !== false || strpos($nameKey, ' hdd ') !== false || strpos($nameKey, ' o cung ') !== false) {
        return 'HDD-SSD';
    }
    if (strpos($nameKey, ' mainboard ') !== false || strpos($nameKey, ' bo mach chu ') !== false) {
        return 'Mainboard';
    }
    if (strpos($nameKey, ' cpu ') !== false || strpos($nameKey, ' vi xu ly ') !== false || strpos($nameKey, ' core i') !== false || strpos($nameKey, ' ryzen ') !== false) {
        return 'CPU';
    }

    return 'Linh kiện';
}

function parseProductSpecifications($xpath) {
    $specs = [];
    $rowQueries = [
        "//div[contains(@class, 'pro-info-summary')]//li", // Bắt cấu hình tóm tắt cạnh hình ảnh (Chuẩn PCAP An Phát)
        "//div[contains(@class, 'bang-tskt')]//tr", // Bảng thông số chi tiết chuẩn
        "//div[@id='product-spec']//tr", // Block thông số
        "//table[contains(@class, 'table-tskt')]//tr",
        "//div[contains(@class, 'product-spec-group')]//table//tr",
        "//div[contains(@class, 'product-spec-group')]//li",
        "//table[contains(@class, 'product-specs')]//tr",
        "//div[contains(@class, 'specifications')]//li",
        "//div[contains(@class, 'specification')]//tr",
        "//div[contains(@class, 'specs')]//tr",
        "//table//tr" // Lưới quét cuối cùng
    ];

    $rows = [];
    foreach ($rowQueries as $query) {
        $nodes = $xpath->query($query);
        if ($nodes->length > 0) {
            foreach ($nodes as $node) {
                $rows[] = $node;
            }
        }
    }

    $headerLabels = [
        'stt', 'ma hang', 'ten hang', 'thoi han bao hanh', 'bao hanh',
        'don gia', 'thanh tien', 'so luong', 'don vi', 'ghi chu',
        'gia niem yet', 'gia khuyen mai', 'gia phai thanh toan', 'tong cong', 'gia ban'
    ];

    foreach ($rows as $row) {
        if (!$row) {
            continue;
        }

        $label = '';
        $value = '';
        $rowText = normalizeTextValue($row->textContent);
        if ($rowText === '') {
            continue;
        }

        $cellNodes = $xpath->query('.//th|.//td', $row);
        $cells = [];
        foreach ($cellNodes as $cell) {
            $cells[] = normalizeTextValue($cell->textContent);
        }
        $cellCount = count($cells);

        if ($cellCount >= 3 && preg_match('/^\d+$/', $cells[0])) {
            $label = $cells[1];
            $value = $cells[2];
        } elseif ($cellCount >= 2) {
            $label = $cells[0];
            $value = $cells[1];
        } elseif ($cellCount === 1 && strpos($rowText, ':') === false) {
            continue;
        } elseif (strpos($rowText, ':') !== false) {
            $parts = explode(':', $rowText, 2);
            $label = normalizeTextValue($parts[0]);
            $value = normalizeTextValue($parts[1]);
        } elseif (preg_match('/^(.*?)(\s+[-–:]\s+)(.*)$/u', $rowText, $parts)) {
            $label = normalizeTextValue($parts[1]);
            $value = normalizeTextValue($parts[3]);
        }

        if ($label === '' || $value === '') {
            continue;
        }

        $labelKey = removeVietnameseTones($label);
        $labelKey = trim(preg_replace('/[^a-z0-9 ]+/', ' ', $labelKey));
        $labelKey = preg_replace('/\s+/', ' ', $labelKey);
        if (in_array($labelKey, $headerLabels, true)) {
            continue;
        }

        if (!isset($specs[$label]) || $specs[$label] === '') {
            $specs[$label] = $value;
        }
    }

    return $specs;
}

function getRequestProtocol() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https' ? 'https' : 'http';
    }
    if (!empty($_SERVER['REQUEST_SCHEME'])) {
        return strtolower($_SERVER['REQUEST_SCHEME']);
    }
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return 'https';
    }
    return 'http';
}

function normalizeImageUrl($url) {
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (strpos($url, '//') === 0) {
        return 'https:' . $url;
    }
    if (preg_match('#^https?://#i', $url)) {
        return preg_replace('#^http://#i', 'https://', $url);
    }
    return $url;
}

function getPublicImageUrl($localFile) {
    $filename = basename($localFile);
    $publicPath = '/images/anphatpc/' . $filename;
    $publicPath = preg_replace('#/+#', '/', $publicPath);

    if (!empty($_SERVER['HTTP_HOST'])) {
        return getRequestProtocol() . '://' . $_SERVER['HTTP_HOST'] . $publicPath;
    }
    return $publicPath;
}

// Client-ID Imgur (dùng chung với form đăng tay ở FE) để lưu ảnh sạch,
// không phụ thuộc ổ đĩa của Render (file local bị xóa mỗi lần deploy).
if (!defined('IMGUR_CLIENT_ID')) {
    define('IMGUR_CLIENT_ID', '139e72807f61c3c');
}

// Tải nội dung ảnh gốc về dạng binary.
function fetchImageData($imageUrl) {
    $imageUrl = trim($imageUrl);
    if (empty($imageUrl) || stripos($imageUrl, 'data:image') === 0) {
        return null;
    }

    // Ảnh được trích ra từ nội dung trang đã cào (og:image, src...), không
    // phải url do client trực tiếp gửi lên -> vẫn phải qua kiểm tra an toàn
    // giống mọi url khác, tránh mở rộng bề mặt SSRF qua nội dung trang.
    if (!is_safe_public_url($imageUrl)) {
        error_log('[SSRF blocked] fetchImageData từ chối url không an toàn: ' . $imageUrl);
        return null;
    }

    $ch = curl_init($imageUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Accept: image/avif,image/webp,image/apng,image/*,*/*;q=0.8",
        "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $data = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $safeAfterRedirect = curl_result_is_from_safe_url($ch);
    curl_close($ch);

    if ($data === false || $httpCode !== 200 || strlen($data) < 100 || !$safeAfterRedirect) {
        return null;
    }
    return $data;
}

// Up ảnh (binary) lên Imgur, trả về link ảnh trực tiếp hoặc null nếu lỗi.
function uploadImageToImgur($imageData, $clientId) {
    if (empty($imageData) || empty($clientId)) {
        return null;
    }

    $ch = curl_init('https://api.imgur.com/3/image');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Client-ID " . $clientId]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, [
        "image" => base64_encode($imageData),
        "type" => "base64"
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 40);

    $resp = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($resp === false || $httpCode !== 200) {
        return null;
    }

    $json = json_decode($resp, true);
    if (!empty($json['success']) && !empty($json['data']['link'])) {
        return normalizeImageUrl($json['data']['link']);
    }
    return null;
}

// Tải ảnh gốc -> up lên Imgur. Trả về link Imgur sạch,
// hoặc '' nếu không xử lý được (caller sẽ giữ ảnh gốc làm fallback).
function buildCleanImageUrl($imageUrl) {
    $raw = fetchImageData($imageUrl);
    if ($raw === null) {
        return '';
    }

    // An Phat images don't seem to have watermarks, so we upload the raw image directly.
    $link = uploadImageToImgur($raw, IMGUR_CLIENT_ID);
    return $link !== null ? $link : '';
}

/**
 * Cào TẤT CẢ danh mục đã cấu hình (collection `configs`, meta_key
 * 'anphat_auto_crawl_urls' = JSON array URL), mỗi danh mục cào tiếp từ offset
 * đã lưu lần trước, dừng khi đạt giới hạn sản phẩm mới/lần hoặc cào hết.
 * Dùng chung cho cả CLI local (cli/auto_crawl_anphat.php) và endpoint HTTP
 * (api/cron_auto_crawl.php, để dịch vụ cron ngoài gọi khi chạy trên Render).
 *
 * $logFn(string $message): void - callback ghi log, tuỳ nơi gọi (echo ra CLI
 * hay ghi vào file) - truyền null để bỏ qua log.
 */
function runAutoCrawlAllCategories($db, ?callable $logFn = null, $maxNewPerRun = 50, $batchSize = 5) {
    $log = $logFn ?: function ($msg) {};
    $configsCollection = $db->configs;

    $configDoc = $configsCollection->findOne(['meta_key' => 'anphat_auto_crawl_urls']);
    if ($configDoc && !empty($configDoc['meta_value'])) {
        $decoded = json_decode($configDoc['meta_value'], true);
        $crawlUrls = is_array($decoded) ? array_values(array_filter($decoded)) : [];
    } else {
        $crawlUrls = [];
    }

    $summary = [
        'categories' => count($crawlUrls),
        'new_inserted' => 0,
        'updated_specifications' => 0,
        'stopped_at_limit' => false,
    ];

    if (empty($crawlUrls)) {
        $log("Danh sách link cào tự động đang rỗng (collection configs, meta_key='anphat_auto_crawl_urls'). Dừng.");
        return $summary;
    }

    $log("Bắt đầu cào tự động. " . count($crawlUrls) . " danh mục, giới hạn {$maxNewPerRun} sản phẩm mới/lần.");

    foreach ($crawlUrls as $url) {
        if ($summary['new_inserted'] >= $maxNewPerRun) {
            $summary['stopped_at_limit'] = true;
            $log("Đã đạt giới hạn {$summary['new_inserted']} sản phẩm mới, dừng sớm. Các danh mục còn lại sẽ cào tiếp vào lần chạy sau.");
            break;
        }

        // Vị trí offset đã cào tới của danh mục này, để lần chạy sau cào tiếp
        // thay vì lặp lại từ đầu (offset lưu riêng theo từng URL).
        $offsetKey = 'anphat_auto_crawl_offset::' . md5($url);
        $offsetDoc = $configsCollection->findOne(['meta_key' => $offsetKey]);
        $offset = $offsetDoc ? intval($offsetDoc['meta_value']) : 0;

        $log("Đang cào: {$url} (offset={$offset})");

        $hasMore = true;
        while ($hasMore && $summary['new_inserted'] < $maxNewPerRun) {
            try {
                $result = crawlAnPhatPcBatch($db, $url, $offset, $batchSize);
            } catch (\Throwable $e) {
                $log("  Lỗi khi cào batch tại offset={$offset}: " . $e->getMessage());
                break;
            }

            if ($result['status'] !== 'success') {
                $log("  Dừng danh mục này: " . ($result['message'] ?? 'lỗi không xác định'));
                break;
            }

            $data = $result['data'];
            $summary['new_inserted'] += $data['new_inserted'];
            $summary['updated_specifications'] += $data['updated_specifications'];
            $log("  Batch offset={$offset}: +{$data['new_inserted']} mới, {$data['updated_specifications']} cập nhật, bỏ qua {$data['skipped']}.");

            $offset = $data['next_offset'];
            $hasMore = $data['has_more'];

            // Nghỉ giữa các batch để giảm tải/tránh bị chặn IP.
            if ($hasMore) {
                sleep(1);
            }
        }

        // Lưu lại vị trí offset đã cào tới cho danh mục này (dù hết hay chưa hết),
        // để lần chạy tiếp theo (hoặc khi vượt giới hạn) tiếp tục đúng chỗ.
        // Nếu danh mục đã cào hết (hasMore=false), reset về 0 để lần sau kiểm tra
        // sản phẩm mới xuất hiện từ đầu danh mục.
        $nextOffsetToSave = $hasMore ? $offset : 0;
        $configsCollection->updateOne(
            ['meta_key' => $offsetKey],
            ['$set' => ['meta_key' => $offsetKey, 'meta_value' => (string) $nextOffsetToSave]],
            ['upsert' => true]
        );
    }

    $log("Hoàn tất. Tổng cộng: {$summary['new_inserted']} sản phẩm mới (status=pending, chờ admin duyệt), {$summary['updated_specifications']} sản phẩm cập nhật thông số.");
    return $summary;
}

/**
 * Cào theo BỘ LỌC: quét 1 link danh mục, chỉ lưu những sản phẩm khớp điều kiện
 * (giá, chip, RAM), bỏ qua sản phẩm quảng cáo/khuyến mãi và sản phẩm không
 * khớp, dừng khi đủ $wantCount sản phẩm mới hoặc quét hết $scanCap sản phẩm.
 * Dùng cho api/crawl_filtered.php (nút "Cào theo bộ lọc" ở trang admin).
 */
function crawlAnPhatFiltered($db, $target_url, array $filters, $wantCount, $scanCap, ?callable $logFn = null, ?callable $progressFn = null) {
    $log = $logFn ?: function ($msg) {};
    $progress = $progressFn ?: function ($stats) {};
    $collection = $db->products;
    $default_image = "https://via.placeholder.com/400x300?text=An+Phat+PC";

    $priceMin = isset($filters['price_min']) && $filters['price_min'] !== null ? (int) $filters['price_min'] : null;
    $priceMax = isset($filters['price_max']) && $filters['price_max'] !== null ? (int) $filters['price_max'] : null;
    $chip = !empty($filters['chip']) ? removeVietnameseTones($filters['chip']) : null;
    $ram = !empty($filters['ram']) ? removeVietnameseTones($filters['ram']) : null;

    $html = fetchHTML($target_url);
    if (!$html) {
        return ["status" => "error", "message" => "Không thể truy cập đường link danh mục này. Web có thể đang chặn Bot."];
    }

    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    $isCategoryPage = $xpath->query("//*[contains(@onclick, 'show_more_product')] | //div[@id='js-filter-container'] | //div[contains(@class, 'p-item')] | //div[contains(@class, 'product-item')]")->length > 0;
    if (!$isCategoryPage) {
        return ["status" => "error", "message" => "Link này không phải trang danh mục (danh sách sản phẩm)."];
    }

    $totalLinks = 0;
    $apiImageMap = [];
    $links = fetchAnPhatCategoryProductLinks($target_url, $html, 0, $scanCap, $totalLinks, $apiImageMap);

    if (empty($links)) {
        return ["status" => "error", "message" => "Không tìm thấy sản phẩm nào trong danh mục này."];
    }

    $insertedCount = 0;
    $updatedCount = 0;
    $scannedCount = 0;
    $excludedCount = 0;
    $filteredOutCount = 0;

    // Từ khóa loại trừ: quảng cáo / khuyến mãi / hàng tặng kèm, không phải sản
    // phẩm thật cần cào.
    $excludeKeywords = ['an phat', 'khuyen mai', 'giam gia', 'sale', 'qua tang', 'combo'];

    // Tải theo LÔ song song (thay vì từng trang một) để giảm thời gian chờ
    // khi phải quét qua nhiều sản phẩm không khớp bộ lọc mới đủ số lượng cần.
    $CHUNK_SIZE = 6;
    $linksToScan = array_slice($links, 0, $scanCap);
    $chunks = array_chunk($linksToScan, $CHUNK_SIZE);
    $scanLimit = count($linksToScan);

    // Báo tiến độ realtime cho FE (xem api/crawl_filtered.php - stream NDJSON)
    // sau mỗi sản phẩm được xử lý, để admin biết đang cào tới đâu thay vì chờ
    // mù mờ không rõ còn bao lâu.
    $emitProgress = function () use (&$scannedCount, &$insertedCount, &$updatedCount, &$excludedCount, &$filteredOutCount, $wantCount, &$totalLinks, $scanLimit, $progress) {
        $progress([
            'scanned' => $scannedCount,
            'scan_limit' => $scanLimit,
            'new_inserted' => $insertedCount,
            'updated_specifications' => $updatedCount,
            'excluded' => $excludedCount,
            'filtered_out' => $filteredOutCount,
            'want_count' => $wantCount,
            'total_links_in_category' => $totalLinks,
        ]);
    };
    $emitProgress();

    foreach ($chunks as $chunk) {
        if ($insertedCount >= $wantCount) {
            break;
        }

        $htmlByUrl = fetchHTMLMulti($chunk);

        foreach ($chunk as $link) {
            if ($insertedCount >= $wantCount) {
                break;
            }
            if ($scannedCount >= $scanCap) {
                break;
            }
            $scannedCount++;
            $emitProgress();

            $detail_html = $htmlByUrl[$link] ?? false;
            if (!$detail_html) {
                continue;
            }

            $detail_dom = new DOMDocument();
            @$detail_dom->loadHTML(mb_convert_encoding($detail_html, 'HTML-ENTITIES', 'UTF-8'));
            $detail_xpath = new DOMXPath($detail_dom);
    
            $nameNode = $detail_xpath->query("//div[@class='pro-name']/h1");
            if ($nameNode->length === 0) {
                $nameNode = $detail_xpath->query("//h1");
            }
            if ($nameNode->length === 0) {
                continue;
            }
            $product_name = trim($nameNode->item(0)->nodeValue);
    
            $nameKey = ' ' . removeVietnameseTones($product_name) . ' ';
            $isExcluded = false;
            foreach ($excludeKeywords as $keyword) {
                if (strpos($nameKey, $keyword) !== false) {
                    $isExcluded = true;
                    break;
                }
            }
            if ($isExcluded) {
                $excludedCount++;
                $log("  [bỏ qua - quảng cáo/KM] {$product_name}");
                continue;
            }
    
            $priceNode = $detail_xpath->query("//div[contains(@class, 'price-container')]/p[@class='price'] | //div[contains(@class, 'product-price-meta')]//span[contains(@class, 'price')]");
            $price_val = 0;
            if ($priceNode->length > 0) {
                $price_val = (int) preg_replace('/[^0-9]/', '', $priceNode->item(0)->nodeValue);
            }
            if ($price_val == 0) {
                $price_val = null;
            }
    
            if ($priceMin !== null && ($price_val === null || $price_val < $priceMin)) {
                $filteredOutCount++;
                continue;
            }
            if ($priceMax !== null && ($price_val === null || $price_val > $priceMax)) {
                $filteredOutCount++;
                continue;
            }
    
            $specs = parseProductSpecifications($detail_xpath);
            $specs_json = !empty($specs) ? json_encode($specs, JSON_UNESCAPED_UNICODE) : null;
            $matchKey = removeVietnameseTones($product_name . ' ' . ($specs_json ?? ''));
    
            if ($chip !== null && strpos($matchKey, $chip) === false) {
                $filteredOutCount++;
                continue;
            }
            if ($ram !== null && strpos($matchKey, $ram) === false) {
                $filteredOutCount++;
                continue;
            }
    
            $source_image = '';
            $found_image = findProductImageUrl($detail_xpath, $link);
            if (!empty($found_image)) {
                $source_image = normalizeImageUrl($found_image);
            } elseif (!empty($apiImageMap[$link])) {
                $source_image = normalizeImageUrl($apiImageMap[$link]);
            }
    
            $product_type = classifyAnPhatProductType($product_name, $target_url);
    
            $existing_product = $collection->findOne(
                ['product_name' => $product_name],
                ['projection' => ['_id' => 1, 'source' => 1, 'image_url' => 1]]
            );
    
            if ($existing_product) {
                $image_update = '';
                $existing_image = $existing_product['image_url'] ?? '';
                if (!empty($source_image) && stripos($existing_image, 'imgur.com') === false) {
                    $image_update = buildCleanImageUrl($source_image);
                }
                $set_fields = [
                    'specifications' => $specs_json,
                    'price' => $price_val,
                    'product_type' => $product_type,
                ];
                if ($image_update !== '') {
                    $set_fields['image_url'] = $image_update;
                }
                $collection->updateOne(['_id' => $existing_product['_id']], ['$set' => $set_fields]);
                $updatedCount++;
                $log("  [cập nhật] {$product_name}");
            } else {
                $image_url = $default_image;
                if (!empty($source_image)) {
                    $clean_image = buildCleanImageUrl($source_image);
                    $image_url = $clean_image !== '' ? $clean_image : $source_image;
                }
                $collection->insertOne([
                    'product_name' => $product_name,
                    'price' => $price_val,
                    'is_price_visible' => 1,
                    'image_url' => $image_url,
                    'description' => '',
                    'manufacturer' => '',
                    'product_type' => $product_type,
                    'status' => 'pending',
                    'source' => 'bot',
                    'specifications' => $specs_json,
                    'created_at' => new MongoDB\BSON\UTCDateTime(),
                ]);
                $insertedCount++;
            $log("  [+ mới] {$product_name}");
            }
            $emitProgress();
        }

        if ($insertedCount < $wantCount) {
            usleep(200000); // Nghỉ ngắn giữa các lô để giảm rủi ro bị chặn IP
        }
    }

    return [
        "status" => "success",
        "message" => "Hoàn tất cào theo bộ lọc.",
        "data" => [
            "scanned" => $scannedCount,
            "new_inserted" => $insertedCount,
            "updated_specifications" => $updatedCount,
            "excluded" => $excludedCount,
            "filtered_out" => $filteredOutCount,
            "total_links_in_category" => $totalLinks,
            "reached_target" => $insertedCount >= $wantCount,
        ],
    ];
}

/**
 * Cào 1 batch sản phẩm từ 1 link An Phát PC (danh mục hoặc sản phẩm đơn lẻ) và
 * ghi thẳng vào MongoDB (status='pending' cho sản phẩm mới, giữ nguyên logic
 * chống trùng lặp/không đè dữ liệu admin đã sửa tay như bản gốc).
 *
 * Trả về mảng ["status" => "success"|"error", "message" => ..., "data" => [...]]
 * giống hệt response JSON mà bot_sync_anphatpc.php trả cho FE, để 2 nơi gọi
 * (endpoint HTTP và script CLI tự động) dùng chung 1 định dạng.
 */
function crawlAnPhatPcBatch($db, $target_url, $offset = 0, $batch_size = 5) {
    $collection = $db->products;

    $insertedCount = 0;
    $updatedCount = 0;
    $processedCount = 0;
    $skippedCount = 0;
    $default_image = "https://via.placeholder.com/400x300?text=An+Phat+PC";
    $image_save_dir = realpath(__DIR__ . '/../../images/anphatpc') ?: (__DIR__ . '/../../images/anphatpc');

    $html = fetchHTML($target_url);
    if (!$html) {
        return [
            "status" => "error",
            "message" => "Không thể truy cập đường link này. Web có thể đang chặn Bot."
        ];
    }

    $dom = new DOMDocument();
    @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);

    $product_links = [];
    $apiImageMap = [];
    $total_links = 0;
    $isApiCategoryPage = false;

    $isCategoryPage = $xpath->query("//*[contains(@onclick, 'show_more_product')] | //div[@id='js-filter-container'] | //div[contains(@class, 'p-item')] | //div[contains(@class, 'product-item')]")->length > 0;

    if (!$isCategoryPage) {
        $product_links[] = $target_url;
        $total_links = 1;
    } else {
        $apiLinks = fetchAnPhatCategoryProductLinks($target_url, $html, $offset, $batch_size, $total_links, $apiImageMap);
        if (!empty($apiLinks)) {
            $product_links = $apiLinks;
            $isApiCategoryPage = true;
        } else {
            $itemNodes = $xpath->query("//div[contains(@class, 'p-item')]//a[contains(@class, 'p-img')] | //div[contains(@class, 'product-item')]//a[@href]");
            if ($itemNodes->length > 0) {
                foreach ($itemNodes as $node) {
                    $href = $node->getAttribute('href');
                    if (!empty($href)) {
                        $href = resolveAbsoluteUrl($href, "https://www.anphatpc.com.vn/");
                        $product_links[] = $href;
                    }
                }
                $product_links = array_unique($product_links);
            }
        }
    }

    if (empty($product_links)) {
        return [
            "status" => "error",
            "message" => "Không tìm thấy sản phẩm nào trong link này để cào."
        ];
    }

    if ($total_links === 0) {
        $total_links = count($product_links);
    }

    if ($isApiCategoryPage) {
        $current_batch = $product_links;
    } else {
        $current_batch = array_slice($product_links, $offset, $batch_size);
    }

    if (empty($current_batch)) {
        return [
            "status" => "error",
            "message" => "Không còn sản phẩm để cào tại vị trí offset này."
        ];
    }

    foreach ($current_batch as $link) {
        usleep(300000); // Ngủ 0.3s để tránh bị block IP

        $detail_html = fetchHTML($link);
        if (!$detail_html) {
            $skippedCount++;
            continue;
        }

        $detail_dom = new DOMDocument();
        @$detail_dom->loadHTML(mb_convert_encoding($detail_html, 'HTML-ENTITIES', 'UTF-8'));
        $detail_xpath = new DOMXPath($detail_dom);

        $nameNode = $detail_xpath->query("//div[@class='pro-name']/h1");
        if ($nameNode->length === 0) {
            $nameNode = $detail_xpath->query("//h1");
        }
        if ($nameNode->length === 0) {
            $skippedCount++;
            continue;
        }
        $product_name = trim($nameNode->item(0)->nodeValue);

        $nameKeyForExclusion = ' ' . removeVietnameseTones($product_name) . ' ';
        $exclusionKeywords = [
            ' balo ', ' tui xach ', ' tui ', ' ghe ', ' ban ', ' qua tang ', ' ao thun ', ' ao khoac ', ' moc khoa '
        ];
        $isExcluded = false;
        foreach ($exclusionKeywords as $keyword) {
            if (strpos($nameKeyForExclusion, $keyword) !== false) {
                $isExcluded = true;
                break;
            }
        }
        if ($isExcluded) {
            $skippedCount++;
            continue;
        }

        $priceNode = $detail_xpath->query("//div[contains(@class, 'price-container')]/p[@class='price'] | //div[contains(@class, 'product-price-meta')]//span[contains(@class, 'price')]");
        $price_val = 0;
        if ($priceNode->length > 0) {
            $raw_price = $priceNode->item(0)->nodeValue;
            $price_val = (int) preg_replace('/[^0-9]/', '', $raw_price);
        }
        if ($price_val == 0) {
            $price_val = null;
        }

        $source_image = '';
        $found_image = findProductImageUrl($detail_xpath, $link);
        if (!empty($found_image)) {
            $source_image = normalizeImageUrl($found_image);
        } elseif (!empty($apiImageMap[$link])) {
            $source_image = normalizeImageUrl($apiImageMap[$link]);
        }

        $specs = parseProductSpecifications($detail_xpath);
        $specs_json = !empty($specs) ? json_encode($specs, JSON_UNESCAPED_UNICODE) : null;

        $product_type = classifyAnPhatProductType($product_name, $target_url);

        $existing_product = $collection->findOne(
            ['$or' => [['product_name' => $product_name]]],
            ['projection' => ['_id' => 1, 'source' => 1, 'image_url' => 1]]
        );

        if ($existing_product) {
            $image_update = '';
            $existing_image = $existing_product['image_url'] ?? '';
            if (!empty($source_image) && stripos($existing_image, 'imgur.com') === false) {
                $image_update = buildCleanImageUrl($source_image);
            }

            $is_bot_source = ($existing_product['source'] ?? '') === 'bot';
            $set_fields = [
                'specifications' => $specs_json,
                'price' => $price_val,
                'product_type' => $product_type,
            ];
            if ($is_bot_source) {
                $set_fields['product_name'] = $product_name;
                $set_fields['manufacturer'] = '';
                $set_fields['description'] = '';
            }
            if ($image_update !== '') {
                $set_fields['image_url'] = $image_update;
            }

            $update_result = $collection->updateOne(
                ['_id' => $existing_product['_id']],
                ['$set' => $set_fields]
            );
            if ($update_result->isAcknowledged()) {
                $updatedCount++;
                $processedCount++;
            }
        } else {
            $image_url = $default_image;
            if (!empty($source_image)) {
                $clean_image = buildCleanImageUrl($source_image);
                $image_url = $clean_image !== '' ? $clean_image : $source_image;
            }

            $insert_result = $collection->insertOne([
                'product_name' => $product_name,
                'price' => $price_val,
                'is_price_visible' => 1,
                'image_url' => $image_url,
                'description' => '',
                'manufacturer' => '',
                'product_type' => $product_type,
                'status' => 'pending',
                'source' => 'bot',
                'specifications' => $specs_json,
                'created_at' => new MongoDB\BSON\UTCDateTime(),
            ]);
            if ($insert_result->getInsertedCount() === 1) {
                $insertedCount++;
                $processedCount++;
            }
        }
    }

    $has_more = ($offset + count($current_batch)) < $total_links;

    if (!$isCategoryPage) {
        if ($processedCount === 1) {
            if ($insertedCount === 1) {
                $message = "Đã lấy xong dữ liệu, thêm 1 sản phẩm mới thành công.";
            } elseif ($updatedCount === 1) {
                $message = "Đã lấy xong dữ liệu, cập nhật 1 sản phẩm thành công.";
            } else {
                $message = "Đã xử lý xong sản phẩm, không có thay đổi.";
            }
        } else {
            $message = "Không thể xử lý sản phẩm từ link này.";
        }
        $continue_prompt = "Hoàn tất cào sản phẩm đơn lẻ.";
        $continue_label = "Hoàn tất";
        $has_more = false;
    } else {
        $message = "Batch này: Thêm {$insertedCount}, cập nhật {$updatedCount}. Tổng link tìm thấy: {$total_links}.";
        $continue_prompt = $has_more ? "Tiếp tục cào dữ liệu?" : "Đã cào hết danh sách sản phẩm.";
        $continue_label = $has_more ? "Tiếp tục cào " . $batch_size . " sản phẩm" : "Hoàn tất";
    }

    return [
        "status" => "success",
        "message" => $message,
        "data" => [
            "target_scanned" => $target_url,
            "total_links_found" => $total_links,
            "total_links" => $total_links,
            "batch_count" => count($current_batch),
            "processed_count" => $processedCount,
            "new_inserted" => $insertedCount,
            "updated_specifications" => $updatedCount,
            "skipped" => $skippedCount,
            "next_offset" => $offset + count($current_batch),
            "has_more" => $has_more,
            "continue_prompt" => $continue_prompt,
            "continue_label" => $continue_label
        ]
    ];
}
