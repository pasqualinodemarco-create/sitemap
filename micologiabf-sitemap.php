<?php
/**
 * Plugin Name: Esporta sitemap Micologia BF
 * Description: Scarica una sitemap XML dei contenuti pubblici dell'Archivio Micologico e di WordPress.
 * Version: 1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_menu', static function () {
    add_management_page('Esporta sitemap', 'Esporta sitemap', 'manage_options', 'micologiabf-sitemap', static function () {
        if (!current_user_can('manage_options')) {
            wp_die('Operazione non autorizzata.');
        }
        ?>
        <div class="wrap">
            <h1>Esporta sitemap</h1>
            <p>Scarica gli URL delle pagine, degli articoli e delle schede fungo pubblicati. Sono esclusi i contenuti protetti da password.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="micologiabf_download_sitemap">
                <?php wp_nonce_field('micologiabf_download_sitemap'); ?>
                <?php submit_button('Scarica sitemap.xml'); ?>
            </form>
        </div>
        <?php
    });
});

function micologiabf_sitemap_xml() {
    $urls = [home_url('/') => ''];
    $types = array_values(array_filter(['page', 'post', 'am_fungo', 'am_articolo'], 'is_post_type_viewable'));
    $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
    for ($page = 1; ; $page++) {
        $query = new WP_Query([
            'post_type' => $types,
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => 250,
            'paged' => $page,
            'orderby' => 'ID',
            'order' => 'ASC',
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
        ]);
        foreach ($query->posts as $post) {
            $url = get_permalink($post);
            if (!$url || strtolower((string) wp_parse_url($url, PHP_URL_HOST)) !== $host) {
                continue;
            }
            $urls[$url] = get_post_modified_time(DATE_W3C, true, $post) ?: '';
            if (count($urls) > 50000) {
                return new WP_Error('sitemap_limit', 'Più di 50.000 URL. Usa un indice con sitemap separate.');
            }
        }
        if (count($query->posts) < 250) {
            break;
        }
    }

    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
    foreach ($urls as $url => $modified) {
        $xml .= '  <url><loc>' . esc_xml($url) . '</loc>';
        if ($modified !== '') {
            $xml .= '<lastmod>' . esc_xml($modified) . '</lastmod>';
        }
        $xml .= "</url>\n";
        if (strlen($xml) > 50 * 1024 * 1024 - 10) {
            return new WP_Error('sitemap_size', 'Il file supera 50 MB. Usa un indice con sitemap separate.');
        }
    }
    return $xml . "</urlset>\n";
}

add_action('admin_post_micologiabf_download_sitemap', static function () {
    if (!current_user_can('manage_options') || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        wp_die('Operazione non autorizzata.', '', ['response' => 403]);
    }
    check_admin_referer('micologiabf_download_sitemap');
    $xml = micologiabf_sitemap_xml();
    if (is_wp_error($xml)) {
        wp_die(esc_html($xml->get_error_message()));
    }
    nocache_headers();
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="sitemap.xml"');
    echo $xml;
    exit;
});
