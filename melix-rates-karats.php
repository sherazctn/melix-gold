<?php
/*
Plugin Name: Melix Gold
Description: Gold karat rates (14K,18K,21K,22K,24K). Manual inputs + optional auto-fill 24K from GoldPriceZ API (AED). Hourly cron, auto-recalculate WC prices, shortcodes, logs, robust product meta UI.
Version: 3.3.2
Author: Sheraz Khan | NETREX
*/
if (!defined('ABSPATH')) exit;

// Add "Contact Developer" link to Plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), 'melix_add_contact_link');
function melix_add_contact_link($links) {
    // Adding it to the end of the array places it after "Deactivate"
    $links['contact_dev'] = '<a href="mailto:sherazctn@gmail.com" style="color:#46b450; font-weight:bold;">Contact Developer</a>';
    return $links;
}
class Melix_Rates_Karats {
    private $opt_key = 'melix_rates_karats_options';
    private $log_key = 'melix_rates_karats_logs';
    private $cron_hook = 'melix_rates_karats_hourly_fetch';
    private $batch_size = 100;

    public function __construct(){
        register_activation_hook(__FILE__, array($this,'activation'));
        register_deactivation_hook(__FILE__, array($this,'deactivation'));
        add_action('admin_menu', array($this,'admin_menu'));
        // ... rest of your construct remains the same ...
        add_action('admin_post_melix_save_settings', array($this,'handle_save_settings'));
        add_action('admin_post_melix_fetch_now', array($this,'admin_fetch_now'));
        add_action('admin_enqueue_scripts', array($this,'admin_assets'));
        add_action('woocommerce_product_options_pricing', array($this,'product_fields'));
        add_action('woocommerce_process_product_meta', array($this,'save_product_meta'));
        add_action('woocommerce_variation_options_pricing', array($this,'variation_fields'), 10, 3);
        add_action('woocommerce_save_product_variation', array($this,'save_variation_meta'), 10, 2);
        add_filter('woocommerce_product_get_price', array($this,'override_product_price'), 99, 2);
        add_filter('woocommerce_product_get_regular_price', array($this,'override_product_price'), 99, 2);
        add_filter('woocommerce_variation_prices_price', array($this,'override_variation_price'), 99, 3);
        add_action('wp_ajax_melix_load_logs', array($this,'ajax_load_logs'));
        add_action($this->cron_hook, array($this,'cron_fetch_rates'));
        add_shortcode('gold_price_14', array($this,'sc_14'));
        add_shortcode('gold_price_18', array($this,'sc_18'));
        add_shortcode('gold_price_21', array($this,'sc_21'));
        add_shortcode('gold_price_22', array($this,'sc_22'));
        add_shortcode('gold_price_24', array($this,'sc_24'));
        add_shortcode('gold_price_14_after_gap', array($this,'sc_14_after_gap'));
        add_shortcode('gold_price_18_after_gap', array($this,'sc_18_after_gap'));
        add_shortcode('gold_price_21_after_gap', array($this,'sc_21_after_gap'));
        add_shortcode('gold_price_22_after_gap', array($this,'sc_22_after_gap'));
        add_shortcode('gold_price_24_after_gap', array($this,'sc_24_after_gap'));
    }

    public function activation(){
        if (!get_option($this->opt_key)){
            add_option($this->opt_key, array(
                'use_api'=>1,
                'api_key'=>'',
                'auto_fetch'=>1,
                'apply_to_db'=>1,
                'last_fetch'=>'',
                'api_status'=>'unknown',
                'api_reason'=>'',
                'market_gap' => '',
                'rate_14'=>'',
                'rate_18'=>'',
                'rate_21'=>'',
                'rate_22'=>'',
                'rate_24'=>''
            ));
        }
        if (!get_option($this->log_key)) add_option($this->log_key, array());
        if (!wp_next_scheduled($this->cron_hook)){
            wp_schedule_event(time(), 'hourly', $this->cron_hook);
        }
    }

    public function deactivation(){
        wp_clear_scheduled_hook($this->cron_hook);
    }

    /* Admin menu & assets */
    public function admin_menu(){
        add_menu_page('Melix Gold','Melix Gold','manage_options','melix-karats',array($this,'settings_page'),'dashicons-chart-area',56);
        add_submenu_page('melix-karats','Logs','Logs','manage_options','melix-karats-logs',array($this,'render_logs_page'));
    }

    public function admin_assets($hook){
        if (in_array($hook, array('toplevel_page_melix-karats','product.php','post.php','post-new.php','admin_page_melix-karats-logs'))){
            wp_enqueue_script('melix-karats-js', plugin_dir_url(__FILE__).'melix-karats-admin.js', array('jquery'), '1.6', true);
            wp_enqueue_style('melix-karats-css', plugin_dir_url(__FILE__).'melix-karats-admin.css', array(), '1.6');
            $opts = get_option($this->opt_key, array());
            wp_localize_script('melix-karats-js','MelixKaratsData', array(
                'rates' => array(
                    '14' => floatval($opts['rate_14'] ?? 0),
                    '18' => floatval($opts['rate_18'] ?? 0),
                    '21' => floatval($opts['rate_21'] ?? 0),
                    '22' => floatval($opts['rate_22'] ?? 0),
                    '24' => floatval($opts['rate_24'] ?? 0)
                ),
                'market_gap' => floatval($opts['market_gap'] ?? 0),
                'ajax_url' => admin_url('admin-ajax.php'),
                'api_status' => $opts['api_status'] ?? 'unknown',
                'api_remaining' => intval($opts['api_usage_remaining'] ?? 0), // Added for logic
                'api_reason' => $opts['api_reason'] ?? '',
                'use_api' => !empty($opts['use_api']) ? 1 : 0
            ));
        }
    }

 
    /* Settings page HTML - TABS & USAGE & LOGIC */
    public function settings_page(){
        if (!current_user_can('manage_options')) return;
        
        $opts = get_option($this->opt_key, array());
        
        // Safety check for counts
        $counts = array(
            '14' => $this->count_products_by_karat('14'),
            '18' => $this->count_products_by_karat('18'),
            '21' => $this->count_products_by_karat('21'),
            '22' => $this->count_products_by_karat('22'),
            '24' => $this->count_products_by_karat('24'),
        );
        
        $real_key = $opts['api_key'] ?? '';
        $display_key = (strlen($real_key) > 10) ? substr($real_key, 0, 4) . '................' . substr($real_key, -4) : $real_key;

        ?>
        <div class="wrap melix-wrap">
            <h1>Melix Gold Settings</h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="#tab-settings" class="nav-tab" id="nav-tab-settings">General Settings</a>
                <a href="#tab-rates" class="nav-tab" id="nav-tab-rates">Gold Rates</a>
            </h2>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('melix_save_settings','melix_nonce');?>
                <input type="hidden" name="action" value="melix_save_settings" />
                <input type="hidden" name="melix_active_tab" id="melix_active_tab" value="tab-settings" />
                <input type="hidden" name="apply_to_db" value="1">

                <div id="tab-settings" class="melix-tab-content">
                    <table class="form-table">
                        <tr>
                            <th>Use API</th>
                            <td>
                                <label><input id="melix_use_api" name="use_api" type="checkbox" value="1" <?php checked(1, $opts['use_api'] ?? 0);?>> Use GoldPriceZ API (auto-fill 24K)</label>
                                <p class="description">If unchecked you can manage all karat rates manually only.</p>
                            </td>
                        </tr>
                        <tbody id="melix-api-settings" style="<?php echo !empty($opts['use_api']) ? '' : 'display:none;'; ?>">
                            <tr>
                                <th>GoldPriceZ API Key</th>
                                <td>
                                    <input name="api_key" type="text" value="<?php echo esc_attr($display_key);?>" class="regular-text" placeholder="Enter API Key">
                                    <p class="description">Key is hidden for security. To change it, just delete and paste the new one.</p>
                                </td>
                            </tr>
                            <tr>
                                <th>API Status</th>
                                <td>
                                    <?php
                                    $status = $opts['api_status'] ?? 'unknown';
                                    $reason = $opts['api_reason'] ?? '';
                                    
                                    if ($status === 'active'){
                                        echo '<div style="margin-bottom:5px;"><span class="melix-status-dot melix-dot-green"></span><span class="melix-status-text melix-active">ACTIVE</span></div>';
                                        
                                        // Usage Info
                                        $rem = isset($opts['api_usage_remaining']) ? $opts['api_usage_remaining'] : '';
                                        $rst = isset($opts['api_usage_reset']) ? $opts['api_usage_reset'] : '';
                                        
                                        if ($rem !== '') {
                                            echo '<div class="api-usage-info">';
                                            echo '<strong>Requests Remaining:</strong> ' . esc_html($rem);
                                            if ($rst) echo '<br><small style="color:#555;">Resets on: ' . esc_html($rst) . '</small>';
                                            echo '</div>';
                                        }
                                    } else {
                                        echo '<span class="melix-status-dot melix-dot-red"></span><span class="melix-status-text melix-inactive">NOT WORKING</span>';
                                        if ($reason) echo ' <span class="melix-api-reason" style="color:#d63638;">('.esc_html($reason).')</span>';
                                    }
                                    ?>
                                    <p class="description" style="margin-top:5px;">API auto-checks on fetch (manual or cron). If active, 24K will auto-populate.</p>
                                </td>
                            </tr>
                            <tr><th>Auto Fetch (hourly)</th><td><label><input type="checkbox" name="auto_fetch" value="1" <?php checked(1, $opts['auto_fetch'] ?? 0);?>> Enable hourly API fetch (24K)</label></td></tr>
                        </tbody>
                    </table>
                    <?php submit_button('Save Settings'); ?>
                </div>

                <div id="tab-rates" class="melix-tab-content">
                    <h2>Gold Rates (AED / Gram)</h2>
                    <p class="description">API active: 24K auto-fills, others auto-calculate. You can override manually.</p>
                    <table class="form-table">
                        <tr>
                            <th>Market Gap (AED/g)</th>
                            <td>
                                <input name="market_gap" type="number" step="0.01" value="<?php echo esc_attr($opts['market_gap'] ?? ''); ?>" class="regular-text">
                                <p class="description">Amount added to each gram before weight calculation.</p>
                            </td>
                        </tr>
                        <tr><th>14K</th><td><input name="rate_14" type="number" id="melix_rate_14" step="0.01" value="<?php echo esc_attr($opts['rate_14'] ?? '');?>" class="regular-text"><div class="melix-gap-preview" data-karat="14"></div></td><td><?php echo intval($counts['14']);?> products</td></tr>
                        <tr><th>18K</th><td><input name="rate_18" type="number" id="melix_rate_18" step="0.01" value="<?php echo esc_attr($opts['rate_18'] ?? '');?>" class="regular-text"><div class="melix-gap-preview" data-karat="18"></div></td><td><?php echo intval($counts['18']);?> products</td></tr>
                        <tr><th>21K</th><td><input name="rate_21" type="number" id="melix_rate_21" step="0.01" value="<?php echo esc_attr($opts['rate_21'] ?? '');?>" class="regular-text"><div class="melix-gap-preview" data-karat="21"></div></td><td><?php echo intval($counts['21']);?> products</td></tr>
                        <tr><th>22K</th><td><input name="rate_22" type="number" id="melix_rate_22" step="0.01" value="<?php echo esc_attr($opts['rate_22'] ?? '');?>" class="regular-text"><div class="melix-gap-preview" data-karat="22"></div></td><td><?php echo intval($counts['22']);?> products</td></tr>
                        <tr><th>24K</th><td><input name="rate_24" id="melix_rate_24" type="number" step="0.01" value="<?php echo esc_attr($opts['rate_24'] ?? '');?>" class="regular-text"><div class="melix-gap-preview" data-karat="24"></div></td><td><?php echo intval($counts['24']);?> products</td></tr>
                    </table>
                    <div style="display: flex; gap: 10px; align-items: center; margin-top: 15px;">
                         <?php submit_button('Save Rates', 'primary', 'submit', false); ?>
                         <button type="button" id="melix-fetch-btn" class="button button-secondary">Fetch Rates Now</button>
                    </div>
                </div>
            </form>
            
            <form id="melix-fetch-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" style="display:none;">
                <?php wp_nonce_field('melix_fetch_now','melix_fetch_now_nonce');?>
                <input type="hidden" name="action" value="melix_fetch_now">
                <input type="hidden" name="melix_active_tab" value="tab-rates"> 
            </form>

            <h2 style="margin-top:30px; border-top:1px solid #ddd; padding-top:20px;">Recent Logs</h2>
            <div id="melix-logs-dashboard">
                <div class="melix-log-table-headers">
                    <div class="col-transaction">Transaction</div>
                    <div class="col-status">Status</div>
                    <div class="col-time">Time</div>
                </div>
                <div id="melix-logs-list">
                    <?php
                    // Limit to 50 records for performance
                    $initial = $this->fetch_logs(0, 50);
                    if(!empty($initial)) {
                        foreach ($initial as $entry) echo $this->render_log_row($entry);
                    } else {
                        echo '<div style="padding:15px; color:#666;">No logs found.</div>';
                    }
                    ?>
                </div>
            </div>
            
            <div style="margin-top:15px; text-align:center;">
                <a href="<?php echo admin_url('admin.php?page=melix-karats-logs'); ?>" class="button button-secondary">View More Records</a>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($){
            // SMART TAB LOGIC
            var activeTab = 'tab-settings'; 
            
            if(window.location.hash) {
                var hash = window.location.hash.substring(1);
                if(hash === 'tab-rates' || hash === 'tab-settings') {
                    activeTab = hash;
                }
            } else {
                // Check if API data object exists before accessing
                if (typeof MelixKaratsData !== 'undefined') {
                    var apiStatus = MelixKaratsData.api_status;
                    var remaining = MelixKaratsData.api_remaining || 0;
                    if (apiStatus === 'active' && remaining > 0) {
                        activeTab = 'tab-rates';
                    }
                }
            }

            // Reset tabs
            $('.nav-tab').removeClass('nav-tab-active');
            $('.melix-tab-content').removeClass('active');
            
            // Set active tab
            $('#nav-' + activeTab).addClass('nav-tab-active');
            $('#' + activeTab).addClass('active');
            $('#melix_active_tab').val(activeTab); 
            
            // Click Handler
            $('.nav-tab-wrapper a').on('click', function(e){
                e.preventDefault();
                var target = $(this).attr('href').substring(1);
                $('.nav-tab').removeClass('nav-tab-active');
                $(this).addClass('nav-tab-active');
                $('.melix-tab-content').removeClass('active');
                $('#' + target).addClass('active');
                $('#melix_active_tab').val(target);
                window.location.hash = target;
            });
            
            $('#melix-fetch-btn').on('click', function(e){
                e.preventDefault();
                $('#melix-fetch-form').submit();
            });
        });
        </script>
        <?php
    }


    /* Save settings and redirect to correct tab */
    public function handle_save_settings(){
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('melix_save_settings','melix_nonce');
        
        // Get active tab from hidden input
        $target_tab = isset($_POST['melix_active_tab']) ? sanitize_text_field($_POST['melix_active_tab']) : 'tab-settings';
        
        $opts = get_option($this->opt_key, array());
        $old = $opts;
        
        $opts['use_api'] = isset($_POST['use_api']) ? 1 : 0;
        
        $posted_key = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : '';
        if (strpos($posted_key, '....') !== false) {
            $opts['api_key'] = $old['api_key'] ?? '';
        } else {
            $opts['api_key'] = $posted_key;
        }

        $opts['auto_fetch'] = isset($_POST['auto_fetch']) ? 1 : 0;
        $opts['apply_to_db'] = 1; // Always ON
        $opts['rate_14'] = isset($_POST['rate_14']) ? sanitize_text_field(wp_unslash($_POST['rate_14'])) : $opts['rate_14'];
        $opts['rate_18'] = isset($_POST['rate_18']) ? sanitize_text_field(wp_unslash($_POST['rate_18'])) : $opts['rate_18'];
        $opts['rate_21'] = isset($_POST['rate_21']) ? sanitize_text_field(wp_unslash($_POST['rate_21'])) : $opts['rate_21'];
        $opts['rate_22'] = isset($_POST['rate_22']) ? sanitize_text_field(wp_unslash($_POST['rate_22'])) : $opts['rate_22'];
        $opts['rate_24'] = isset($_POST['rate_24']) ? sanitize_text_field(wp_unslash($_POST['rate_24'])) : $opts['rate_24'];
        $opts['market_gap'] = isset($_POST['market_gap']) ? sanitize_text_field(wp_unslash($_POST['market_gap'])) : ($opts['market_gap'] ?? '');
        
        update_option($this->opt_key, $opts);
        
        $changes = array();
        foreach (array('rate_14'=>'14','rate_18'=>'18','rate_21'=>'21','rate_22'=>'22','rate_24'=>'24') as $k=>$label){
            $oldv = isset($old[$k]) ? $old[$k] : '';
            $newv = isset($opts[$k]) ? $opts[$k] : '';
            if ((string)$oldv !== (string)$newv){
                $arrow = '';
                if (is_numeric($oldv) && is_numeric($newv)){
                    $ov = floatval($oldv); $nv = floatval($newv);
                    if ($nv > $ov) $arrow = 'up';
                    elseif ($nv < $ov) $arrow = 'down';
                }
                $changes[] = array(
                    'karat' => $label,
                    'old' => $oldv,
                    'new' => $newv,
                    'dir' => $arrow
                );
            }
        }
        
        $stats = array('updated'=>0, 'errors'=>0);
        if (!empty($opts['apply_to_db'])){
            $stats = $this->recalculate_all_products($opts, true);
        }

        if (!empty($changes)){
            $this->push_transaction_log(array(
                'time'=>current_time('mysql'),
                'user'=>wp_get_current_user()->user_login,
                'type'=>'settings_save',
                'changes'=>$changes,
                'stats'=>$stats,
                'status'=>'success'
            ));
        } else {
            $this->push_transaction_log(array(
                'time'=>current_time('mysql'),
                'user'=>wp_get_current_user()->user_login,
                'type'=>'settings_save',
                'changes'=>array(),
                'status'=>'success',
                'note'=>'Settings saved (no rate change)'
            ));
        }
        
        // REDIRECT TO ACTIVE TAB
        wp_safe_redirect(admin_url('admin.php?page=melix-karats#' . $target_tab));
        exit;
    }

    /* Manual fetch now */
    public function admin_fetch_now(){
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('melix_fetch_now','melix_fetch_now_nonce');
        $this->fetch_and_update_rates(true);
        // Redirect to RATES tab
        wp_safe_redirect(admin_url('admin.php?page=melix-karats#tab-rates'));
        exit;
    }

    /* Cron handler */
    public function cron_fetch_rates(){ $this->fetch_and_update_rates(false); }

 

    public function fetch_and_update_rates($is_manual=false){
        $opts = get_option($this->opt_key, array());
        $log_user_name = 'Live Gold API'; 

        if (empty($opts['use_api'])) {
            $opts['api_status'] = 'inactive'; $opts['api_reason'] = 'API disabled';
            update_option($this->opt_key, $opts);
            $this->push_transaction_log(array('time'=>current_time('mysql'), 'user'=>$log_user_name, 'type'=>'fetch', 'changes'=>array(), 'status'=>'error', 'note'=>'API disabled by admin'));
            return;
        }
        $api_key = $opts['api_key'] ?? '';
        if (empty($api_key)){
            $opts['api_status'] = 'inactive'; $opts['api_reason'] = 'API key missing';
            update_option($this->opt_key,$opts);
            $this->push_transaction_log(array('time'=>current_time('mysql'), 'user'=>$log_user_name, 'type'=>'fetch', 'changes'=>array(), 'status'=>'error', 'note'=>'API key missing'));
            return;
        }
        $url = 'https://goldpricez.com/api/rates/currency/aed/measure/gram';
        $response = wp_remote_get($url, array(
            'timeout' => 20,
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
            'headers' => array('X-API-KEY' => $api_key, 'Accept' => 'application/json')
        ));
        if (is_wp_error($response)){
            $opts['api_status'] = 'inactive'; $opts['api_reason'] = $response->get_error_message();
            update_option($this->opt_key,$opts);
            $this->push_transaction_log(array('time'=>current_time('mysql'), 'user'=>$log_user_name, 'type'=>'fetch', 'changes'=>array(), 'status'=>'error', 'note'=>$opts['api_reason']));
            return;
        }
        
        // Headers Logic
        $headers = wp_remote_retrieve_headers($response);
        $safe_headers = array(); foreach($headers as $k => $v) $safe_headers[strtolower($k)] = $v;
        $remaining = ''; $reset = '';

        if (isset($safe_headers['x-ratelimit-remaining'])) $remaining = $safe_headers['x-ratelimit-remaining'];
        elseif (isset($safe_headers['x-rate-limit-remaining'])) $remaining = $safe_headers['x-rate-limit-remaining'];
        elseif (isset($safe_headers['x-quota-remaining'])) $remaining = $safe_headers['x-quota-remaining'];

        if (isset($safe_headers['x-ratelimit-reset'])) $reset = $safe_headers['x-ratelimit-reset'];
        elseif (isset($safe_headers['x-rate-limit-reset'])) $reset = $safe_headers['x-rate-limit-reset'];

        if ($remaining !== '') $opts['api_usage_remaining'] = $remaining;
        if ($reset !== '') { $opts['api_usage_reset'] = is_numeric($reset) ? date('Y-m-d H:i:s', $reset) : $reset; }
        else { if ($remaining !== '') $opts['api_usage_reset'] = '1st of next month'; }

        $body = wp_remote_retrieve_body($response);
        $body = trim($body, "\xEF\xBB\xBF");
        $json = json_decode($body, true);
        if (is_string($json)) $json = json_decode($json, true);

        if (!is_array($json) || !isset($json['gram_in_aed'])) {
            $opts['api_status'] = 'inactive'; $opts['api_reason'] = 'Invalid API response';
            update_option($this->opt_key, $opts);
            $this->push_transaction_log(['time' => current_time('mysql'), 'user' => $log_user_name, 'type' => 'fetch', 'status' => 'error', 'note' => 'JSON Error: ' . json_last_error_msg()]);
            return;
        }
        
        $old = $opts;
        $old24 = isset($opts['rate_24']) ? round(floatval($opts['rate_24']), 2) : 0;
        $new24 = round(floatval($json['gram_in_aed']), 2);
        
        $changes = array();
        if ($new24 > 0 && $new24 != $old24){
            $opts['rate_24'] = $new24;
            $changes[] = array('karat'=>'24','old'=>$old24,'new'=>$new24,'dir'=> ($new24 > $old24 ? 'up' : 'down'));
        }
        
        $karats = array('14'=>14/24, '18'=>18/24, '21'=>21/24, '22'=>22/24);
        foreach ($karats as $k=>$factor){
            $key = 'rate_'.$k;
            $oldv = floatval($old[$key] ?? 0);
            $newv = round($new24 * $factor, 2);
            $opts[$key] = $newv;
            if (abs($newv - $oldv) > 0.001) { 
                $changes[] = array('karat' => $k, 'old' => $oldv, 'new' => $newv, 'dir' => ($newv > $oldv ? 'up' : 'down'));
            }
        }
        
        $opts['api_status'] = 'active'; $opts['api_reason'] = ''; $opts['last_fetch'] = current_time('mysql');
        update_option($this->opt_key, $opts);
        
        $stats = array('updated'=>0, 'errors'=>0);
        if (!empty($opts['apply_to_db'])){ $stats = $this->recalculate_all_products($opts, true); }
        
        $this->push_transaction_log(array('time'=>current_time('mysql'), 'user'=> $log_user_name, 'type'=>'fetch', 'changes'=>$changes, 'stats'=>$stats, 'status'=>'success'));
    }

    /* Recalculate all products (batched) */
    private function recalculate_all_products($opts, $return_stats = true){
        global $wpdb;
        $apply = !empty($opts['apply_to_db']);
        $rates = array(
            'r14'=>floatval($opts['rate_14'] ?? 0),
            'r18'=>floatval($opts['rate_18'] ?? 0),
            'r21'=>floatval($opts['rate_21'] ?? 0),
            'r22'=>floatval($opts['rate_22'] ?? 0),
            'r24'=>floatval($opts['rate_24'] ?? 0)
        );
        $updated = 0;
        $errors = 0;
        $paged = 1;
        do {
            // Fetch product IDs to save memory
            $q = new WP_Query(array('post_type'=>'product','posts_per_page'=>$this->batch_size,'paged'=>$paged,'fields'=>'ids'));
            if (!$q->have_posts()) break;
            
            foreach ($q->posts as $id){
                $product = wc_get_product($id);
                if (!$product) continue;
                
                // SIMPLE PRODUCTS
                $price = $this->calc_for_product($product, $rates);
                if ($price !== null){
                    if ($apply){
                        // Check if price is actually different (within 1 cent)
                        $current_price = floatval($product->get_regular_price());
                        if (abs($current_price - $price) > 0.01) {
                            try{
                                $product->set_regular_price($price);
                                $product->set_price($price);
                                $product->save();
                                $updated++;
                            } catch(Exception $e){ $errors++; }
                        }
                    } else {
                        // For meta-only updates, always update
                        update_post_meta($id, 'melix_last_calc_price', $price);
                        $updated++;
                    }
                }
                
                // VARIATIONS
                if ($product->is_type('variable')){
                    $vars = $product->get_children();
                    foreach ($vars as $v_id){
                        $v = wc_get_product($v_id);
                        if (!$v) continue;
                        $price = $this->calc_for_variation($v, $product, $rates);
                        if ($price !== null){
                             if ($apply){
                                $current_price_v = floatval($v->get_regular_price());
                                if (abs($current_price_v - $price) > 0.01) {
                                    try{
                                        $v->set_regular_price($price);
                                        $v->set_price($price);
                                        $v->save();
                                        $updated++;
                                    } catch(Exception $e){ $errors++; }
                                }
                            } else {
                                update_post_meta($v_id, 'melix_last_calc_price', $price);
                                $updated++;
                            }
                        }
                    }
                }
            }
            $paged++;
            wp_reset_postdata();
        } while (true);

        return array('updated'=>$updated, 'errors'=>$errors);
    }

    /* Calculation helpers */
    private function calc_for_product($product, $rates){
        $meta = $product->get_meta('melix_karat') ?: '';
        $weight_meta = $product->get_meta('melix_weight');
        $weight = ($weight_meta !== '') ? floatval($weight_meta) : floatval($product->get_weight() ?: 0);
        if (!$meta || $weight <= 0) return null;
        $rate = $rates['r24'];
        switch ($meta){
            case '14': $rate = $rates['r14']; break;
            case '18': $rate = $rates['r18']; break;
            case '21': $rate = $rates['r21']; break;
            case '22': $rate = $rates['r22']; break;
            case '24': $rate = $rates['r24']; break;
        }
        if ($rate <= 0) return null;
        
        $opts = get_option($this->opt_key, array());
        $market_gap = floatval($opts['market_gap'] ?? 0);
        
        // adjusted rate per gram
        $adjusted_rate = $rate + $market_gap;
        
        // base price
        $base = round($adjusted_rate * $weight, wc_get_price_decimals());
        
        $markup = $product->get_meta('melix_markup');
        if ($markup !== ''){
            $m = floatval($markup);
            if ($m > 0) $base = round($base + ($base * ($m/100)), wc_get_price_decimals());
        }
        return $base;
    }

    private function calc_for_variation($variation, $parent, $rates){
        $meta = $variation->get_meta('melix_karat');
        $weight_meta = $variation->get_meta('melix_weight');
        $weight = ($weight_meta !== '') ? floatval($weight_meta) : floatval($variation->get_weight() ?: 0);
        if (!$meta){
            $attrs = $variation->get_attributes();
            foreach ($attrs as $k=>$v){
                foreach (array('14','18','21','22','24') as $k2){
                    if (stripos($v,$k2.'k') !== false || stripos($v, $k2 . 'K') !== false) { $meta = $k2; break 2; }
                }
            }
        }
        if ($weight <= 0 && $parent){
            $pw = $parent->get_meta('melix_weight');
            $weight = ($pw !== '') ? floatval($pw) : floatval($parent->get_weight() ?: 0);
        }
        if (!$meta || $weight <= 0) return null;
        $rate = $rates['r24'];
        switch ($meta){
            case '14': $rate = $rates['r14']; break;
            case '18': $rate = $rates['r18']; break;
            case '21': $rate = $rates['r21']; break;
            case '22': $rate = $rates['r22']; break;
            case '24': $rate = $rates['r24']; break;
        }
        if ($rate <= 0) return null;
        $opts = get_option($this->opt_key, array());
        $market_gap = floatval($opts['market_gap'] ?? 0);
        
        $adjusted_rate = $rate + $market_gap;
        $base = round($adjusted_rate * $weight, wc_get_price_decimals());
        $markup = $variation->get_meta('melix_markup');
        if ($markup === '' && $parent) $markup = $parent->get_meta('melix_markup');
        if ($markup !== ''){
            $m = floatval($markup);
            if ($m > 0) $base = round($base + ($base * ($m/100)), wc_get_price_decimals());
        }
        return $base;
    }
    
    
    // Get gold rate after market gap (per gram)
        private function get_rate_after_market_gap($karat){
            $opts = get_option($this->opt_key, array());
        
            $rate_key = 'rate_' . $karat;
            if (empty($opts[$rate_key])) return '';
        
            $rate = floatval($opts[$rate_key]);
            $market_gap = floatval($opts['market_gap'] ?? 0);
        
            $final = $rate + $market_gap;
        
            return number_format_i18n($final, wc_get_price_decimals());
        }
        

    /* Shortcodes */
    public function sc_14(){ $opts = get_option($this->opt_key, array()); return isset($opts['rate_14']) ? esc_html(number_format_i18n(floatval($opts['rate_14']), wc_get_price_decimals())) : ''; }
    public function sc_18(){ $opts = get_option($this->opt_key, array()); return isset($opts['rate_18']) ? esc_html(number_format_i18n(floatval($opts['rate_18']), wc_get_price_decimals())) : ''; }
    public function sc_21(){ $opts = get_option($this->opt_key, array()); return isset($opts['rate_21']) ? esc_html(number_format_i18n(floatval($opts['rate_21']), wc_get_price_decimals())) : ''; }
    public function sc_22(){ $opts = get_option($this->opt_key, array()); return isset($opts['rate_22']) ? esc_html(number_format_i18n(floatval($opts['rate_22']), wc_get_price_decimals())) : ''; }
    public function sc_24(){ $opts = get_option($this->opt_key, array()); return isset($opts['rate_24']) ? esc_html(number_format_i18n(floatval($opts['rate_24']), wc_get_price_decimals())) : ''; }
    
    // Shortcodes AFTER market gap (amount only)
    public function sc_14_after_gap(){ return $this->get_rate_after_market_gap('14'); }
    public function sc_18_after_gap(){ return $this->get_rate_after_market_gap('18'); }
    public function sc_21_after_gap(){ return $this->get_rate_after_market_gap('21'); }
    public function sc_22_after_gap(){ return $this->get_rate_after_market_gap('22'); }
    public function sc_24_after_gap(){ return $this->get_rate_after_market_gap('24'); }

    /* LOG Helpers
       - push_transaction_log stores a single transaction entry with array of changes
       - fetch_logs returns the stored logs array
       - backward compatible: older single entries also handled
    */
    private function push_transaction_log($entry){
        $logs = get_option($this->log_key, array());
        // new entry structure: ['time','user','type','changes'=>[...],'status','note'?]
        array_unshift($logs, $entry);
        if (count($logs) > 20000) $logs = array_slice($logs,0,20000);
        update_option($this->log_key,$logs);
    }

    /* LOG Helpers - Filter Logic */
    /* LOG Helpers - Smart Filter Logic */
    private function fetch_logs($offset=0, $limit=20, $filters=array()){
        $all = get_option($this->log_key, array());
        if (empty($all)) return array();
        
        // Safety: If $filters is passed as a string (old code), convert to array
        if (is_string($filters)) {
            $filters = array('search' => $filters);
        }
        
        // Filter Logic
        $filtered = array_filter($all, function($entry) use ($filters){
            // 1. Search Text
            if (!empty($filters['search'])) {
                $s = strtolower($filters['search']);
                $search_str = strtolower(($entry['note'] ?? '') . ' ' . ($entry['user'] ?? ''));
                if (strpos($search_str, $s) === false) return false;
            }
            
            // 2. Date Filter (Matches start of "YYYY-MM-DD")
            if (!empty($filters['date'])) {
                if (strpos($entry['time'], $filters['date']) !== 0) return false;
            }

            // 3. User Filter
            if (!empty($filters['user'])) {
                if (($entry['user'] ?? '') !== $filters['user']) return false;
            }

            // 4. Karat Filter
            if (!empty($filters['karat'])) {
                $found = false;
                if (!empty($entry['changes']) && is_array($entry['changes'])) {
                    foreach ($entry['changes'] as $c) {
                        if (($c['karat'] ?? '') == $filters['karat']) { 
                            $found = true; 
                            break; 
                        }
                    }
                }
                if (!$found) return false;
            }

            return true;
        });
        
        // Re-index array
        $filtered = array_values($filtered);
        
        return array_slice($filtered, $offset, $limit);
    }

    public function ajax_load_logs(){
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);
        
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        $limit  = isset($_POST['limit']) ? intval($_POST['limit']) : 20;
        
        // Capture filters correctly
        $filters = array(
            'search' => isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '',
            'date'   => isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '',
            'user'   => isset($_POST['user']) ? sanitize_text_field($_POST['user']) : '',
            'karat'  => isset($_POST['karat']) ? sanitize_text_field($_POST['karat']) : '',
        );

        // Fetch items
        $items = $this->fetch_logs($offset, $limit, $filters);
        
        // Fetch total count for pagination
        $all_matches = $this->fetch_logs(0, 50000, $filters); 
        $count = count($all_matches);

        $rows = array();
        foreach ($items as $e) $rows[] = $this->render_log_row($e);
        
        wp_send_json_success(array('rows'=>$rows, 'count'=>$count));
    }

    private function render_log_row($entry){
        // Skip invalid/legacy entries
        if (isset($entry['summary']) && isset($entry['time'])) return ''; 

        $time = esc_html($entry['time'] ?? '');
        $user = esc_html($entry['user'] ?? '');
        $status = ($entry['status'] ?? 'success');
        $note = isset($entry['note']) ? esc_html($entry['note']) : '';
        $changes = isset($entry['changes']) && is_array($entry['changes']) ? $entry['changes'] : array();
        $stats = isset($entry['stats']) ? $entry['stats'] : array();

        // 1. Build Transaction Column HTML
        $trans_html = '';
        if (!empty($changes)){
            foreach ($changes as $c){
                $karat = esc_html($c['karat'] ?? '');
                $old = esc_html((string)($c['old'] ?? ''));
                $new = esc_html((string)($c['new'] ?? ''));
                $dir = $c['dir'] ?? 'eq';
                
                // Assign classes for colorful animated arrows
                $icon = '→'; 
                $arrow_class = 'melix-arrow-eq';
                if ($dir === 'up') { 
                    $icon = '↑'; 
                    $arrow_class = 'melix-arrow-up'; 
                } elseif ($dir === 'down') { 
                    $icon = '↓'; 
                    $arrow_class = 'melix-arrow-down'; 
                }
                
                $trans_html .= '<div class="melix-change-row"><span class="'.$arrow_class.'">'.$icon.'</span> <strong>'.$karat.'K</strong> '.$old.' → '.$new.'</div>';
            }
        } else {
            // Show note if no rate changes (e.g. "API disabled" or "Settings saved")
            $trans_html = '<div class="melix-change-row">'.($note ? $note : 'No changes').'</div>';
        }

        // 2. Build Status Column HTML
        // First element: The Badge
        $status_html = $status === 'error' ? '<span class="melix-status melix-error">Error</span>' : '<span class="melix-status melix-success">Success</span>';
        
        // Second element: The Stats (Stacked underneath because parent has flex-direction: column)
        if (!empty($stats) && ($stats['updated'] > 0 || $stats['errors'] > 0)) {
            $status_html .= '<div style="font-size:0.85em; color:#666; line-height:1.4; text-align:center; margin-top:2px;">';
            
            if ($stats['updated'] > 0) {
                $status_html .= '<div>Updated: <strong>'.$stats['updated'].'</strong></div>';
            }
            if ($stats['errors'] > 0) {
                $status_html .= '<div style="color:#d63638;">Failed: <strong>'.$stats['errors'].'</strong></div>';
            }
            
            $status_html .= '</div>';
        }

        // 3. Return Full Row
        // .col-status has "align-items: center" from CSS, so everything centers perfectly
        return '<div class="melix-log-row"><div class="col-transaction">'.$trans_html.'</div><div class="col-status">'.$status_html.'</div><div class="col-time">'.$time.' by '.$user.'</div></div>';
    }


    /* Logs Page (Clean & Filtered) */
    public function render_logs_page(){
        if (!current_user_can('manage_options')) return;
        
        $all_logs = get_option($this->log_key, array());
        $users = array();
        foreach($all_logs as $l) {
            if(!empty($l['user'])) $users[$l['user']] = $l['user'];
        }
        ?>
        <div class="wrap melix-wrap">
            <h1 class="wp-heading-inline">Melix Gold Logs</h1>
            <hr class="wp-header-end">

            <div class="melix-filters-bar">
                <input type="date" id="filter-date" class="regular-text">
                
                <select id="filter-user">
                    <option value="">All Users</option>
                    <option value="Live Gold API">Live Gold API</option>
                    <?php foreach($users as $u): if($u === 'Live Gold API') continue; ?>
                        <option value="<?php echo esc_attr($u); ?>"><?php echo esc_html($u); ?></option>
                    <?php endforeach; ?>
                </select>

                <select id="filter-karat">
                    <option value="">All Karats</option>
                    <option value="14">14K</option>
                    <option value="18">18K</option>
                    <option value="21">21K</option>
                    <option value="22">22K</option>
                    <option value="24">24K</option>
                </select>

                <input type="text" id="melix-log-search" placeholder="Search notes...">
                <button id="melix-apply-filters" class="button button-primary">Filter Logs</button>
                <button id="melix-reset-filters" class="button button-secondary">Reset</button>
            </div>

            <div id="melix-logs-dashboard">
                <div class="melix-log-table-headers">
                    <div class="col-transaction">Transaction</div>
                    <div class="col-status">Status</div>
                    <div class="col-time">Time</div>
                </div>
                <div id="melix-logs-full"></div>
            </div>

            <div id="melix-logs-pagination"></div>
        </div>

        <script>
        (function($){
            var perPage=100; // SHOW 100 RECORDS
            var page=1;
            function loadLogs(){
                var data = {
                    action: 'melix_load_logs', offset: (page-1)*perPage, limit: perPage,
                    search: $('#melix-log-search').val(), date: $('#filter-date').val(),
                    user: $('#filter-user').val(), karat: $('#filter-karat').val()
                };
                $('#melix-logs-full').html('<p style="padding:20px; color:#666;">Loading...</p>');
                $.post(ajaxurl, data, function(resp){
                    if (resp.success){
                        if(resp.data.rows.length > 0) {
                            $('#melix-logs-full').html(resp.data.rows.join(''));
                        } else {
                            $('#melix-logs-full').html('<p style="padding:20px;">No logs found matching your filters.</p>');
                        }
                        var total = resp.data.count; var pages = Math.ceil(total/perPage) || 1;
                        var html='';
                        if(pages > 1){
                            if(page > 1) html += '<button class="button melix-page-btn" data-page="'+(page-1)+'">« Prev</button> ';
                            var start = Math.max(1, page-2); var end = Math.min(pages, page+2);
                            for(var i=start; i<=end; i++){
                                var active = (i === page) ? 'button-primary' : 'button-secondary';
                                html += '<button class="button melix-page-btn '+active+'" data-page="'+i+'">'+i+'</button> ';
                            }
                            if(page < pages) html += '<button class="button melix-page-btn" data-page="'+(page+1)+'">Next »</button>';
                            html += '<span style="margin-left:10px; line-height:28px; color:#666;">'+total+' items</span>';
                        }
                        $('#melix-logs-pagination').html(html);
                    } else { $('#melix-logs-full').html('<p style="padding:20px; color:red;">Error loading logs.</p>'); }
                });
            }
            $('#melix-apply-filters').on('click', function(){ page=1; loadLogs(); });
            $('#melix-reset-filters').on('click', function(){
                $('#melix-log-search').val(''); $('#filter-date').val(''); $('#filter-user').val(''); $('#filter-karat').val('');
                page=1; loadLogs();
            });
            $(document).on('click','.melix-page-btn',function(){ page=parseInt($(this).data('page')); loadLogs(); });
            loadLogs();
        })(jQuery);
        </script>
        <?php
    }

    /* Helpers */
    private function count_products_by_karat($karat){
        global $wpdb;
        return intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key=%s AND meta_value=%s", 'melix_karat', $karat)));
    }

    /* Product admin fields */
    public function product_fields(){
        echo '<div class="options_group">';
        woocommerce_wp_select(array('id'=>'melix_karat','label'=>'Gold Type','options'=>array(''=>'— Select —','14'=>'Gold (14K)','18'=>'Gold (18K)','21'=>'Gold (21K)','22'=>'Gold (22K)','24'=>'Gold (24K)'),'description'=>'Choose karat for price calculation'));
        woocommerce_wp_text_input(array('id'=>'melix_weight','label'=>'Product Weight (grams)','type'=>'number','custom_attributes'=>array('step'=>'0.01','min'=>'0'),'description'=>'Weight in grams'));
        echo '<hr style="margin:10px 0;" />';
        woocommerce_wp_text_input(array('id'=>'melix_markup','label'=>'Markup (%)','type'=>'number','custom_attributes'=>array('step'=>'0.01','min'=>'0'),'description'=>'Percentage markup added to base price'));
        echo '</div>';
    }

    public function save_product_meta($post_id){
        $product = wc_get_product($post_id);
        if (!$product) return;
        $karat = isset($_POST['melix_karat']) ? sanitize_text_field(wp_unslash($_POST['melix_karat'])) : '';
        $weight = isset($_POST['melix_weight']) ? sanitize_text_field(wp_unslash($_POST['melix_weight'])) : '';
        $markup = isset($_POST['melix_markup']) ? sanitize_text_field(wp_unslash($_POST['melix_markup'])) : '';
        update_post_meta($post_id,'melix_karat',$karat);
        update_post_meta($post_id,'melix_weight',$weight);
        update_post_meta($post_id,'melix_markup',$markup);

        $opts = get_option($this->opt_key, array());
        if (!empty($opts['apply_to_db'])){
            $rates = array(
                'r14'=>floatval($opts['rate_14'] ?? 0),
                'r18'=>floatval($opts['rate_18'] ?? 0),
                'r21'=>floatval($opts['rate_21'] ?? 0),
                'r22'=>floatval($opts['rate_22'] ?? 0),
                'r24'=>floatval($opts['rate_24'] ?? 0)
            );
            $price = $this->calc_for_product($product, $rates);
            if ($price !== null){ $product->set_regular_price($price); $product->set_price($price); $product->save(); }
        }
    }

    public function variation_fields($loop, $variation_data, $variation){
        $variation_id = $variation->ID;
        $karat = get_post_meta($variation_id,'melix_karat',true);
        $weight = get_post_meta($variation_id,'melix_weight',true);
        $markup = get_post_meta($variation_id,'melix_markup',true);
        woocommerce_wp_select(array('id'=>"melix_karat[{$variation_id}]",'label'=>'Gold Type','value'=>$karat,'options'=>array(''=>'— Select —','14'=>'Gold (14K)','18'=>'Gold (18K)','21'=>'Gold (21K)','22'=>'Gold (22K)','24'=>'Gold (24K)')));
        woocommerce_wp_text_input(array('id'=>"melix_weight[{$variation_id}]",'label'=>'Product Weight (grams)','value'=>$weight,'type'=>'number','custom_attributes'=>array('step'=>'0.01','min'=>'0')));
        echo '<hr style="margin:8px 0;" />';
        woocommerce_wp_text_input(array('id'=>"melix_markup[{$variation_id}]",'label'=>'Markup (%)','value'=>$markup,'type'=>'number','custom_attributes'=>array('step'=>'0.01','min'=>'0')));
    }

    public function save_variation_meta($variation_id,$i){
        if (isset($_POST['melix_karat'][$variation_id])) update_post_meta($variation_id,'melix_karat',sanitize_text_field(wp_unslash($_POST['melix_karat'][$variation_id])));
        if (isset($_POST['melix_weight'][$variation_id])) update_post_meta($variation_id,'melix_weight',sanitize_text_field(wp_unslash($_POST['melix_weight'][$variation_id])));
        if (isset($_POST['melix_markup'][$variation_id])) update_post_meta($variation_id,'melix_markup',sanitize_text_field(wp_unslash($_POST['melix_markup'][$variation_id])));

        $opts = get_option($this->opt_key, array());
        if (!empty($opts['apply_to_db'])){
            $variation = wc_get_product($variation_id);
            $parent = wc_get_product($variation->get_parent_id());
            $rates = array(
                'r14'=>floatval($opts['rate_14'] ?? 0),
                'r18'=>floatval($opts['rate_18'] ?? 0),
                'r21'=>floatval($opts['rate_21'] ?? 0),
                'r22'=>floatval($opts['rate_22'] ?? 0),
                'r24'=>floatval($opts['rate_24'] ?? 0)
            );
            $price = $this->calc_for_variation($variation, $parent, $rates);
            if ($price !== null){ $variation->set_regular_price($price); $variation->set_price($price); $variation->save(); }
        }
    }

    // Added missing method for product price override
    public function override_product_price($price, $product) {
        if (is_admin() && !wp_doing_ajax()) return $price;

        $opts = get_option($this->opt_key, array());
        $rates = array(
            'r14' => floatval($opts['rate_14'] ?? 0),
            'r18' => floatval($opts['rate_18'] ?? 0),
            'r21' => floatval($opts['rate_21'] ?? 0),
            'r22' => floatval($opts['rate_22'] ?? 0),
            'r24' => floatval($opts['rate_24'] ?? 0)
        );

        $new_price = $this->calc_for_product($product, $rates);
        if ($new_price !== null) {
            return $new_price;
        }

        return $price;
    }

    // Added missing method for variation price override
    public function override_variation_price($price, $variation, $product) {
        if (is_admin() && !wp_doing_ajax()) return $price;

        $opts = get_option($this->opt_key, array());
        $rates = array(
            'r14' => floatval($opts['rate_14'] ?? 0),
            'r18' => floatval($opts['rate_18'] ?? 0),
            'r21' => floatval($opts['rate_21'] ?? 0),
            'r22' => floatval($opts['rate_22'] ?? 0),
            'r24' => floatval($opts['rate_24'] ?? 0)
        );

        $new_price = $this->calc_for_variation($variation, $product, $rates);
        if ($new_price !== null) {
            return $new_price;
        }

        return $price;
    }

} // end class

new Melix_Rates_Karats();