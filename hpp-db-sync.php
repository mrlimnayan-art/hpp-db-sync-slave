<?php
/**
 * Plugin Name: HangPoPok DB Sync (Slave Version)
 * Description: ប្រព័ន្ធទទួលទិន្នន័យពី Master (Post All Site) និងគ្រប់គ្រងស្តុក (Auto-Update ពី GitHub)។
 * Version: 1.0
 * Author: WP Admin
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// ==========================================
// ១. ប្រព័ន្ធ AUTO-UPDATE ពី GITHUB
// ==========================================
$puc_path = __DIR__ . '/plugin-update-checker-5.6/plugin-update-checker.php';
if ( file_exists( $puc_path ) ) {
    require_once $puc_path;
    $myUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        'https://github.com/mrlimnayan-art/hpp-db-sync-slave', // ប្តូរទៅកាន់ Link Repo ពិតប្រាកដ
        __FILE__,
        'hpp-db-sync-slave'
    );
    $myUpdateChecker->setBranch('main');
}

add_action('wp_ajax_hpp_force_github_update', 'hpp_force_github_update_handler');
function hpp_force_github_update_handler() {
    delete_site_transient('update_plugins');
    wp_send_json_success(array('url' => admin_url('update-core.php?force-check=1')));
}

// ==========================================
// ២. បង្កើតតារាង Database ថ្មី (Slave)
// ==========================================
register_activation_hook( __FILE__, 'hpp_slave_create_db_table_full' );
function hpp_slave_create_db_table_full() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'hpp_sync_data_full';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        post_id bigint(20) NOT NULL,
        hpp_product_id varchar(50) DEFAULT '',
        product_code varchar(100) DEFAULT '',
        barcode varchar(100) DEFAULT '',
        stock_status varchar(50) DEFAULT '',
        total_stock float DEFAULT 0,
        price varchar(50) DEFAULT '',
        image_url text,
        description longtext,
        last_updated datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY post_id (post_id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

function hpp_purge_cache_for_post( $post_id ) {
    clean_post_cache( $post_id );
    if ( defined( 'LSCWP_V' ) || has_action( 'litespeed_purge_post' ) ) {
        do_action( 'litespeed_purge_post', $post_id );
    }
}

// ==========================================
// ៣. ទទួលទិន្នន័យពី MASTER (API Receiver សម្រាប់ HPP Super Push)
// ==========================================
add_action( 'rest_api_init', function () {
    register_rest_route( 'hpp-sync/v1', '/update', array(
        'methods' => 'POST', 
        'callback' => 'hpp_slave_receive_data', 
        'permission_callback' => '__return_true'
    ));
});

function hpp_slave_receive_data( $request ) {
    $secret = $request->get_header( 'x-hpp-secret' );
    if ( $secret !== 'KLShop2026-Secure!@#' ) return new WP_Error( 'unauthorized', 'Invalid Secret' );
    
    $params = $request->get_json_params();
    if ( isset($params['bulk_data']) && is_array($params['bulk_data']) ) {
        global $wpdb; 
        $main_table = $wpdb->prefix . 'hpp_sync_data_full';
        
        foreach ($params['bulk_data'] as $item) {
            $barcode = sanitize_text_field($item['barcode'] ?? '');
            if (empty($barcode)) continue;

            $local_post_id = $wpdb->get_var( $wpdb->prepare("SELECT post_id FROM $main_table WHERE barcode = %s LIMIT 1", $barcode) );
            if ( !$local_post_id ) { 
                $local_post_id = $wpdb->get_var( $wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_value = %s LIMIT 1", $barcode) ); 
            }
            if ( !$local_post_id ) continue; // មិនមានទំនិញនេះក្នុង Slave ទេ

            $wpdb->replace( $main_table, array(
                'post_id'        => $local_post_id, 
                'hpp_product_id' => sanitize_text_field($item['hpp_id'] ?? ''), 
                'product_code'   => sanitize_text_field($item['product_code'] ?? ''), 
                'barcode'        => $barcode, 
                'stock_status'   => sanitize_text_field($item['status'] ?? ''), 
                'total_stock'    => floatval($item['qty'] ?? 0), 
                'price'          => sanitize_text_field($item['price'] ?? ''), 
                'image_url'      => esc_url_raw($item['image_url'] ?? ''), 
                'description'    => wp_kses_post($item['description'] ?? ''), 
                'last_updated'   => current_time('mysql')
            ));
            hpp_purge_cache_for_post( $local_post_id );
        }
        return rest_ensure_response( array('status' => 'success', 'message' => 'Bulk update processed') );
    }
    return new WP_Error( 'invalid_data', 'Missing bulk_data' );
}

// ==========================================
// ៤. មុខងារ Search ក្នុង Frontend (WordPress)
// ==========================================
add_filter( 'posts_join', 'hpp_search_join_custom_table' );
function hpp_search_join_custom_table( $join ) {
    global $wpdb;
    if ( ! is_admin() && is_search() ) {
        $table_name = $wpdb->prefix . 'hpp_sync_data_full';
        $join .= " LEFT JOIN $table_name ON $wpdb->posts.ID = $table_name.post_id ";
    }
    return $join;
}

add_filter( 'posts_where', 'hpp_search_where_custom_table' );
function hpp_search_where_custom_table( $where ) {
    global $wpdb;
    if ( ! is_admin() && is_search() ) {
        $term = get_query_var( 's' );
        if ( ! empty( $term ) ) {
            $table_name = $wpdb->prefix . 'hpp_sync_data_full';
            $where = preg_replace(
                "/\(\s*{$wpdb->posts}.post_title\s+LIKE\s*(\'[^\']+\')\s*\)/",
                "({$wpdb->posts}.post_title LIKE $1) 
                OR ({$table_name}.barcode LIKE $1)
                OR ({$table_name}.product_code LIKE $1)
                OR ({$table_name}.hpp_product_id LIKE $1)",
                $where
            );
        }
    }
    return $where;
}
add_filter( 'posts_distinct', function($d){ return is_search() ? 'DISTINCT' : $d; } );

// ==========================================
// ៥. ផ្នែក Menu គ្រប់គ្រង និង AJAX
// ==========================================
add_action('admin_menu', 'hpp_db_sync_menu');
function hpp_db_sync_menu() {
    add_menu_page('គ្រប់គ្រង DB (Slave)', '🔄 ផ្គូផ្គងស្តុក', 'manage_options', 'hpp-db-sync', 'hpp_db_sync_html', 'dashicons-update', 31);
}

add_action('wp_ajax_hpp_sync_proxy', 'hpp_sync_proxy_handler');
function hpp_sync_proxy_handler() {
    $query = isset($_POST['graphql_query']) ? stripslashes($_POST['graphql_query']) : '';
    $url = "https://lovhakhour.hangpopok.com/graphql?token=40ea09344997ade2d2539d8f3d305d58ebbeb53a27250da8534aff990b64ff0d8614965ac76cf2260598fc5aa0a815ba4e7e9c4abb961f0213f2e1933315f800";
    $response = wp_remote_post($url, array('headers' => array('Content-Type' => 'application/json'), 'body' => json_encode(array('query' => $query)), 'timeout' => 90));
    echo is_wp_error($response) ? wp_send_json_error($response->get_error_message()) : wp_remote_retrieve_body($response);
    wp_die();
}

add_action('wp_ajax_save_hpp_to_db', 'save_hpp_to_db_handler');
function save_hpp_to_db_handler() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'hpp_sync_data_full';
    $pid     = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    $hpp_id  = isset($_POST['hpp_id']) ? sanitize_text_field($_POST['hpp_id']) : '';
    $code    = isset($_POST['product_code']) ? sanitize_text_field($_POST['product_code']) : '';
    $barcode = isset($_POST['barcode']) ? sanitize_text_field($_POST['barcode']) : '';
    $status  = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
    $qty     = isset($_POST['qty']) ? floatval($_POST['qty']) : 0;
    $price   = isset($_POST['price']) ? sanitize_text_field($_POST['price']) : '';
    $image   = isset($_POST['image_url']) ? esc_url_raw($_POST['image_url']) : '';
    $desc    = isset($_POST['description']) ? wp_kses_post($_POST['description']) : '';
    
    if ($pid > 0) {
        $wpdb->replace($table_name, array('post_id' => $pid, 'hpp_product_id' => $hpp_id, 'product_code' => $code, 'barcode' => $barcode, 'stock_status' => $status, 'total_stock' => $qty, 'price' => $price, 'image_url' => $image, 'description' => $desc, 'last_updated' => current_time('mysql')), array('%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s'));
        hpp_purge_cache_for_post($pid);
        wp_send_json_success();
    }
    wp_die();
}

add_action('wp_ajax_undo_specific_meta', 'undo_specific_meta_handler');
function undo_specific_meta_handler() {
    global $wpdb; $table_name = $wpdb->prefix . 'hpp_sync_data_full';
    $pid = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    if ($pid > 0) { $wpdb->delete($table_name, array('post_id' => $pid), array('%d')); hpp_purge_cache_for_post($pid); wp_send_json_success(); }
    wp_die();
}

// ==========================================
// ៦. ចំណុចប្រទាក់ UI 
// ==========================================
function hpp_db_sync_html() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'hpp_sync_data_full';
    $results = $wpdb->get_results("SELECT * FROM $table_name", ARRAY_A);
    $synced_rows = array();
    if ( $results ) foreach ( $results as $row ) $synced_rows[$row['post_id']] = (object) $row;

    $args = array('post_type' => array('post', 'product'), 'posts_per_page' => -1);
    $query = new WP_Query($args);
    ?>
    <style>
        .split-container { display: flex; flex-direction: column; gap: 20px; margin-top: 20px; }
        .panel { background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .hpp-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .hpp-table th, .hpp-table td { border: 1px solid #ccd0d4; padding: 10px; text-align: left; vertical-align: middle; }
        .hpp-table th { background: #f8f9fa; position: sticky; top: 0; z-index: 10; }
        .btn-copy, .btn-paste { background: #46b450; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .btn-copy { background: #ff9800; }
        .btn-clear { background: #d63638; color: #fff; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; }
        .search-box { width: 350px; height: 40px; padding: 0 15px; border: 1px solid #ccd0d4; border-radius: 4px; }
        .status-done { color: #46b450; font-weight: bold; }
        .hpp-thumb { width: 45px; height: 45px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; }
        .auto-box { background: #f0f6fc; padding: 15px; border-left: 4px solid #2271b1; border-radius: 4px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;}
        .update-box { background: #e7f5fe; padding: 15px; border-radius: 8px; border: 1px solid #99d1f1; margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between;}
    </style>

    <div class="wrap">
        <h1>🔄 Pro Sync (Slave Edition)</h1>
        
        <div class="update-box">
            <div>
                <strong style="color: #007cba; font-size: 16px;">🌐 ការតភ្ជាប់ទៅកាន់ GitHub៖</strong> 
                <span style="color: #666; margin-left: 10px;">ប្រព័ន្ធអាប់ដេតស្វ័យប្រវត្តិកំពុងដំណើរការ។</span>
            </div>
            <button class="button button-primary" onclick="forceGithubUpdateCheck()" id="btn-update">🔄 ឆែករកកូដថ្មីពី GitHub</button>
        </div>

        <div class="auto-box">
            <div>
                <h3 style="margin-top:0; margin-bottom: 10px;">⚡ ឧបករណ៍ស្វ័យប្រវត្តិ (Automation Tools)</h3>
                <button class="button" onclick="autoMatchOldPosts()" id="btn-auto-match" style="background: #ffeb3b; color: #000; font-weight: bold; border-color: #ffc107;">🪄 Auto-Save ទំនិញចាស់ៗ (Ultimate Match)</button>
                <span id="auto-status" style="margin-left: 15px; font-weight: bold; color: #d63638;"></span>
            </div>
            <div style="text-align: right; color:#d63638;">
                <strong>សម្គាល់:</strong> មុខងារ Force Sync ត្រូវបានបិទលើ Slave Site។<br>សូម Push ពី Master Site។
            </div>
        </div>

        <div class="split-container">
            <div class="panel" style="border-left: 4px solid #ff9800;">
                <h2 style="color: #ff9800;">១. ម៉ាស៊ីនស្វែងរកហាងពពក (API Search)</h2>
                <div style="display: flex; gap: 10px; align-items: center; margin-bottom: 15px;">
                    <input type="text" id="hpp-search-input" class="search-box" placeholder="ឈ្មោះ, ID, Code, ឬ Barcode..." onkeypress="if(event.key === 'Enter') startHppSearch()">
                    <button class="button button-primary button-large" onclick="startHppSearch()">🔍 ស្វែងរក</button>
                    <div id="hpp-loading" style="display: none; font-size: 15px; color: #2271b1; font-weight: bold;">
                        <span class="spinner is-active" style="float: none;"></span> <span id="hpp-loading-text">កំពុងរុករក...</span>
                    </div>
                </div>
                <div style="max-height: 350px; overflow-y: auto;">
                    <table class="hpp-table">
                        <thead><tr><th>រូបភាព</th><th>លេខកូដ / Barcode</th><th>ឈ្មោះ & តម្លៃ</th><th>ស្តុកសរុប</th><th>Action</th></tr></thead>
                        <tbody id="hpp-result-container">
                            <tr><td colspan="5" style="text-align:center; color:#888;">សូមវាយបញ្ចូលឈ្មោះ ឬកូដ ដើម្បីស្វែងរក...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel" style="border-left: 4px solid #46b450;">
                <h2 style="color: #46b450;">២. ទំនិញក្នុងវេបសាយ (WordPress List)</h2>
                <input type="text" id="wp-search-input" class="search-box" placeholder="🔍 រាវរកឈ្មោះក្នុងវេបសាយ..." onkeyup="filterWpTable()" style="margin-bottom: 15px;">
                <div style="max-height: 600px; overflow-y: auto;">
                    <table class="hpp-table" id="wp-table">
                        <thead><tr><th>ឈ្មោះទំនិញ (WordPress)</th><th style="width: 350px;">ទិន្នន័យក្នុង DB (Full Data)</th><th style="width: 150px;">Action</th></tr></thead>
                        <tbody id="wp-list">
                            <?php if ($query->have_posts()): while ($query->have_posts()): $query->the_post(); 
                                $pid = get_the_ID(); $db_data = isset($synced_rows[$pid]) ? $synced_rows[$pid] : null;
                            ?>
                            <tr class="wp-row" data-title="<?php echo esc_attr(strtolower(get_the_title())); ?>" data-pid="<?php echo $pid; ?>">
                                <td><strong><?php the_title(); ?></strong><br><small style="color:#888;">WP ID: <?php echo $pid; ?></small></td>
                                <td id="wp-st-<?php echo $pid; ?>">
                                    <?php if ($db_data): ?>
                                        <div style="display: flex; gap: 10px; align-items: center;">
                                            <?php if(!empty($db_data->image_url)): ?><img src="<?php echo esc_url($db_data->image_url); ?>" class="hpp-thumb"><?php endif; ?>
                                            <div>
                                                <span class="status-done">✅ <?php echo esc_html($db_data->stock_status); ?> (<?php echo esc_html($db_data->total_stock); ?>)</span><br>
                                                <small>ID: <?php echo esc_html($db_data->hpp_product_id); ?> | Code: <?php echo esc_html($db_data->product_code); ?></small><br>
                                                <small>តម្លៃ: <strong><?php echo esc_html($db_data->price); ?></strong></small>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span style="color:#888;" class="empty-data">មិនទាន់មានទិន្នន័យ</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button class="btn-paste" onclick="pasteToPost(<?php echo $pid; ?>)">💾 Paste</button>
                                    <button class="btn-clear" onclick="clearPostData(<?php echo $pid; ?>)">🧹</button>
                                </td>
                            </tr>
                            <?php endwhile; endif; wp_reset_postdata(); ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script>
    window.currentHppSyncData = null;

    async function forceGithubUpdateCheck() {
        const btn = document.getElementById('btn-update'); btn.disabled = true; btn.innerText = "⏳ កំពុងទាក់ទង GitHub...";
        const res = await fetch(ajaxurl + '?action=hpp_force_github_update'); const json = await res.json();
        if(json.success) { window.location.href = json.data.url; }
    }

    async function fetchFromProxy(queryStr) {
        const fd = new URLSearchParams(); fd.append('action', 'hpp_sync_proxy'); fd.append('graphql_query', queryStr);
        const res = await fetch(ajaxurl, { method: 'POST', body: fd }); return await res.json();
    }

    async function startHppSearch() {
        const input = document.getElementById('hpp-search-input').value.trim();
        if (!input) return;
        document.getElementById('hpp-loading').style.display = "inline-block";
        document.getElementById('hpp-result-container').innerHTML = "<tr><td colspan='5'>រុករក...</td></tr>";
        
        // (កូដ Search ហាងពពកសង្ខេបដើម្បីកុំឲ្យវែងពេក)
        try {
            const enumJson = await fetchFromProxy(`{ __type(name: "ProductKbnEnum") { enumValues { name } } }`);
            const allKbns = enumJson.data.__type.enumValues.map(e => e.name);
            const search = input.toLowerCase(); let rawMatches = [];

            for (const kbn of allKbns) {
                let off = 0; let end = false;
                while (!end) {
                    const json = await fetchFromProxy(`{ products(kbn: ${kbn}, pageSize: 200, offset: ${off}) { id code name barcode price images description } }`);
                    const list = json.data?.products; if (!list || list.length === 0) { end = true; continue; }
                    const found = list.filter(item => (item.name && item.name.toLowerCase().includes(search)) || (item.id && String(item.id) === search) || (item.code && item.code.toLowerCase().includes(search)) || (item.barcode && item.barcode.toLowerCase().includes(search)));
                    rawMatches = rawMatches.concat(found); if (rawMatches.length >= 20) { end = true; break; }
                    off += list.length;
                }
            }

            const seenIds = new Set();
            const matches = rawMatches.filter(item => { const isDup = seenIds.has(item.id); seenIds.add(item.id); return !isDup; }).slice(0, 15);
            
            if (matches.length === 0) {
                document.getElementById('hpp-result-container').innerHTML = "<tr><td colspan='5' style='text-align:center;'>❌ រកមិនឃើញ!</td></tr>";
            } else {
                let s1Map = {}, s2Map = {}; // សម្រាប់ការស្វែងរកលឿន អាចកែប្រែបាន
                const getStock = async (oid) => { let m={}; let off=0; while(true){ const res = await fetchFromProxy(`{ product_stock(outlet: ${oid}, pageSize: 350, offset: ${off}) { product stock_qty } }`); const l = res.data?.product_stock; if(!l||l.length===0)break; l.forEach(s=>m[s.product]=parseFloat(s.stock_qty)||0); if(l.length<350)break; off+=l.length; } return m; };
                s1Map = await getStock('null'); s2Map = await getStock('272');

                document.getElementById('hpp-result-container').innerHTML = "";
                matches.forEach(match => {
                    const totalStock = (s1Map[match.id] || 0) + (s2Map[match.id] || 0);
                    let imgName = ""; try { const imgs = JSON.parse(match.images); imgName = imgs[0] || ""; } catch(e) {}
                    const imgLink = imgName ? `https://s3-ap-southeast-1.amazonaws.com/hangpopok.com/product/${imgName.replace(/[\[\]"]/g, '')}` : "";
                    const priceFmt = match.price ? (match.price * 100).toLocaleString() + " ៛" : "---";
                    const dataObj = { id: match.id, code: match.code, barcode: match.barcode || match.code, name: match.name, price: priceFmt, stock: totalStock, img: imgLink, desc: match.description || "" };
                    document.getElementById('hpp-result-container').innerHTML += `<tr><td><img src="${imgLink}" class="hpp-thumb"></td><td>Code: ${match.code}<br><small>BC: ${match.barcode||'---'}</small></td><td><strong>${match.name}</strong><br><span style="color:red; font-weight:bold;">${priceFmt}</span></td><td>${totalStock}</td><td><button class="btn-copy" onclick='copyHppData(${JSON.stringify(dataObj).replace(/'/g, "&apos;")})'>📋 ចម្លង</button></td></tr>`;
                });
            }
        } catch (e) { alert("Error: " + e.message); }
        document.getElementById('hpp-loading').style.display = "none";
    }

    function copyHppData(data) { window.currentHppSyncData = data; alert("បានចម្លង: " + data.name); }

    async function pasteToPost(postId) {
        if (!window.currentHppSyncData) return alert("សូមចម្លងទំនិញពីហាងពពកសិន!");
        const d = window.currentHppSyncData; let label = d.stock >= 50 ? "មានស្តុក" : (d.stock > 0 ? "ជិតអស់ស្តុក" : "អស់ស្តុក");
        const fd = new FormData(); fd.append('action', 'save_hpp_to_db'); fd.append('post_id', postId); fd.append('hpp_id', d.id); fd.append('product_code', d.code); fd.append('barcode', d.barcode); fd.append('status', label); fd.append('qty', d.stock); fd.append('price', d.price); fd.append('image_url', d.img); fd.append('description', d.desc);
        await fetch(ajaxurl, { method: 'POST', body: fd }); location.reload();
    }

    async function clearPostData(postId) {
        if (!confirm("លុបទិន្នន័យ?")) return;
        const fd = new FormData(); fd.append('action', 'undo_specific_meta'); fd.append('post_id', postId);
        await fetch(ajaxurl, { method: 'POST', body: fd }); location.reload();
    }

    function filterWpTable() {
        const input = document.getElementById('wp-search-input').value.toLowerCase();
        document.querySelectorAll('.wp-row').forEach(row => { row.style.display = (row.getAttribute('data-title').includes(input) || row.getAttribute('data-pid').includes(input)) ? '' : 'none'; });
    }

    async function getStockMap(oid) {
        let map = {}; let off = 0;
        while (true) {
            const res = await fetchFromProxy(`{ product_stock(outlet: ${oid}, pageSize: 350, offset: ${off}) { product stock_qty } }`);
            const list = res.data?.product_stock; if (!list || list.length === 0) break;
            list.forEach(s => map[s.product] = parseFloat(s.stock_qty) || 0);
            if (list.length < 350) break; off += list.length;
        } return map;
    }

    function cleanStringForMatch(str) { 
        return !str ? "" : String(str).toLowerCase().replace(/×/g, 'x').replace(/អុី/g, 'អ៊ី').replace(/[^\u1780-\u17FFa-z0-9]/g, ''); 
    }

    async function autoMatchOldPosts() {
        if(!confirm("ចាប់ផ្តើមស្វែងរក និងផ្គូផ្គងស្វ័យប្រវត្តិ?")) return;
        const btn = document.getElementById('btn-auto-match'); 
        const statusBox = document.getElementById('auto-status');
        btn.disabled = true;
        
        try {
            statusBox.innerText = "⏳ កំពុងទាញទិន្នន័យពីហាងពពក...";
            const enumJson = await fetchFromProxy(`{ __type(name: "ProductKbnEnum") { enumValues { name } } }`);
            const allKbns = enumJson.data.__type.enumValues.map(e => e.name); 
            let allProducts = [];
            for (const kbn of allKbns) {
                let off = 0; let end = false;
                while (!end) {
                    const json = await fetchFromProxy(`{ products(kbn: ${kbn}, pageSize: 200, offset: ${off}) { id code name barcode price images description } }`);
                    const list = json.data?.products; if (!list || list.length === 0) { end = true; continue; }
                    allProducts = allProducts.concat(list); off += list.length;
                }
            }
            const s1 = await getStockMap('null'); 
            const s2 = await getStockMap('272');
            
            statusBox.innerText = "⏳ កំពុងផ្គូផ្គង...";
            let count = 0; 
            const rows = document.querySelectorAll('.wp-row');
            
            for (let row of rows) {
                const td = row.querySelector('td[id^="wp-st-"]');
                if (td.querySelector('.empty-data')) {
                    const cleanWpTitle = cleanStringForMatch(row.getAttribute('data-title'));
                    const match = allProducts.find(p => cleanStringForMatch(p.name) === cleanWpTitle);
                    if (match) {
                        const totalStock = (s1[match.id] || 0) + (s2[match.id] || 0);
                        let label = totalStock >= 50 ? "មានស្តុក" : (totalStock > 0 ? "ជិតអស់ស្តុក" : "អស់ស្តុក");
                        let img = ""; try { const imgs = JSON.parse(match.images); img = imgs[0] ? `https://s3-ap-southeast-1.amazonaws.com/hangpopok.com/product/${imgs[0].replace(/[\[\]"]/g, '')}` : ""; } catch(e){}
                        const fd = new FormData(); 
                        fd.append('action', 'save_hpp_to_db'); 
                        fd.append('post_id', row.getAttribute('data-pid')); 
                        fd.append('hpp_id', match.id); 
                        fd.append('product_code', match.code || ''); 
                        fd.append('barcode', match.barcode || match.code || ''); 
                        fd.append('status', label); 
                        fd.append('qty', totalStock); 
                        fd.append('price', match.price ? (match.price * 100).toLocaleString() + " ៛" : "---"); 
                        fd.append('image_url', img); 
                        fd.append('description', match.description || '');
                        
                        await fetch(ajaxurl, { method: 'POST', body: fd });
                        td.innerHTML = `<span class="status-done">✅ Auto-Matched (${totalStock})</span>`; 
                        count++;
                    }
                }
            }
            statusBox.innerText = `✅ រួចរាល់! បានផ្គូផ្គង ${count} ទំនិញ។`; 
            btn.disabled = false;
        } catch (err) { 
            alert("Error: " + err.message); 
            btn.disabled = false; 
            statusBox.innerText = "❌ មានបញ្ហា!";
        }
    }
    </script>
    <?php
}

// ==========================================
// ៧. Content Filter សម្រាប់ Single Post
// ==========================================
add_filter( 'the_content', 'hpp_display_meta_on_single_post', 5 );
function hpp_display_meta_on_single_post( $content ) {
    if ( ! is_singular( array( 'post', 'product' ) ) || ! in_the_loop() || ! is_main_query() ) return $content;
    global $wpdb; $table_name = $wpdb->prefix . 'hpp_sync_data_full';
    $db_data = $wpdb->get_row( $wpdb->prepare( "SELECT hpp_product_id, barcode, stock_status FROM $table_name WHERE post_id = %d", get_the_ID() ) );
    
    if ( $db_data && (!empty($db_data->hpp_product_id) || !empty($db_data->barcode)) ) {
        $status = esc_html($db_data->stock_status); $bg = '#46b450';
        if (mb_strpos($status, 'ជិត') !== false) $bg = '#ff9800'; elseif (mb_strpos($status, 'អស់') !== false) $bg = '#d63638';
        $meta_box = '<div style="background:#f8f9fa; border:1px dashed #46b450; padding:12px; margin:15px 0; border-radius:6px; clear:both;"><div style="display:flex; align-items:center; gap:8px; border-bottom:1px solid #eee; padding-bottom:5px; margin-bottom:10px;"><span style="font-size:18px;">📦</span><strong style="color:#333;">លេខកូដសម្គាល់ទំនិញ</strong></div><p style="margin:0 0 5px 0; font-size:14px;"><strong>🆔 Product ID:</strong> '.($db_data->hpp_product_id ?: '---').'</p><p style="margin:0 0 5px 0; font-size:14px;"><strong>🔣 Barcode / Code:</strong> <span style="background:#fff; border:1px solid #ccc; padding:2px 6px; border-radius:4px; font-family:monospace; font-weight:bold;">'.($db_data->barcode ?: '---').'</span></p><p style="margin:0; font-size:14px;"><strong>📊 ស្ថានភាពស្តុក៖</strong> <span style="background:'.$bg.'; color:#fff; padding:2px 8px; border-radius:4px; font-size:12px; font-weight:bold; margin-left:5px;">'.$status.'</span></p></div>';
        return $content . $meta_box;
    } 
    return $content;
}
