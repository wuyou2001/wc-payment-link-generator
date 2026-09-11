<?php

/**
 * Plugin Name: WooCommerce 付款链接生成器
 * Description: 在 WooCommerce 后台生成自定义金额付款链接，支持设置有效期限、变体产品自动补齐属性、可自主选择保留页面内容（产品信息、账单地址、页首页脚、隐私政策），支持跳过订单验证与纯净结账模式，完美兼容移动端与所有主题，支持 GitHub Releases 一键自动升级更新。
 * Version: 2.0.4
 * Author: Wwnine
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * 判断旧版付款链接。
 */
function wplpg_is_payment_link_request()
{
    return isset($_GET['add-to-cart'], $_GET['amount'])
        && absint($_GET['add-to-cart']) > 0
        && is_numeric($_GET['amount'])
        && (float) $_GET['amount'] > 0;
}

/**
 * WooCommerce Session。
 */
function wplpg_set_session($key, $value)
{
    if (function_exists('WC') && WC()->session) {
        WC()->session->set($key, $value);
    }
}

function wplpg_unset_session($key)
{
    if (function_exists('WC') && WC()->session) {
        WC()->session->__unset($key);
    }
}

/**
 * 获取实际 Checkout 页面 Slug。
 */
function wplpg_get_checkout_slug()
{
    $page_id = function_exists('wc_get_page_id')
        ? wc_get_page_id('checkout')
        : 0;

    if ($page_id > 0) {
        $slug = sanitize_title(get_post_field('post_name', $page_id));

        if ($slug) {
            return $slug;
        }
    }

    return 'checkout';
}

/**
 * 格式化有效时长。
 */
function wplpg_format_duration($seconds)
{
    $seconds = absint($seconds);

    if ($seconds <= 0) {
        return '永久有效';
    }

    if ($seconds % DAY_IN_SECONDS === 0) {
        return ($seconds / DAY_IN_SECONDS) . ' 天';
    }

    if ($seconds % HOUR_IN_SECONDS === 0) {
        return ($seconds / HOUR_IN_SECONDS) . ' 小时';
    }

    if ($seconds % MINUTE_IN_SECONDS === 0) {
        return ($seconds / MINUTE_IN_SECONDS) . ' 分钟';
    }

    return ceil($seconds / HOUR_IN_SECONDS) . ' 小时';
}

/**
 * 生成 Token。
 */
function wplpg_generate_token()
{
    try {
        return bin2hex(random_bytes(12));
    } catch (Exception $e) {
        if (function_exists('wp_generate_password')) {
            $pwd = preg_replace('/[^a-f0-9]/', '', strtolower(wp_generate_password(48, false, false)));
            if (strlen($pwd) >= 24) {
                return substr($pwd, 0, 24);
            }
        }
        return substr(md5(uniqid((string) mt_rand(), true)), 0, 24);
    }
}

/**
 * 智能解析变体商品与自动补全所有属性选项。
 * 当变体产品未选定具体变体或属性包含“任意/未设置”时，自动选择有效变体并填充完整属性。
 *
 * @param int   $product_id   主商品 ID
 * @param int   $variation_id 变体 ID（可选）
 * @param array $attributes   已有属性键值对（可选）
 * @return array ['variation_id' => int, 'attributes' => array]
 */
function wplpg_resolve_variation_and_attributes($product_id, $variation_id = 0, $attributes = [])
{
    $product_id = absint($product_id);
    $product = wc_get_product($product_id);

    if (!$product || !$product->is_type('variable')) {
        return [
            'variation_id' => 0,
            'attributes'   => [],
        ];
    }

    $variation = null;
    if ($variation_id > 0) {
        $candidate = wc_get_product($variation_id);
        if ($candidate && (int) $candidate->get_parent_id() === $product_id) {
            $variation = $candidate;
        }
    }

    // 如果没有指定有效变体，自动寻找一个可购买且有库存的变体（优先在库变体）
    if (!$variation) {
        $children = $product->get_children();
        if (!empty($children)) {
            foreach ($children as $cid) {
                $candidate = wc_get_product($cid);
                if ($candidate && $candidate->is_purchasable() && $candidate->is_in_stock() && (int) $candidate->get_parent_id() === $product_id) {
                    $variation = $candidate;
                    $variation_id = $candidate->get_id();
                    break;
                }
            }
            // 兜底：若所有变体库存均为0，选择第一个子变体
            if (!$variation) {
                $first_id = $children[0];
                $candidate = wc_get_product($first_id);
                if ($candidate && (int) $candidate->get_parent_id() === $product_id) {
                    $variation = $candidate;
                    $variation_id = $candidate->get_id();
                }
            }
        }
    }

    if (!$variation) {
        return [
            'variation_id' => 0,
            'attributes'   => [],
        ];
    }

    $variation_id = $variation->get_id();
    $var_attributes = $variation->get_variation_attributes();
    $merged = is_array($attributes) ? array_merge($var_attributes, $attributes) : $var_attributes;

    // 自动补齐所有变体属性（解决“任何属性/Any”或漏填导致 WooCommerce 无法加入购物车的问题）
    $parent_attributes = $product->get_attributes();
    $default_attributes = $product->get_default_attributes();

    foreach ($parent_attributes as $attr_name => $attr_obj) {
        $is_variation = is_object($attr_obj) && method_exists($attr_obj, 'get_variation') ? $attr_obj->get_variation() : true;
        if (!$is_variation) {
            continue;
        }

        $clean_name = sanitize_title($attr_name);
        $attr_key = 'attribute_' . $clean_name;

        if (!isset($merged[$attr_key]) || $merged[$attr_key] === '' || $merged[$attr_key] === null) {
            // 1. 优先使用商品设置的默认属性值
            if (isset($default_attributes[$clean_name]) && $default_attributes[$clean_name] !== '') {
                $merged[$attr_key] = $default_attributes[$clean_name];
            } elseif (is_object($attr_obj) && method_exists($attr_obj, 'is_taxonomy') && $attr_obj->is_taxonomy()) {
                // 2. 属于分类法属性时取第一个 term 的 slug
                $terms = wc_get_product_terms($product_id, $attr_name, ['fields' => 'slugs']);
                if (!empty($terms) && !is_wp_error($terms)) {
                    $merged[$attr_key] = (string) $terms[0];
                }
            } elseif (is_object($attr_obj) && method_exists($attr_obj, 'get_options')) {
                // 3. 属于自定义文本属性时取第一个选项
                $options = $attr_obj->get_options();
                if (!empty($options) && is_array($options)) {
                    $merged[$attr_key] = trim((string) $options[0]);
                }
            }
        }
    }

    return [
        'variation_id' => $variation_id,
        'attributes'   => $merged,
    ];
}

/**
 * 创建短链接。
 *
 * @param int   $product_id       商品 ID
 * @param float $amount           支付金额
 * @param int   $variation_id     变体 ID
 * @param array $attributes       变体属性
 * @param int   $expire_seconds   有效期秒数，0 为永久有效
 * @param int   $skip_validation  是否跳过订单验证（1 为跳过，0 为正常验证）
 * @param int   $hide_details    是否隐藏订单地址与详情仅显示结账模块（1 为隐藏，0 为显示）
 * @return string 短链接地址
 */
function wplpg_create_short_link(
    $product_id,
    $amount,
    $variation_id = 0,
    $attributes = [],
    $expire_seconds = 86400,
    $skip_validation = 1,
    $hide_details = 1,
    $retain_products = 0,
    $retain_billing = 0,
    $retain_header_footer = 0,
    $retain_policy = 0
) {
    $product_id = absint($product_id);
    $product = wc_get_product($product_id);

    if ($product && $product->is_type('variable')) {
        $resolved = wplpg_resolve_variation_and_attributes($product_id, $variation_id, $attributes);
        $variation_id = $resolved['variation_id'];
        $attributes = $resolved['attributes'];
    } else {
        $variation_id = 0;
        $attributes = [];
    }

    $token = wplpg_generate_token();
    $slug = wplpg_get_checkout_slug();
    $expire_seconds = (int) $expire_seconds;
    $now = time();
    $expire_at = $expire_seconds > 0 ? ($now + $expire_seconds) : 0;
    $link_payload = [
        'slug'                 => $slug,
        'product_id'           => $product_id,
        'amount'               => wc_format_decimal($amount),
        'variation_id'         => absint($variation_id),
        'attributes'           => is_array($attributes) ? $attributes : [],
        'created_at'           => $now,
        'expire_at'            => $expire_at,
        'expire_secs'          => $expire_seconds,
        'skip_validation'      => !empty($skip_validation) ? 1 : 0,
        'hide_details'         => !empty($hide_details) ? 1 : 0,
        'retain_products'      => !empty($retain_products) ? 1 : 0,
        'retain_billing'       => !empty($retain_billing) ? 1 : 0,
        'retain_header_footer' => !empty($retain_header_footer) ? 1 : 0,
        'retain_policy'        => !empty($retain_policy) ? 1 : 0,
    ];

    // 1. 物理数据库持久化存储（主存储），设置 autoload='no'，永不因 Redis/Memcached 重启或缓存清理而丢失
    update_option('wplpg_link_' . $token, $link_payload, 'no');

    // 2. 内存加速 Transient 存储（辅存储），防范 Memcached/Redis 大于 30 天时间戳溢出
    $transient_ttl = $expire_seconds > 0 ? min($expire_seconds, 2592000) : 0;
    set_transient('wplpg_short_' . $token, $link_payload, $transient_ttl);

    return trailingslashit(home_url()) .
        $slug . '-' . $token . '/';
}

/**
 * 判断当前是否处于付款链接的纯支付结账模式（通用全主题）。
 */
function wplpg_is_pure_checkout()
{
    if (isset($_GET['wplpg_token'])) {
        return true;
    }

    if (function_exists('WC')) {
        if (WC()->session && (WC()->session->get('wplpg_payment_link') || WC()->session->get('wplpg_hide_details') || WC()->session->get('wplpg_skip_validation') || WC()->session->get('custom_payment_amount'))) {
            return true;
        }

        if (WC()->cart) {
            foreach (WC()->cart->get_cart() as $cart_item) {
                if (!empty($cart_item['wplpg_payment_link']) || !empty($cart_item['wplpg_token']) || !empty($cart_item['wplpg_hide_details'])) {
                    return true;
                }
            }
        }
    }

    return false;
}


/**
 * 获取当前付款链接请求的页面内容保留配置
 *
 * @return array
 */
function wplpg_get_retention_options()
{
    $defaults = [
        'retain_products'      => 0,
        'retain_billing'       => 0,
        'retain_header_footer' => 0,
        'retain_policy'        => 0,
    ];

    if (isset($_GET['wplpg_token'])) {
        $token = sanitize_key($_GET['wplpg_token']);
        $data = wplpg_get_link_data($token);
        if (is_array($data)) {
            return [
                'retain_products'      => !empty($data['retain_products']) ? 1 : 0,
                'retain_billing'       => !empty($data['retain_billing']) ? 1 : 0,
                'retain_header_footer' => !empty($data['retain_header_footer']) ? 1 : 0,
                'retain_policy'        => !empty($data['retain_policy']) ? 1 : 0,
            ];
        }
    }

    if (function_exists('WC') && WC()->session) {
        $rp = WC()->session->get('wplpg_retain_products');
        $rb = WC()->session->get('wplpg_retain_billing');
        $rh = WC()->session->get('wplpg_retain_header_footer');
        $ry = WC()->session->get('wplpg_retain_policy');
        if ($rp !== null || $rb !== null || $rh !== null || $ry !== null) {
            return [
                'retain_products'      => !empty($rp) ? 1 : 0,
                'retain_billing'       => !empty($rb) ? 1 : 0,
                'retain_header_footer' => !empty($rh) ? 1 : 0,
                'retain_policy'        => !empty($ry) ? 1 : 0,
            ];
        }
    }

    if (function_exists('WC') && WC()->cart) {
        foreach (WC()->cart->get_cart() as $cart_item) {
            if (isset($cart_item['wplpg_retain_products']) || isset($cart_item['wplpg_retain_billing'])) {
                return [
                    'retain_products'      => !empty($cart_item['wplpg_retain_products']) ? 1 : 0,
                    'retain_billing'       => !empty($cart_item['wplpg_retain_billing']) ? 1 : 0,
                    'retain_header_footer' => !empty($cart_item['wplpg_retain_header_footer']) ? 1 : 0,
                    'retain_policy'        => !empty($cart_item['wplpg_retain_policy']) ? 1 : 0,
                ];
            }
        }
    }

    return $defaults;
}

function wplpg_is_retain_products()
{
    $opts = wplpg_get_retention_options();
    return !empty($opts['retain_products']);
}

function wplpg_is_retain_billing()
{
    $opts = wplpg_get_retention_options();
    return !empty($opts['retain_billing']);
}

function wplpg_is_retain_header_footer()
{
    $opts = wplpg_get_retention_options();
    return !empty($opts['retain_header_footer']);
}

function wplpg_is_retain_policy()
{
    $opts = wplpg_get_retention_options();
    return !empty($opts['retain_policy']);
}

/**
 * 别名函数兼容旧版。
 */
function wplpg_is_hide_details()
{
    return wplpg_is_pure_checkout();
}

function wplpg_is_skip_validation()
{
    return wplpg_is_pure_checkout();
}

/**
 * 统一多层级容错读取付款链接数据。
 * 优先读取内存 Transient 缓存；如遇缓存重置、Redis 重启或易失性淘汰，
 * 自动降级从 MySQL wp_options 永久存储恢复，并重新写入缓存。
 *
 * @param string $token 24位Token
 * @return array|false 链接配置数组或 false
 */
function wplpg_get_link_data($token)
{
    $token = sanitize_key($token);
    if (strlen($token) !== 24) {
        return false;
    }

    // 1. 优先从内存 Transient 读取
    $data = get_transient('wplpg_short_' . $token);
    if (is_array($data) && !empty($data['product_id'])) {
        return $data;
    }

    // 2. 核心兜底：从数据库 wp_options 物理持久化存储读取
    $data = get_option('wplpg_link_' . $token);
    if (is_array($data) && !empty($data['product_id'])) {
        // 自动回写 Transient 加速后续访问
        $expire_at = absint($data['expire_at'] ?? 0);
        $ttl = 0;
        if ($expire_at > 0) {
            $remaining = $expire_at - time();
            $ttl = $remaining > 0 ? min($remaining, 2592000) : 1;
        }
        set_transient('wplpg_short_' . $token, $data, $ttl);
        return $data;
    }

    return false;
}

/**
 * 根据 Token 为购物车填充自定义商品与金额。
 * 兼容移动端设备在 302 重定向中丢 Cookie 的情况。
 *
 * @param string $token 24位Token
 * @return bool|WP_Error
 */
function wplpg_populate_cart_from_token($token)
{
    $token = sanitize_key($token);
    if (strlen($token) !== 24) {
        return false;
    }

    $data = wplpg_get_link_data($token);

    if (!is_array($data)) {
        return new WP_Error('not_found', __('该付款链接不存在或已过期失效。', 'woocommerce'));
    }

    // 校验有效期
    $expire_at = absint($data['expire_at'] ?? 0);
    if ($expire_at > 0 && time() > $expire_at) {
        delete_transient('wplpg_short_' . $token);
        delete_option('wplpg_link_' . $token);
        return new WP_Error('expired', __('该付款链接已超过有效时间，已失效。请联系客服重新获取。', 'woocommerce'));
    }

    if (!function_exists('WC')) {
        return false;
    }

    // 确保 Session 初始化并下发 Cookie
    if (WC()->session) {
        if (!WC()->session->has_session()) {
            WC()->session->set_customer_session_cookie(true);
        }
    }

    if (function_exists('wc_load_cart') && !WC()->cart) {
        wc_load_cart();
    }

    if (!WC()->cart) {
        return false;
    }

    $product_id      = absint($data['product_id'] ?? 0);
    $amount          = (float) ($data['amount'] ?? 0);
    $variation_id    = absint($data['variation_id'] ?? 0);
    $attributes      = isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : [];
    $skip_validation      = isset($data['skip_validation']) ? (int) $data['skip_validation'] : 1;
    $hide_details         = isset($data['hide_details']) ? (int) $data['hide_details'] : 1;
    $retain_products      = isset($data['retain_products']) ? (int) $data['retain_products'] : 0;
    $retain_billing       = isset($data['retain_billing']) ? (int) $data['retain_billing'] : 0;
    $retain_header_footer = isset($data['retain_header_footer']) ? (int) $data['retain_header_footer'] : 0;
    $retain_policy        = isset($data['retain_policy']) ? (int) $data['retain_policy'] : 0;

    $product = wc_get_product($product_id);
    if (!$product || !$product->is_purchasable() || !is_finite($amount) || $amount <= 0) {
        return new WP_Error('invalid_product', '商品不可购买或金额无效。');
    }

    if ($product->is_type('variable')) {
        $resolved = wplpg_resolve_variation_and_attributes($product_id, $variation_id, $attributes);
        $variation_id = $resolved['variation_id'];
        $attributes   = $resolved['attributes'];
        if (!$variation_id) {
            return new WP_Error('invalid_variation', '未找到可用的商品变体。');
        }
    } else {
        $variation_id = 0;
        $attributes = [];
    }

    // 检查购物车中是否已经有该 Token 对应的商品与金额
    $already_in_cart = false;
    foreach (WC()->cart->get_cart() as $cart_item) {
        if (
            !empty($cart_item['wplpg_payment_link']) &&
            isset($cart_item['wplpg_token']) &&
            $cart_item['wplpg_token'] === $token &&
            absint($cart_item['product_id']) === $product_id &&
            absint($cart_item['variation_id']) === $variation_id &&
            isset($cart_item['custom_payment_amount']) &&
            (float) $cart_item['custom_payment_amount'] === $amount
        ) {
            $already_in_cart = true;
            break;
        }
    }

    if (!$already_in_cart) {
        WC()->cart->empty_cart();
        $cart_item_key = WC()->cart->add_to_cart(
            $product_id,
            1,
            $variation_id,
            $attributes,
            [
                'custom_payment_amount'       => $amount,
                'custom_payment_product'      => $product_id,
                'wplpg_payment_link'          => true,
                'wplpg_token'                 => $token,
                'wplpg_skip_validation'       => $skip_validation,
                'wplpg_hide_details'          => $hide_details,
                'wplpg_retain_products'       => $retain_products,
                'wplpg_retain_billing'        => $retain_billing,
                'wplpg_retain_header_footer'  => $retain_header_footer,
                'wplpg_retain_policy'         => $retain_policy,
            ]
        );

        if (!$cart_item_key) {
            return false;
        }

        // 立即计算总价并主动写入 Session 与 Cookie
        WC()->cart->calculate_totals();
        WC()->cart->maybe_set_cart_cookies();

        if (WC()->session) {
            WC()->session->set('custom_payment_amount', $amount);
            WC()->session->set('custom_payment_product', $product_id);
            WC()->session->set('wplpg_payment_link', true);
            WC()->session->set('wplpg_token', $token);
            WC()->session->set('wplpg_skip_validation', $skip_validation);
            WC()->session->set('wplpg_hide_details', $hide_details);
            WC()->session->set('wplpg_retain_products', $retain_products);
            WC()->session->set('wplpg_retain_billing', $retain_billing);
            WC()->session->set('wplpg_retain_header_footer', $retain_header_footer);
            WC()->session->set('wplpg_retain_policy', $retain_policy);
            WC()->session->save_data();
        }
    }

    return true;
}

/**
 * 监听 URL 中携带的 wplpg_token 参数（兜底移动端 302 Cookie 丢失情况）。
 */
function wplpg_check_query_token()
{
    if (isset($_GET['wplpg_token'])) {
        $token = sanitize_key($_GET['wplpg_token']);
        $res = wplpg_populate_cart_from_token($token);
        if (is_wp_error($res)) {
            if (function_exists('wc_add_notice')) {
                wc_add_notice($res->get_error_message(), 'error');
            }
        }
    }
}
add_action('wp_loaded', 'wplpg_check_query_token', 20);

/**
 * 处理短链接访问。
 *
 * 匹配 24 位十六进制 Token。
 * 在限定时间内多次打开均一直有效并重置购物车为指定商品与金额。
 */
function wplpg_handle_short_link()
{
    if (!function_exists('wc_get_checkout_url')) {
        return;
    }

    $request_uri = isset($_SERVER['REQUEST_URI'])
        ? wp_unslash($_SERVER['REQUEST_URI'])
        : '';

    $path = wp_parse_url($request_uri, PHP_URL_PATH);

    if (!is_string($path)) {
        return;
    }

    // 正则匹配结账短链接 slug 与 24 位 token
    if (!preg_match('/(?:^|\/)([^\/]+)-([a-f0-9]{24})\/?$/i', $path, $matches)) {
        return;
    }

    $token = strtolower($matches[2]);
    $result = wplpg_populate_cart_from_token($token);

    if (is_wp_error($result)) {
        if (function_exists('wc_add_notice')) {
            wc_add_notice($result->get_error_message(), 'error');
        }
        wp_safe_redirect(wc_get_checkout_url());
        exit;
    }

    // 将 token 作为参数带到结账页面，确保移动端浏览器无论是否丢失 302 Cookie 都能精准还原购物车
    $checkout_url = add_query_arg('wplpg_token', $token, wc_get_checkout_url());
    wp_safe_redirect($checkout_url);
    exit;
}
add_action('template_redirect', 'wplpg_handle_short_link', 1);

/**
 * 移除 WooCommerce 默认的隐私政策输出动作
 */
add_action('wp', function () {
    if (wplpg_is_pure_checkout() && !wplpg_is_retain_policy()) {
        remove_action('woocommerce_checkout_terms_and_conditions', 'wc_checkout_privacy_policy_text', 20);
        remove_action('woocommerce_checkout_terms_and_conditions', 'wc_terms_and_conditions_page_content', 30);
    }
});

/**
 * 为 Body 添加专属 Class 方便全局定位
 */
add_filter('body_class', function ($classes) {
    if (wplpg_is_pure_checkout()) {
        $classes[] = 'wplpg-pure-checkout';
    }
    return $classes;
});

/**
 * 适用于任何 WordPress 主题/FSE 模板的纯净结账样式与脚本
 */
function wplpg_render_pure_checkout_assets()
{
    if (!wplpg_is_pure_checkout()) {
        return;
    }

    $retain_hf       = wplpg_is_retain_header_footer();
    $retain_policy   = wplpg_is_retain_policy();
    $retain_billing  = wplpg_is_retain_billing();
    $retain_products = wplpg_is_retain_products();
    ?>
    <style id="wplpg-universal-pure-checkout-style">
        <?php if (!$retain_hf): ?>
        /* 1. 隐藏所有主题的页首（Header、顶部栏、导航菜单、站点Logo、购物车图标、页面大标题等） */
        header,
        .site-header,
        #site-header,
        #masthead,
        .header-wrapper,
        .main-header,
        .top-bar,
        .header-container,
        header.wp-block-template-part,
        .wp-block-template-part header,
        .hostinger-ai-menu,
        .whb-header,
        .woodmart-header,
        .ast-site-header,
        .elementor-location-header,
        .entry-header,
        .page-header,
        .page-title,
        h1.entry-title,
        h1.wp-block-post-title,
        .woocommerce-checkout > h1 {
            display: none !important;
            visibility: hidden !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: hidden !important;
        }

        /* 2. 隐藏所有主题的页脚（Footer、版权区、社媒小部件等） */
        footer,
        .site-footer,
        #site-footer,
        #colophon,
        .footer-wrapper,
        .main-footer,
        .footer-container,
        footer.wp-block-template-part,
        .wp-block-template-part footer,
        .woodmart-footer,
        .ast-site-footer,
        .elementor-location-footer {
            display: none !important;
            visibility: hidden !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: hidden !important;
        }
        <?php endif; ?>

        <?php if (!$retain_policy): ?>
        /* 3. 隐藏隐私政策说明文本与服务条款文字 */
        .woocommerce-privacy-policy-text,
        .woocommerce-privacy-policy-text *,
        .woocommerce-terms-and-conditions-wrapper,
        .woocommerce-terms-and-conditions-wrapper *,
        .wc-terms-and-conditions,
        .woocommerce-privacy-policy-link,
        p.form-row.terms,
        .form-row.place-order .woocommerce-terms-and-conditions-wrapper {
            display: none !important;
            visibility: hidden !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            opacity: 0 !important;
            overflow: hidden !important;
        }
        <?php endif; ?>

        <?php if (!$retain_billing): ?>
        /* 4. 隐藏所有地址表单、账户选项、额外备注、登录与优惠券提示 */
        #customer_details,
        .col2-set,
        .woocommerce-billing-fields,
        .woocommerce-shipping-fields,
        .woocommerce-additional-fields,
        .woocommerce-account-fields,
        .woocommerce-form-coupon-toggle,
        .woocommerce-form-login-toggle,
        .woodmart-checkout-steps,
        .checkout-steps-wrapper {
            display: none !important;
            visibility: hidden !important;
            height: 0 !important;
            margin: 0 !important;
            padding: 0 !important;
            overflow: hidden !important;
        }
        <?php else: ?>
        /* 保留账单地址：仅隐藏配送地址、额外备注及优惠券折叠按钮 */
        .woocommerce-shipping-fields,
        .woocommerce-additional-fields,
        .woocommerce-account-fields,
        .woocommerce-form-coupon-toggle,
        .woocommerce-form-login-toggle,
        .woodmart-checkout-steps,
        .checkout-steps-wrapper {
            display: none !important;
        }
        .col2-set .col-1 {
            float: none !important;
            width: 100% !important;
        }
        .col2-set .col-2 {
            display: none !important;
        }
        #customer_details {
            margin-bottom: 24px !important;
        }
        <?php endif; ?>

        <?php if (!$retain_products): ?>
        /* 5. 隐藏订单商品明细表、标题与冗余表格 */
        #order_review_heading,
        .woocommerce-checkout-review-order-table,
        .woocommerce-table--order-details,
        .checkout-order-review-table,
        table.shop_table {
            display: none !important;
        }
        <?php else: ?>
        .woocommerce-checkout-review-order-table {
            margin-bottom: 20px !important;
            width: 100% !important;
        }
        <?php endif; ?>

        /* 6. 纯支付模式全局页面容器居中美化 */
        body {
            background-color: #f8fafc !important;
            margin: 0 !important;
            padding: 0 !important;
        }

        .wp-site-blocks,
        .site-content,
        .main-page-wrapper,
        #main,
        .content-area,
        main {
            display: flex !important;
            flex-direction: column !important;
            justify-content: center !important;
            align-items: center !important;
            min-height: 100vh !important;
            padding: 30px 15px !important;
            box-sizing: border-box !important;
            background: transparent !important;
        }

        .entry-content,
        .woocommerce,
        form.woocommerce-checkout {
            width: 100% !important;
            max-width: <?php echo $retain_billing ? '1000px' : '560px'; ?> !important;
            margin: 0 auto !important;
            float: none !important;
        }

        #order_review {
            width: 100% !important;
            float: none !important;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            padding: 24px;
            background: #ffffff;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.05);
            box-sizing: border-box;
        }

        #payment {
            background: transparent !important;
            border: none !important;
            padding: 0 !important;
            margin: 0 !important;
        }

        #payment ul.payment_methods {
            border: none !important;
            padding: 0 !important;
        }

        /* 顶部支付金额卡片 */
        .wplpg-payment-amount-banner {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 22px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .wplpg-payment-amount-banner .wplpg-banner-label {
            font-size: 15px;
            color: #475569;
            font-weight: 600;
        }

        .wplpg-payment-amount-banner .wplpg-banner-amount {
            font-size: 24px;
            color: #0f172a;
            font-weight: 700;
        }

        @media (max-width: 600px) {
            .wp-site-blocks,
            .site-content,
            #main {
                padding: 15px 10px !important;
            }
            #order_review {
                padding: 18px;
            }
            .wplpg-payment-amount-banner {
                padding: 14px 16px;
            }
            .wplpg-payment-amount-banner .wplpg-banner-amount {
                font-size: 20px;
            }
        }
    </style>
    <script>
        (function() {
            function enforcePureCheckout() {
                var selectors = [];
                <?php if (!$retain_hf): ?>
                selectors.push('header', 'footer', '.site-header', '.site-footer', 'header.wp-block-template-part', 'footer.wp-block-template-part');
                <?php endif; ?>
                <?php if (!$retain_policy): ?>
                selectors.push('.woocommerce-privacy-policy-text', '.woocommerce-terms-and-conditions-wrapper');
                <?php endif; ?>
                <?php if (!$retain_billing): ?>
                selectors.push('#customer_details', '.woocommerce-billing-fields');
                <?php endif; ?>
                <?php if (!$retain_products): ?>
                selectors.push('.woocommerce-checkout-review-order-table', '#order_review_heading');
                <?php endif; ?>

                for (var i = 0; i < selectors.length; i++) {
                    var elements = document.querySelectorAll(selectors[i]);
                    for (var j = 0; j < elements.length; j++) {
                        elements[j].style.setProperty('display', 'none', 'important');
                    }
                }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', enforcePureCheckout);
            } else {
                enforcePureCheckout();
            }
            // 兜底 AJAX 刷新结账区域
            if (window.jQuery) {
                jQuery(document).ready(function($) {
                    $(document.body).on('updated_checkout', enforcePureCheckout);
                });
            }
            setInterval(enforcePureCheckout, 250);
        })();
    </script>
    <?php
}

add_action('wp_head', 'wplpg_render_pure_checkout_assets', 99999);
add_action('wp_footer', 'wplpg_render_pure_checkout_assets', 99999);
add_action('woocommerce_before_checkout_form', 'wplpg_render_pure_checkout_assets', 1);

/**
 * 在支付网关模块上方输出使用站点语言的“总额”卡片
 */
add_action('woocommerce_review_order_before_payment', function () {
    if (!wplpg_is_pure_checkout()) {
        return;
    }
    $total_html = (function_exists('WC') && WC()->cart) ? WC()->cart->get_total() : '';
    ?>
    <div class=wplpg-payment-amount-banner>
        <span class=wplpg-banner-label><?php echo esc_html__('Total', 'woocommerce'); ?></span>
        <span class=wplpg-banner-amount><?php echo wp_kses_post($total_html); ?></span>
    </div>
    <?php
}, 10);

/**
 * 【跳过订单验证核心逻辑】
 * 1. 结账表单字段非必填化
 */
function wplpg_make_checkout_fields_optional($fields)
{
    if (!wplpg_is_skip_validation()) {
        return $fields;
    }

    if (is_array($fields)) {
        foreach ($fields as $key => $field) {
            if (is_array($field)) {
                if ($key !== 'billing_email') {
                    $fields[$key]['required'] = false;
                }
            }
        }
    }

    return $fields;
}
add_filter('woocommerce_billing_fields', 'wplpg_make_checkout_fields_optional', 9999);
add_filter('woocommerce_shipping_fields', 'wplpg_make_checkout_fields_optional', 9999);

add_filter('woocommerce_checkout_fields', function ($fields) {
    if (!wplpg_is_skip_validation() || !is_array($fields)) {
        return $fields;
    }

    foreach (['billing', 'shipping', 'account', 'order'] as $section) {
        if (isset($fields[$section]) && is_array($fields[$section])) {
            foreach ($fields[$section] as $key => $field) {
                if ($key !== 'billing_email') {
                    $fields[$section][$key]['required'] = false;
                }
            }
        }
    }

    return $fields;
}, 9999);

/**
 * 2. 绕过结账后端非核心字段验证报错
 */
add_action('woocommerce_after_checkout_validation', function ($data, $errors) {
    if (!wplpg_is_skip_validation() || !is_wp_error($errors)) {
        return;
    }

    $error_codes = $errors->get_error_codes();
    foreach ($error_codes as $code) {
        // 过滤掉所有地址、姓名、邮编、电话、国家、条款及隐私等报错
        if ($code !== 'payment_method_required') {
            $errors->remove($code);
        }
    }
}, 9999, 2);

/**
 * 3. 避免因无运费配置导致无法结账（标记为免配送）
 */
add_filter('woocommerce_cart_needs_shipping', function ($needs_shipping) {
    if (wplpg_is_skip_validation()) {
        return false;
    }
    return $needs_shipping;
}, 9999);

add_filter('woocommerce_cart_needs_shipping_address', function ($needs_shipping_address) {
    if (wplpg_is_skip_validation()) {
        return false;
    }
    return $needs_shipping_address;
}, 9999);

/**
 * 4. 允许访客免登录极速结算
 */
add_filter('woocommerce_checkout_must_be_logged_in', function ($must_be_logged_in) {
    if (wplpg_is_skip_validation()) {
        return false;
    }
    return $must_be_logged_in;
}, 9999);

/**
 * 5. 创建订单时自动填充默认占位字段，防止支付网关因字段为空报错
 */
add_action('woocommerce_checkout_create_order', function ($order, $data) {
    if (!wplpg_is_skip_validation() || !is_a($order, 'WC_Order')) {
        return;
    }

    if (!$order->get_billing_first_name()) {
        $order->set_billing_first_name('Guest');
    }
    if (!$order->get_billing_last_name()) {
        $order->set_billing_last_name('Customer');
    }
    if (!$order->get_billing_email()) {
        $order->set_billing_email('guest_' . time() . '@checkout.local');
    }
    if (!$order->get_billing_country()) {
        $order->set_billing_country('US');
    }
    if (!$order->get_billing_address_1()) {
        $order->set_billing_address_1('Direct Checkout');
    }
    if (!$order->get_billing_city()) {
        $order->set_billing_city('Online');
    }
    if (!$order->get_billing_postcode()) {
        $order->set_billing_postcode('00000');
    }
}, 20, 2);

/**
 * 获取随机可购买变体。
 */
function wplpg_get_random_variation_for_product($product_id)
{
    $product = wc_get_product($product_id);

    if (!$product || !$product->is_type('variable')) {
        return null;
    }

    $variation_ids = $product->get_children();
    if (empty($variation_ids)) {
        return null;
    }

    shuffle($variation_ids);

    foreach ($variation_ids as $variation_id) {
        $variation = wc_get_product($variation_id);

        if (
            !$variation ||
            !$variation->is_purchasable() ||
            !$variation->is_in_stock() ||
            (int) $variation->get_parent_id() !== (int) $product_id
        ) {
            continue;
        }

        $resolved = wplpg_resolve_variation_and_attributes($product_id, $variation_id);

        return [
            'product_id'      => absint($product_id),
            'variation_id'    => absint($variation_id),
            'attributes'      => $resolved['attributes'],
            'variation_label' => wp_strip_all_tags(
                wc_get_formatted_variation($variation, true)
            ),
        ];
    }

    // 兜底：若所有变体库存均为0，选取第一个变体并补齐属性
    $fallback_id = $variation_ids[0];
    $variation = wc_get_product($fallback_id);
    if ($variation && (int) $variation->get_parent_id() === (int) $product_id) {
        $resolved = wplpg_resolve_variation_and_attributes($product_id, $fallback_id);
        return [
            'product_id'      => absint($product_id),
            'variation_id'    => absint($fallback_id),
            'attributes'      => $resolved['attributes'],
            'variation_label' => wp_strip_all_tags(
                wc_get_formatted_variation($variation, true)
            ),
        ];
    }

    return null;
}

/**
 * AJAX：获取随机变体。
 */
function wplpg_ajax_get_variation()
{
    check_ajax_referer('wplpg_create_short_link', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('没有权限。');
    }

    $product_id = absint($_POST['product_id'] ?? 0);
    $product = wc_get_product($product_id);

    if (!$product || !$product->is_purchasable()) {
        wp_send_json_error('商品不存在或不可购买。');
    }

    if (!$product->is_type('variable')) {
        wp_send_json_success([
            'product_id'      => $product_id,
            'variation_id'    => 0,
            'attributes'      => [],
            'variation_label' => '',
        ]);
    }

    $variation = wplpg_get_random_variation_for_product($product_id);

    if (!$variation) {
        wp_send_json_error('未找到可购买的商品变体。');
    }

    wp_send_json_success($variation);
}
add_action('wp_ajax_wplpg_get_variation', 'wplpg_ajax_get_variation');

/**
 * AJAX：生成短链接。
 */
function wplpg_ajax_create_short_link()
{
    check_ajax_referer('wplpg_create_short_link', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('没有权限。');
    }

    $product_id = absint($_POST['product_id'] ?? 0);
    $amount = isset($_POST['amount'])
        ? (float) wc_format_decimal(wp_unslash($_POST['amount']))
        : 0;
    $variation_id = absint($_POST['variation_id'] ?? 0);
    $expire_seconds = isset($_POST['expire_seconds']) ? (int) $_POST['expire_seconds'] : 86400;
    $skip_validation = isset($_POST['skip_validation']) ? (int) $_POST['skip_validation'] : 1;
    $hide_details = isset($_POST['hide_details']) ? (int) $_POST['hide_details'] : 1;

    if ($hide_details) {
        $skip_validation = 1;
    }

    if (
        !$product_id ||
        !is_finite($amount) ||
        $amount <= 0
    ) {
        wp_send_json_error('产品 ID 或付款金额无效。');
    }

    $product = wc_get_product($product_id);

    if (!$product || !$product->is_purchasable()) {
        wp_send_json_error('商品不存在或不可购买。');
    }

    $attributes = [];

    if ($product->is_type('variable')) {
        $passed_attributes = isset($_POST['attributes']) ? json_decode(wp_unslash($_POST['attributes']), true) : [];
        $resolved = wplpg_resolve_variation_and_attributes($product_id, $variation_id, is_array($passed_attributes) ? $passed_attributes : []);
        $variation_id = $resolved['variation_id'];
        $attributes   = $resolved['attributes'];

        if (!$variation_id) {
            wp_send_json_error('未找到有效的商品变体。');
        }
    } else {
        $variation_id = 0;
        $attributes = [];
    }

    $retain_products      = !empty($_POST['retain_products']) ? 1 : 0;
    $retain_billing       = !empty($_POST['retain_billing']) ? 1 : 0;
    $retain_header_footer = !empty($_POST['retain_header_footer']) ? 1 : 0;
    $retain_policy        = !empty($_POST['retain_policy']) ? 1 : 0;

    $link = wplpg_create_short_link(
        $product_id,
        $amount,
        $variation_id,
        $attributes,
        $expire_seconds,
        $skip_validation,
        $hide_details,
        $retain_products,
        $retain_billing,
        $retain_header_footer,
        $retain_policy
    );

    $expire_text = $expire_seconds > 0
        ? wp_date('Y-m-d H:i:s', time() + $expire_seconds) . '（' . wplpg_format_duration($expire_seconds) . '内有效）'
        : '永久有效';

    wp_send_json_success([
        'link'                 => $link,
        'expire_text'          => $expire_text,
        'expire_secs'          => $expire_seconds,
        'skip_validation'      => $skip_validation,
        'hide_details'         => $hide_details,
        'retain_products'      => $retain_products,
        'retain_billing'       => $retain_billing,
        'retain_header_footer' => $retain_header_footer,
        'retain_policy'        => $retain_policy,
    ]);
}
add_action('wp_ajax_wplpg_create_short_link', 'wplpg_ajax_create_short_link');

/**
 * 保留旧版参数链接功能。
 */
add_action('wp_loaded', function () {
    if (!isset($_GET['add-to-cart'])) {
        return;
    }

    if (wplpg_is_payment_link_request()) {
        wplpg_set_session(
            'custom_payment_amount',
            (float) wc_format_decimal(wp_unslash($_GET['amount']))
        );

        wplpg_set_session(
            'custom_payment_product',
            absint($_GET['add-to-cart'])
        );
    } else {
        wplpg_unset_session('custom_payment_amount');
        wplpg_unset_session('custom_payment_product');
    }
});

/**
 * 保存旧版链接自定义金额。
 */
add_filter(
    'woocommerce_add_cart_item_data',
    function ($cart_item_data, $product_id) {
        if (!wplpg_is_payment_link_request()) {
            return $cart_item_data;
        }

        $requested_product_id = absint($_GET['add-to-cart']);
        $amount = (float) wc_format_decimal(wp_unslash($_GET['amount']));
        $product = wc_get_product($requested_product_id);

        if (
            !$product ||
            !$product->is_purchasable() ||
            $requested_product_id !== (int) $product_id ||
            !is_finite($amount) ||
            $amount <= 0
        ) {
            return $cart_item_data;
        }

        $cart_item_data['custom_payment_amount'] = $amount;
        $cart_item_data['custom_payment_product'] = $requested_product_id;
        $cart_item_data['wplpg_payment_link'] = true;

        return $cart_item_data;
    },
    10,
    2
);

/**
 * 从 Session 恢复购物车数据。
 */
add_filter(
    'woocommerce_get_cart_item_from_session',
    function ($cart_item, $values) {
        foreach (
            [
                'custom_payment_amount',
                'custom_payment_product',
                'wplpg_payment_link',
                'wplpg_token',
                'wplpg_skip_validation',
                'wplpg_hide_details',
                'wplpg_retain_products',
                'wplpg_retain_billing',
                'wplpg_retain_header_footer',
                'wplpg_retain_policy',
            ] as $key
        ) {
            if (isset($values[$key])) {
                $cart_item[$key] = $values[$key];
            }
        }

        if (
            !empty($cart_item['wplpg_payment_link']) &&
            isset($cart_item['custom_payment_amount']) &&
            !empty($cart_item['data']) &&
            is_callable([$cart_item['data'], 'set_price'])
        ) {
            $cart_item['data']->set_price((float) $cart_item['custom_payment_amount']);
        }

        return $cart_item;
    },
    20,
    2
);

/**
 * 应用自定义价格。
 */
function wplpg_apply_custom_price($cart_item)
{
    if (
        empty($cart_item['wplpg_payment_link']) ||
        empty($cart_item['data']) ||
        !isset($cart_item['custom_payment_amount'])
    ) {
        return $cart_item;
    }

    $amount = (float) $cart_item['custom_payment_amount'];

    if (
        $amount > 0 &&
        is_callable([$cart_item['data'], 'set_price'])
    ) {
        $cart_item['data']->set_price($amount);
    }

    return $cart_item;
}

add_filter('woocommerce_add_cart_item', 'wplpg_apply_custom_price', 20);
add_filter('woocommerce_cart_item_from_session', 'wplpg_apply_custom_price', 20);

add_action('woocommerce_before_calculate_totals', function ($cart) {
    if (is_admin() && !defined('DOING_AJAX')) {
        return;
    }

    foreach ($cart->get_cart() as $cart_item) {
        wplpg_apply_custom_price($cart_item);
    }
}, 20);

/**
 * 旧版付款链接清空原购物车。
 */
add_filter(
    'woocommerce_add_to_cart_validation',
    function ($passed, $product_id) {
        if (
            !wplpg_is_payment_link_request() ||
            !function_exists('WC') ||
            !WC()->cart
        ) {
            return $passed;
        }

        if (absint($_GET['add-to-cart']) !== (int) $product_id) {
            return $passed;
        }

        WC()->cart->empty_cart();

        return $passed;
    },
    10,
    2
);

add_action('woocommerce_cart_emptied', function () {
    wplpg_unset_session('custom_payment_amount');
    wplpg_unset_session('custom_payment_product');
    wplpg_unset_session('wplpg_skip_validation');
    wplpg_unset_session('wplpg_hide_details');
    wplpg_unset_session('wplpg_retain_products');
    wplpg_unset_session('wplpg_retain_billing');
    wplpg_unset_session('wplpg_retain_header_footer');
    wplpg_unset_session('wplpg_retain_policy');
});

/**
 * 插件自动更新类（通过 GitHub Releases 实现多站点一键检查与无缝覆盖升级）。
 */
class WPLPG_Plugin_Updater
{
    protected $plugin_file;
    protected $plugin_basename;
    protected $slug;
    protected $version;
    protected $github_repo;

    public function __construct($plugin_file, $version = '', $github_repo = 'wuyou2001/wc-payment-link-generator')
    {
        $this->plugin_file = $plugin_file;
        $this->plugin_basename = plugin_basename($plugin_file);
        $this->slug = dirname($this->plugin_basename);
        if ($this->slug === '.' || empty($this->slug)) {
            $this->slug = 'wc-payment-link-generator';
        }

        // 单点真实源：自动从主文件头动态解析版本号，杜绝版本号不一致
        if (empty($version)) {
            if (function_exists('get_file_data')) {
                $file_data = get_file_data($plugin_file, ['Version' => 'Version']);
                $version = !empty($file_data['Version']) ? $file_data['Version'] : '1.0.0';
            } else {
                $content = @file_get_contents($plugin_file, false, null, 0, 8192);
                if ($content && preg_match('/^[ 	\/*#@]*Version:(.*)$/mi', $content, $match)) {
                    $version = trim($match[1]);
                } else {
                    $version = '1.0.0';
                }
            }
        }
        $this->version = ltrim($version, 'vV');
        $this->github_repo = trim((string) get_option('wplpg_github_repo', $github_repo));
        if (empty($this->github_repo)) {
            $this->github_repo = $github_repo;
        }

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_popup'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'source_selection'], 10, 4);
        add_filter('upgrader_post_install', [$this, 'post_install'], 10, 3);
    }

    public function get_github_repo()
    {
        $repo = trim((string) get_option('wplpg_github_repo', $this->github_repo));
        return !empty($repo) ? $repo : $this->github_repo;
    }

    public function get_release_info($force_check = false)
    {
        $transient_key = 'wplpg_gh_release_' . md5($this->get_github_repo());
        if (!$force_check) {
            $cached = get_transient($transient_key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $repo = $this->get_github_repo();
        $headers = [
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            'headers'    => [
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ];

        // 1. 优先请求 GitHub Releases
        $api_url = 'https://api.github.com/repos/' . $repo . '/releases/latest';
        $response = wp_remote_get($api_url, $headers);

        if (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($data) && !empty($data['tag_name'])) {
                set_transient($transient_key, $data, 12 * HOUR_IN_SECONDS);
                return $data;
            }
        }

        // 2. 兜底容错：若未创建正式 Release 页面，自动读取最新的 Git Tag 触发更新
        $tags_url = 'https://api.github.com/repos/' . $repo . '/tags';
        $tags_response = wp_remote_get($tags_url, $headers);

        if (!is_wp_error($tags_response) && wp_remote_retrieve_response_code($tags_response) === 200) {
            $tags_data = json_decode(wp_remote_retrieve_body($tags_response), true);
            if (is_array($tags_data) && !empty($tags_data[0]['name'])) {
                $latest_tag = $tags_data[0];
                $data = [
                    'tag_name'     => $latest_tag['name'],
                    'html_url'     => 'https://github.com/' . $repo . '/releases/tag/' . rawurlencode($latest_tag['name']),
                    'body'         => '版本 ' . $latest_tag['name'] . ' 更新（包含最新性能优化与修复）。',
                    'zipball_url'  => 'https://raw.githubusercontent.com/' . $repo . '/' . rawurlencode($latest_tag['name']) . '/wc-payment-link-generator.zip',
                    'assets'       => [
                        [
                            'name' => 'wc-payment-link-generator.zip',
                            'browser_download_url' => 'https://raw.githubusercontent.com/' . $repo . '/' . rawurlencode($latest_tag['name']) . '/wc-payment-link-generator.zip',
                        ],
                        [
                            'name' => 'package.zip',
                            'browser_download_url' => 'https://raw.githubusercontent.com/' . $repo . '/main/wc-payment-link-generator.zip',
                        ]
                    ],
                    'published_at' => gmdate('Y-m-d H:i:s'),
                ];
                set_transient($transient_key, $data, 12 * HOUR_IN_SECONDS);
                return $data;
            }
        }

        return false;
    }

    public function check_update($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_release_info();
        if (!$release) {
            return $transient;
        }

        $new_version = ltrim($release['tag_name'], 'vV');
        if (version_compare($new_version, $this->version, '>')) {
            $package_url = '';
            if (!empty($release['assets']) && is_array($release['assets'])) {
                foreach ($release['assets'] as $asset) {
                    if (isset($asset['name']) && preg_match('/\.zip$/i', $asset['name'])) {
                        $package_url = $asset['browser_download_url'];
                        break;
                    }
                }
            }
            if (empty($package_url) && !empty($release['zipball_url'])) {
                $package_url = $release['zipball_url'];
            }

            $item = (object) [
                'id'            => $this->plugin_basename,
                'slug'          => $this->slug,
                'plugin'        => $this->plugin_basename,
                'new_version'   => $new_version,
                'url'           => $release['html_url'] ?? ('https://github.com/' . $this->get_github_repo()),
                'package'       => $package_url,
                'icons'         => [],
                'banners'       => [],
                'banners_rtl'   => [],
                'tested'        => '',
                'requires_php'  => '7.4',
                'compatibility' => new stdClass(),
            ];

            $transient->response[$this->plugin_basename] = $item;
        }

        return $transient;
    }

    public function plugin_popup($result, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (empty($args->slug) || ($args->slug !== $this->slug && $args->slug !== $this->plugin_basename)) {
            return $result;
        }

        $release = $this->get_release_info();
        if (!$release) {
            return $result;
        }

        $new_version = ltrim($release['tag_name'], 'vV');
        $changelog = !empty($release['body']) ? nl2br(esc_html($release['body'])) : '暂无详细更新说明。';

        $res = new stdClass();
        $res->name           = 'WooCommerce 付款链接生成器';
        $res->slug           = $this->slug;
        $res->version        = $new_version;
        $res->author         = '<a href="https://github.com/' . esc_attr($this->get_github_repo()) . '">Wwnine</a>';
        $res->homepage       = 'https://github.com/' . esc_attr($this->get_github_repo());
        $res->requires       = '5.4';
        $res->tested         = '6.7';
        $res->requires_php   = '7.4';
        $res->last_updated   = $release['published_at'] ?? gmdate('Y-m-d H:i:s');
        $res->sections       = [
            'description' => '在 WooCommerce 后台生成自定义金额付款链接，支持短链接、跳过订单验证与纯净结账模式，全主题自适应并支持 GitHub 一键自动更新。',
            'changelog'   => $changelog,
        ];

        return $res;
    }

    /**
     * 自动校验与校正解压目录（防止 GitHub zip 嵌套子文件夹或目录名不匹配导致安装失败）。
     */
    public function source_selection($source, $remote_source, $upgrader, $hook_extra = [])
    {
        global $wp_filesystem;

        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $source;
        }

        // 如果解压目录里嵌套了 wc-payment-link-generator 目录，提升该子目录
        $nested_dir = trailingslashit($source) . $this->slug;
        if ($wp_filesystem->is_dir($nested_dir)) {
            return trailingslashit($nested_dir);
        }

        // 纠偏解压根目录为插件标准 slug 目录名
        $corrected_source = trailingslashit($remote_source) . $this->slug . '/';
        if ($source !== $corrected_source) {
            $wp_filesystem->move($source, $corrected_source);
            return $corrected_source;
        }

        return $source;
    }

    public function post_install($true, $hook_extra, $result)
    {
        global $wp_filesystem;

        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_basename) {
            return $result;
        }

        $proper_destination = trailingslashit(WP_PLUGIN_DIR) . $this->slug;
        if (trailingslashit($result['destination']) !== trailingslashit($proper_destination)) {
            $wp_filesystem->move($result['destination'], $proper_destination, true);
            $result['destination'] = $proper_destination;
        }

        activate_plugin($this->plugin_basename);
        return $result;
    }
}

/**
 * 实例化自动更新器
 */
function wplpg_get_updater_instance()
{
    static $instance = null;
    if ($instance === null) {
        $instance = new WPLPG_Plugin_Updater(__FILE__, '', 'wuyou2001/wc-payment-link-generator');
    }
    return $instance;
}
add_action('init', function () {
    if (is_admin()) {
        wplpg_get_updater_instance();
    }
});

/**
 * 后台菜单。
 */
add_action('admin_menu', function () {
    add_menu_page(
        '生成付款链接',
        '生成付款链接',
        'manage_woocommerce',
        'generate-payment-link',
        'generate_payment_link_page',
        'dashicons-cart',
        56
    );
});

/**
 * 后台页面。
 */
function generate_payment_link_page()
{
    if (
        isset($_POST['wplpg_save_checkout_slug']) &&
        check_admin_referer('wplpg_save_checkout_slug')
    ) {
        $checkout_page_id = wc_get_page_id('checkout');
        $new_slug = sanitize_title(
            wp_unslash($_POST['wplpg_checkout_slug'] ?? '')
        );


        if (
            $checkout_page_id > 0 &&
            $new_slug &&
            current_user_can('edit_post', $checkout_page_id)
        ) {
            $updated = wp_update_post(
                [
                    'ID'        => $checkout_page_id,
                    'post_name' => $new_slug,
                ],
                true
            );

            if (!is_wp_error($updated)) {
                flush_rewrite_rules(false);

                echo '<div class="notice notice-success is-dismissible"><p>' .
                    esc_html('Checkout 页面 Slug 已保存。') .
                    '</p></div>';
            } else {
                echo '<div class="notice notice-error is-dismissible"><p>' .
                    esc_html($updated->get_error_message()) .
                    '</p></div>';
            }
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>' .
                esc_html('Slug 无效，或未找到 Checkout 页面。') .
                '</p></div>';
        }
    }

    $checkout_page_id = wc_get_page_id('checkout');
    $checkout_slug = $checkout_page_id > 0
        ? get_post_field('post_name', $checkout_page_id)
        : 'checkout';

    $checkout_url = wc_get_checkout_url();
    $random_products = [];

    $products = wc_get_products([
        'status'  => 'publish',
        'limit'   => 100,
        'orderby' => 'rand',
    ]);

    foreach ($products as $product) {
        if ($product->is_purchasable()) {
            $random_products[] = [
                'id'    => $product->get_id(),
                'title' => wp_strip_all_tags($product->get_name()),
            ];
        }
    }

    $ajax_url = admin_url('admin-ajax.php');
    $nonce = wp_create_nonce('wplpg_create_short_link');
?>

    <div class="wrap wplpg-admin">
        <h1>WooCommerce 一键付款链接生成器</h1>

        <div class="wplpg-grid">
            <section class="wplpg-card">
                <div class="wplpg-card-header">
                    <h2>生成付款链接</h2>
                    <p>输入商品 ID 和付款金额，生成隐藏参数的短链接。</p>
                </div>

                <div class="wplpg-card-body">
                    <form id="wplpg-form">
                        <div class="wplpg-field">
                            <label for="wplpg-product-id">产品 ID</label>
                            <div class="wplpg-input-group">
                                <input
                                    type="number"
                                    id="wplpg-product-id"
                                    min="1"
                                    placeholder="留空随机选择商品">
                                <button
                                    type="button"
                                    id="wplpg-random-product"
                                    class="button">
                                    随机商品
                                </button>
                            </div>
                            <p id="wplpg-product-label" class="wplpg-help"></p>
                        </div>

                        <div class="wplpg-field">
                            <label for="wplpg-amount">付款金额</label>
                            <input
                                type="number"
                                id="wplpg-amount"
                                min="0.01"
                                step="0.01"
                                required>
                        </div>

                        <div class="wplpg-field">
                            <label for="wplpg-expire-preset">短链接有效时间</label>
                            <select id="wplpg-expire-preset">
                                <option value="3600">1 小时</option>
                                <option value="21600">6 小时</option>
                                <option value="86400" selected>24 小时（1 天，默认推荐）</option>
                                <option value="259200">3 天</option>
                                <option value="604800">7 天</option>
                                <option value="2592000">30 天</option>
                                <option value="custom">自定义时长...</option>
                                <option value="0">永久有效（不限时）</option>
                            </select>
                            <div id="wplpg-custom-expire-wrap" style="margin-top: 10px; display: none;">
                                <div class="wplpg-input-group">
                                    <input type="number" id="wplpg-custom-expire-value" min="1" value="2" placeholder="数值">
                                    <select id="wplpg-custom-expire-unit">
                                        <option value="3600">小时</option>
                                        <option value="86400">天</option>
                                    </select>
                                </div>
                            </div>
                            <p class="wplpg-help">
                                在限定时间内，用户随时打开该短链接均可正常结算，金额与商品持续有效。
                            </p>
                        </div>

                        <div class="wplpg-field">
                            <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="wplpg-hide-details" value="1" checked style="width: 18px; height: 18px; margin: 0;">
                                <span><strong>全主题通用纯净结账模式</strong>（自动精简干扰元素，呈现居中纯支付界面）</span>
                            </label>
                            <p class="wplpg-help">
                                适用于任何主题或 FSE 模板。默认隐藏全站头尾、地址与详情；您可通过下方选项自由勾选需要保留的内容。
                            </p>

                            <div id="wplpg-retention-options-wrap" style="margin-left: 26px; margin-top: 12px; padding: 14px 18px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px;">
                                <div style="margin-bottom: 10px; font-weight: 600; color: #1e293b; font-size: 13px;">
                                    可选保留结账页面内容（按需勾选保留，默认全隐藏）：
                                </div>
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                                        <input type="checkbox" id="wplpg-retain-products" value="1" style="width: 16px; height: 16px; margin: 0;">
                                        <span>保留产品信息（明细表格）</span>
                                    </label>
                                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                                        <input type="checkbox" id="wplpg-retain-billing" value="1" style="width: 16px; height: 16px; margin: 0;">
                                        <span>保留账单地址（免必填）</span>
                                    </label>
                                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                                        <input type="checkbox" id="wplpg-retain-header-footer" value="1" style="width: 16px; height: 16px; margin: 0;">
                                        <span>保留页首与页脚</span>
                                    </label>
                                    <label style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer;">
                                        <input type="checkbox" id="wplpg-retain-policy" value="1" style="width: 16px; height: 16px; margin: 0;">
                                        <span>保留隐私政策与条款</span>
                                    </label>
                                </div>
                                <p class="wplpg-help" style="margin-top: 8px; margin-bottom: 0;">
                                    提示：若勾选“保留账单地址”，页面宽度将自适应扩展为 1000px，且客户仍可免必填快速提交。
                                </p>
                            </div>
                        </div>

                        <div class="wplpg-field">
                            <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer;">
                                <input type="checkbox" id="wplpg-skip-validation" value="1" checked style="width: 18px; height: 18px; margin: 0;">
                                <span><strong>跳过订单验证</strong>（免填详细地址，支持极速结账与访客免登录）</span>
                            </label>
                            <p class="wplpg-help">
                                开启后，免除必填地址、电话、邮编与运费配送限制，直接提交付款。
                            </p>
                        </div>

                        <button
                            type="submit"
                            class="button button-primary button-large">
                            生成付款链接
                        </button>
                    </form>

                    <div id="wplpg-result" hidden></div>
                </div>
            </section>

            <aside class="wplpg-card">
                <div class="wplpg-card-header">
                    <h2>Checkout 页面设置</h2>
                    <p>此设置会直接修改 WooCommerce Checkout 页面。</p>
                </div>

                <div class="wplpg-card-body">
                    <form method="post">
                        <?php wp_nonce_field('wplpg_save_checkout_slug'); ?>

                        <div class="wplpg-field">
                            <label for="wplpg-checkout-slug">
                                Checkout 页面 Slug
                            </label>
                            <input
                                type="text"
                                id="wplpg-checkout-slug"
                                name="wplpg_checkout_slug"
                                value="<?php echo esc_attr($checkout_slug); ?>"
                                placeholder="checkout"
                                required>
                            <p class="wplpg-help">
                                例如：checkout-paypal
                            </p>
                        </div>

                        <button
                            type="submit"
                            name="wplpg_save_checkout_slug"
                            class="button">
                            保存 Checkout Slug
                        </button>
                    </form>

                    <div class="wplpg-info">
                        <strong>当前结账页面地址</strong>
                        <code><?php echo esc_html($checkout_url); ?></code>
                    </div>

                    <div class="wplpg-info">
                        <strong>短链接示例</strong>
                        <code>
                            <?php echo esc_html(
                                trailingslashit(home_url()) .
                                    $checkout_slug . '-随机Token/'
                            ); ?>
                        </code>
                    </div>
                </div>
            </aside>


        </div>
    </div>

    <style>
        .wplpg-admin {
            max-width: 1200px;
        }

        .wplpg-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(300px, 1fr);
            gap: 24px;
            margin-top: 20px;
        }

        .wplpg-card {
            overflow: hidden;
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0, 0, 0, .04);
        }

        .wplpg-card-header {
            padding: 20px 24px;
            background: #f6f7f7;
            border-bottom: 1px solid #dcdcde;
        }

        .wplpg-card-header h2 {
            margin: 0 0 6px;
            font-size: 17px;
        }

        .wplpg-card-header p {
            margin: 0;
            color: #646970;
        }

        .wplpg-card-body {
            padding: 24px;
        }

        .wplpg-field {
            margin-bottom: 22px;
        }

        .wplpg-field label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
        }

        .wplpg-field input[type="text"],
        .wplpg-field input[type="number"],
        .wplpg-field select {
            width: 100%;
            max-width: 420px;
            min-height: 40px;
            box-sizing: border-box;
            border-radius: 4px;
        }

        .wplpg-input-group {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .wplpg-input-group input {
            flex: 1;
        }

        .wplpg-input-group select {
            width: auto;
            min-width: 100px;
        }

        .wplpg-help {
            min-height: 18px;
            margin: 7px 0 0;
            color: #646970;
            font-size: 13px;
        }

        #wplpg-result {
            margin-top: 24px;
            padding: 16px;
            word-break: break-word;
        }

        #wplpg-result a {
            display: inline-block;
            max-width: 100%;
            margin-top: 6px;
            word-break: break-all;
        }

        #wplpg-result button {
            margin-top: 8px;
        }

        .wplpg-info {
            margin-top: 24px;
            padding-top: 18px;
            border-top: 1px solid #dcdcde;
        }

        .wplpg-info strong {
            display: block;
            margin-bottom: 8px;
        }

        .wplpg-info code {
            display: block;
            padding: 10px;
            overflow-wrap: anywhere;
            background: #f6f7f7;
            border: 1px solid #dcdcde;
            border-radius: 4px;
        }

        @media (max-width: 800px) {
            .wplpg-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 520px) {

            .wplpg-card-body,
            .wplpg-card-header {
                padding: 18px;
            }

            .wplpg-input-group {
                display: block;
            }

            .wplpg-input-group input,
            .wplpg-input-group select,
            .wplpg-input-group button {
                width: 100%;
                max-width: none;
            }

            .wplpg-input-group button,
            .wplpg-input-group select {
                margin-top: 10px;
            }
        }
    </style>

    <script>
        (function() {
            const products = <?php echo wp_json_encode($random_products); ?>;
            const ajaxUrl = <?php echo wp_json_encode($ajax_url); ?>;
            const nonce = <?php echo wp_json_encode($nonce); ?>;

            const form = document.getElementById('wplpg-form');
            const productInput = document.getElementById('wplpg-product-id');
            const amountInput = document.getElementById('wplpg-amount');
            const expirePreset = document.getElementById('wplpg-expire-preset');
            const customExpireWrap = document.getElementById('wplpg-custom-expire-wrap');
            const customExpireValue = document.getElementById('wplpg-custom-expire-value');
            const customExpireUnit = document.getElementById('wplpg-custom-expire-unit');
            const hideDetailsCheckbox = document.getElementById('wplpg-hide-details');
            const skipValidationCheckbox = document.getElementById('wplpg-skip-validation');
            const retentionOptionsWrap = document.getElementById('wplpg-retention-options-wrap');
            const retainProductsCheckbox = document.getElementById('wplpg-retain-products');
            const retainBillingCheckbox = document.getElementById('wplpg-retain-billing');
            const retainHeaderFooterCheckbox = document.getElementById('wplpg-retain-header-footer');
            const retainPolicyCheckbox = document.getElementById('wplpg-retain-policy');
            const productLabel = document.getElementById('wplpg-product-label');
            const result = document.getElementById('wplpg-result');

            if (hideDetailsCheckbox && retentionOptionsWrap) {
                hideDetailsCheckbox.addEventListener('change', function() {
                    retentionOptionsWrap.style.display = this.checked ? 'block' : 'none';
                });
            }

            function getExpireSeconds() {
                const preset = expirePreset.value;
                if (preset === 'custom') {
                    const val = parseInt(customExpireValue.value, 10) || 1;
                    const unit = parseInt(customExpireUnit.value, 10) || 3600;
                    return Math.max(60, val * unit);
                }
                return parseInt(preset, 10);
            }

            expirePreset.addEventListener('change', function() {
                if (this.value === 'custom') {
                    customExpireWrap.style.display = 'block';
                } else {
                    customExpireWrap.style.display = 'none';
                }
            });

            hideDetailsCheckbox.addEventListener('change', function() {
                if (this.checked) {
                    skipValidationCheckbox.checked = true;
                }
            });

            function showMessage(message, type) {
                result.hidden = false;
                result.className = 'notice notice-' + type + ' inline';
                result.textContent = message;
            }

            function chooseRandomProduct() {
                if (!products.length) {
                    showMessage('未找到可用商品。', 'error');
                    return false;
                }

                const item = products[
                    Math.floor(Math.random() * products.length)
                ];

                productInput.value = item.id;
                productLabel.textContent =
                    '当前商品：' + item.title + ' (#' + item.id + ')';

                return true;
            }

            function request(action, data) {
                data.action = action;
                data.nonce = nonce;

                return fetch(ajaxUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
                    },
                    body: new URLSearchParams(data)
                }).then(function(response) {
                    return response.json();
                });
            }

            function copyText(text) {
                if (
                    navigator.clipboard &&
                    navigator.clipboard.writeText
                ) {
                    return navigator.clipboard.writeText(text);
                }

                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.left = '-9999px';

                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                textarea.remove();

                return Promise.resolve();
            }

            document
                .getElementById('wplpg-random-product')
                .addEventListener('click', function() {
                    chooseRandomProduct();
                    result.hidden = true;
                });

            form.addEventListener('submit', function(event) {
                event.preventDefault();

                if (!productInput.value && !chooseRandomProduct()) {
                    return;
                }

                const amount = amountInput.value.trim();
                const expireSeconds = getExpireSeconds();
                const hideDetails = hideDetailsCheckbox.checked ? 1 : 0;
                const skipValidation = (skipValidationCheckbox.checked || hideDetails) ? 1 : 0;
                const retainProducts = (hideDetails && retainProductsCheckbox && retainProductsCheckbox.checked) ? 1 : 0;
                const retainBilling = (hideDetails && retainBillingCheckbox && retainBillingCheckbox.checked) ? 1 : 0;
                const retainHeaderFooter = (hideDetails && retainHeaderFooterCheckbox && retainHeaderFooterCheckbox.checked) ? 1 : 0;
                const retainPolicy = (hideDetails && retainPolicyCheckbox && retainPolicyCheckbox.checked) ? 1 : 0;

                if (!amount || parseFloat(amount) <= 0) {
                    showMessage('请输入有效的付款金额。', 'error');
                    return;
                }

                request('wplpg_get_variation', {
                        product_id: productInput.value
                    })
                    .then(function(variationResponse) {
                        if (!variationResponse.success) {
                            throw new Error(
                                variationResponse.data || '获取商品信息失败。'
                            );
                        }

                        const variation = variationResponse.data;

                        return request('wplpg_create_short_link', {
                            product_id: productInput.value,
                            amount: amount,
                            variation_id: variation.variation_id || 0,
                            attributes: JSON.stringify(
                                variation.attributes || {}
                            ),
                            expire_seconds: expireSeconds,
                            skip_validation: skipValidation,
                            hide_details: hideDetails,
                            retain_products: retainProducts,
                            retain_billing: retainBilling,
                            retain_header_footer: retainHeaderFooter,
                            retain_policy: retainPolicy
                        });
                    })
                    .then(function(response) {
                        if (!response.success) {
                            throw new Error(response.data || '生成链接失败。');
                        }

                        const link = response.data.link;
                        const expireText = response.data.expire_text || '';
                        const isHide = response.data.hide_details === 1;
                        const isSkip = response.data.skip_validation === 1;

                        result.hidden = false;
                        result.className = 'notice notice-success inline';
                        result.replaceChildren();

                        const paragraph = document.createElement('p');
                        paragraph.textContent = '付款链接：';

                        const anchor = document.createElement('a');
                        anchor.href = link;
                        anchor.target = '_blank';
                        anchor.rel = 'noopener noreferrer';
                        anchor.textContent = link;

                        paragraph.appendChild(document.createElement('br'));
                        paragraph.appendChild(anchor);
                        result.appendChild(paragraph);

                        const metaDiv = document.createElement('div');
                        metaDiv.style.marginTop = '8px';
                        metaDiv.style.fontSize = '13px';
                        metaDiv.style.color = '#50575e';

                        if (expireText) {
                            const expireP = document.createElement('p');
                            expireP.style.margin = '4px 0';
                            expireP.innerHTML = '<strong>有效时间：</strong>' + escapeHtml(expireText);
                            metaDiv.appendChild(expireP);
                        }

                        const modeP = document.createElement('p');
                        modeP.style.margin = '4px 0';
                        let modeDesc = '标准结账界面';
                        if (isHide) {
                            const retainedList = [];
                            if (response.data.retain_products) retainedList.push('产品信息');
                            if (response.data.retain_billing) retainedList.push('账单地址');
                            if (response.data.retain_header_footer) retainedList.push('页首页脚');
                            if (response.data.retain_policy) retainedList.push('隐私政策');

                            if (retainedList.length > 0) {
                                modeDesc = '<span style="color:#008a20;font-weight:600;">纯净结账（已选择保留：' + escapeHtml(retainedList.join('、')) + '）</span>';
                            } else {
                                modeDesc = '<span style="color:#008a20;font-weight:600;">全主题纯净模式（全隐藏，仅展示支付模块）</span>';
                            }
                        }
                        modeP.innerHTML = '<strong>结账模式：</strong>' + modeDesc;
                        metaDiv.appendChild(modeP);

                        const skipP = document.createElement('p');
                        skipP.style.margin = '4px 0';
                        skipP.innerHTML = '<strong>订单验证：</strong>' + (isSkip ? '<span style="color:#008a20;font-weight:600;">已跳过（免填详细地址，支持极速结算）</span>' : '标准验证');
                        metaDiv.appendChild(skipP);

                        result.appendChild(metaDiv);

                        const button = document.createElement('button');
                        button.type = 'button';
                        button.className = 'button';
                        button.textContent = '复制链接';

                        const feedback = document.createElement('span');
                        feedback.style.marginLeft = '12px';

                        button.addEventListener('click', function() {
                            copyText(link).then(function() {
                                feedback.textContent = '已复制';
                            });
                        });

                        result.appendChild(button);
                        result.appendChild(feedback);
                    })
                    .catch(function(error) {
                        showMessage(
                            error.message || '生成付款链接失败。',
                            'error'
                        );
                    });
            });

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }
        })();
    </script>
<?php
}
