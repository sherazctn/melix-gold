<?php
/*
Plugin Name: Melix Gold
Description: Gold karat rates (14K,18K,21K,22K,24K). Manual inputs + optional auto-fill 24K from GoldPriceZ API (AED). Hourly cron, auto-recalculate WC prices, shortcodes, logs, robust product meta UI.
Version: 1.0.3 
Author: NETREX / Sheraz
*/

if (!defined('ABSPATH')) exit;

class Melix_Rates_Karats {
    private $opt_key = 'melix_rates_karats_options';
    private $log_key = 'melix_rates_karats_logs';
    private $cron_hook = 'melix_rates_karats_hourly_fetch';
    private $batch_size = 100;

    public function __construct(){
        register_activation_hook(__FILE__, array($this,'activation'));
        register_deactivation_hook(__FILE__, array($this,'deactivation'));

        add_action('admin_menu', array($this,'admin_menu'));
        add_action('admin_post_melix_save_settings', array($this,'handle_save_settings'));
        add_action('admin_post_melix_fetch_now', array($this,'admin_fetch_now'));
        add_action('admin_enqueue_scripts', array($this,'admin_assets'));

        // product admin
        add_action('woocommerce_product_options_pricing', array($this,'product_fields'));
        add_action('woocommerce_process_product_meta', array($this,'save_product_meta'));
        add_action('woocommerce_variation_options_pricing', array($this,'variation_fields'), 10, 3);
        add_action('woocommerce_save_product_variation', array($this,'save_variation_meta'), 10, 2);

        // runtime overrides
        add_filter('woocommerce_product_get_price', array($this,'override_product_price'), 99, 2);
        add_filter('woocommerce_product_get_regular_price', array($this,'override_product_price'), 99, 2);
        add_filter('woocommerce_variation_prices_price', array($this,'override_variation_price'), 99, 3);

        // logs AJAX
        add_action('wp_ajax_melix_load_logs', array($this,'ajax_load_logs'));

        // cron
        add_action($this->cron_hook, array($this,'cron_fetch_rates'));

        // shortcodes
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
        add_menu_page('Melix Gold','Melix Gold','manage_options','melix-karats',array($this,'settings_page'),'dashicons-groups',56);
        add_submenu_page('melix-karats','Logs','Logs','manage_options','melix-karats-logs',array($this,'render_logs_page'));
    }

    public function admin_assets($hook){
        if (in_array($hook, array('toplevel_page_melix-karats','product.php','post.php','post-new.php','admin_page_melix-karats-logs'))){
            wp_enqueue_script('melix-karats-js', plugin_dir_url(__FILE__).'melix-karats-admin.js', array('jquery'), '1.2', true);
            wp_enqueue_style('melix-karats-css', plugin_dir_url(__FILE__).'melix-karats-admin.css', array(), '1.2');
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
                'api_reason' => $opts['api_reason'] ?? '',
                'use_api' => !empty($opts['use_api']) ? 1 : 0
            ));
        }
    }

    /* Settings page HTML */
    public function settings_page(){
        if (!current_user_can('manage_options')) return;
        $opts = get_option($this->opt_key, array());
        $counts = array(
            '14' => $this->count_products_by_karat('14'),
            '18' => $this->count_products_by_karat('18'),
            '21' => $this->count_products_by_karat('21'),
            '22' => $this->count_products_by_karat('22'),
            '24' => $this->count_products_by_karat('24'),
        );
        ?>
        <div class="wrap melix-wrap">
            <h1>Melix Gold — Settings</h1>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('melix_save_settings','melix_nonce');?>
                <input type="hidden" name="action" value="melix_save_settings" />
                <table class="form-table">
                    <tr>
                        <th>Use API</th>
                        <td>
                            <label><input id="melix_use_api" name="use_api" type="checkbox" value="1" <?php checked(1, $opts['use_api'] ?? 0);?>> Use GoldPriceZ API (auto-fill 24K)</label>
                            <p class="description">If unchecked you can manage all karat rates manually only.</p>
                        </td>
                    </tr>

                    <tbody id="melix-api-settings" style="<?php echo !empty($opts['use_api']) ? '' : 'display:none;'; ?>">
                        <tr><th>GoldPriceZ API Key</th><td><input name="api_key" type="text" value="<?php echo esc_attr($opts['api_key'] ?? '');?>" class="regular-text" placeholder="API key (optional)"></td></tr>
                        <tr><th>API Status</th><td>
                            <?php
                            $status = $opts['api_status'] ?? 'unknown';
                            $reason = $opts['api_reason'] ?? '';
                            if ($status === 'active'){
                                echo '<span class="melix-api-status melix-active">ACTIVE</span>';
                            } else {
                                echo '<span class="melix-api-status melix-inactive">NOT WORKING</span>';
                                if ($reason) echo ' <span class="melix-api-reason">('.esc_html($reason).')</span>';
                            }
                            ?>
                            <p class="description">API auto-checks on fetch (manual or cron). If active, 24K will auto-populate and other karats will be calculated. You can still override any rate manually.</p>
                        </td></tr>

                        <tr><th>Auto Fetch (hourly)</th><td><label><input type="checkbox" name="auto_fetch" value="1" <?php checked(1, $opts['auto_fetch'] ?? 0);?>> Enable hourly API fetch (24K)</label></td></tr>
                    </tbody>

                    <tr><th>Apply to DB</th><td><label><input type="checkbox" name="apply_to_db" value="1" <?php checked(1, $opts['apply_to_db'] ?? 0);?>> Write calculated prices into product DB on save and during recalculation</label></td></tr>
                </table>
                
                <table class="form-table">
                    <tr>
                        <th>Market Gap (AED / gram)</th>
                        <td>
                            <input name="market_gap" type="number" step="0.01"
                                   value="<?php echo esc_attr($opts['market_gap'] ?? ''); ?>"
                                   class="regular-text">
                            <p class="description">
                                This amount will be added to each gram before weight calculation.
                            </p>
                        </td>
                    </tr>
                </table>

                <h2>Gold Rates (AED / Gram)</h2>
                <p class="description">You can enter rates manually. If API active, 24K will be auto-filled and others auto-calculated if empty or previously auto-derived. You can still type over them to override.</p>
                <table class="form-table">
                    <tr><th>14K</th><td><input name="rate_14" type="number" id="melix_rate_14" step="0.0001" value="<?php echo esc_attr($opts['rate_14'] ?? '');?>" class="regular-text"><div class="melix-gap-preview" data-karat="14"></div></td><td><?php echo intval($counts['14']);?> products</td></tr>
                    <tr><th>18K</th><td><input name="rate_18" type="number" id="melix_rate_18" step="0.0001" value="<?php echo esc_attr($opts['rate_18'] ?? '');?>" class="regular-text"><div class="melix-gap-preview" data-karat="18"></div></td><td><?php echo intval($counts['18']);?> products</td></tr>
                    <tr><th>21K</th><td><input name="rate_21" type="number" id="melix_rate_21" step="0.0001" value="<?php echo esc_attr($opts['rate_21'] ?? '');?>" class="regular-text"><div class="melix-gap-preview" data-karat="21"></div></td><td><?php echo intval($counts['21']);?> products</td></tr>
                    <tr><th>22K</th><td><input name="rate_22" type="number" id="melix_rate_22" step="0.0001" value="<?php echo esc_attr($opts['rate_22'] ?? '');?>" class="regular-text"><div class="melix-gap-preview" data-karat="22"></div></td><td><?php echo intval($counts['22']);?> products</td></tr>
                    <tr><th>24K</th><td><input name="rate_24" id="melix_rate_24" type="number" step="0.0001" value="<?php echo esc_attr($opts['rate_24'] ?? '');?>" class="regular-text"><div class="melix-gap-preview" data-karat="24"></div></td><td><?php echo intval($counts['24']);?> products</td></tr>
                </table>

                <?php submit_button('Save Settings & Recalculate'); ?>
            </form>

            <p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php'));?>" style="display:inline;">
                <?php wp_nonce_field('melix_fetch_now','melix_fetch_now_nonce');?>
                <input type="hidden" name="action" value="melix_fetch_now">
                <button class="button button-primary" type="submit">Fetch Rates Now</button>
            </form>
            </p>

            <h2 style="margin-top:16px;">Recent Logs</h2>
            <div id="melix-logs-dashboard">
                <div class="melix-log-table-headers">
                    <div class="col-transaction"><strong>Transaction</strong></div>
                    <div class="col-status"><strong>Status</strong></div>
                    <div class="col-time"><strong>Time</strong></div>
                </div>
                <div id="melix-logs-list">
                    <?php
                    $initial = $this->fetch_logs(0,20);
                    foreach ($initial as $entry) echo $this->render_log_row($entry);
                    ?>
                </div>
                <div style="margin-top:8px;">
                    <button id="melix-load-more" class="button">Load more</button>
                    <span id="melix-logs-counter" style="margin-left:12px;"></span>
                </div>
            </div>

        </div>
        <?php
    }

    /* Save settings and create grouped transaction log */
    public function handle_save_settings(){
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('melix_save_settings','melix_nonce');
        $opts = get_option($this->opt_key, array());
        $old = $opts;

        $opts['use_api'] = isset($_POST['use_api']) ? 1 : 0;
        $opts['api_key'] = isset($_POST['api_key']) ? sanitize_text_field(wp_unslash($_POST['api_key'])) : $opts['api_key'];
        $opts['auto_fetch'] = isset($_POST['auto_fetch']) ? 1 : 0;
        $opts['apply_to_db'] = isset($_POST['apply_to_db']) ? 1 : 0;

        $opts['rate_14'] = isset($_POST['rate_14']) ? sanitize_text_field(wp_unslash($_POST['rate_14'])) : $opts['rate_14'];
        $opts['rate_18'] = isset($_POST['rate_18']) ? sanitize_text_field(wp_unslash($_POST['rate_18'])) : $opts['rate_18'];
        $opts['rate_21'] = isset($_POST['rate_21']) ? sanitize_text_field(wp_unslash($_POST['rate_21'])) : $opts['rate_21'];
        $opts['rate_22'] = isset($_POST['rate_22']) ? sanitize_text_field(wp_unslash($_POST['rate_22'])) : $opts['rate_22'];
        $opts['rate_24'] = isset($_POST['rate_24']) ? sanitize_text_field(wp_unslash($_POST['rate_24'])) : $opts['rate_24'];
        
        $opts['market_gap'] = isset($_POST['market_gap']) ? sanitize_text_field(wp_unslash($_POST['market_gap'])) : ($opts['market_gap'] ?? '');

        update_option($this->opt_key, $opts);

        // gather changed karats into a single transaction
        $changes = array();
        foreach (array('rate_14'=>'14','rate_18'=>'18','rate_21'=>'21','rate_22'=>'22','rate_24'=>'24') as $k=>$label){
            $oldv = isset($old[$k]) ? $old[$k] : '';
            $newv = isset($opts[$k]) ? $opts[$k] : '';
            if ((string)$oldv !== (string)$newv){
                // determine direction arrow
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

        if (!empty($changes)){
            $this->push_transaction_log(array(
                'time'=>current_time('mysql'),
                'user'=>wp_get_current_user()->user_login,
                'type'=>'settings_save',
                'changes'=>$changes,
                'status'=>'success'
            ));
            $this->recalculate_all_products($opts);
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

        wp_safe_redirect(admin_url('admin.php?page=melix-karats'));
        exit;
    }

    /* Manual fetch now */
    public function admin_fetch_now(){
        if (!current_user_can('manage_options')) wp_die('Unauthorized');
        check_admin_referer('melix_fetch_now','melix_fetch_now_nonce');
        $this->fetch_and_update_rates(true);
        wp_safe_redirect(admin_url('admin.php?page=melix-karats'));
        exit;
    }

    /* Cron handler */
    public function cron_fetch_rates(){ $this->fetch_and_update_rates(false); }

    /* Fetch API and update rates — log as single transaction */
    public function fetch_and_update_rates($is_manual=false){
        $opts = get_option($this->opt_key, array());
        if (empty($opts['use_api'])) {
            $opts['api_status'] = 'inactive';
            $opts['api_reason'] = 'API disabled';
            update_option($this->opt_key, $opts);
            $this->push_transaction_log(array(
                'time'=>current_time('mysql'),
                'user'=>($is_manual?wp_get_current_user()->user_login:'cron'),
                'type'=>'fetch',
                'changes'=>array(),
                'status'=>'error',
                'note'=>'API disabled by admin'
            ));
            return;
        }

        $api_key = $opts['api_key'] ?? '';
        if (empty($api_key)){
            $opts['api_status'] = 'inactive';
            $opts['api_reason'] = 'API key missing';
            update_option($this->opt_key,$opts);
            $this->push_transaction_log(array(
                'time'=>current_time('mysql'),
                'user'=>($is_manual?wp_get_current_user()->user_login:'cron'),
                'type'=>'fetch',
                'changes'=>array(),
                'status'=>'error',
                'note'=>'API key missing'
            ));
            return;
        }

        $url = 'https://goldpricez.com/api/rates/currency/aed/measure/gram/metal/gold?key=' . urlencode($api_key);
        $response = wp_remote_get($url, array('timeout'=>20));
        if (is_wp_error($response)){
            $opts['api_status'] = 'inactive';
            $opts['api_reason'] = $response->get_error_message();
            update_option($this->opt_key,$opts);
            $this->push_transaction_log(array(
                'time'=>current_time('mysql'),
                'user'=>($is_manual?wp_get_current_user()->user_login:'cron'),
                'type'=>'fetch',
                'changes'=>array(),
                'status'=>'error',
                'note'=>$opts['api_reason']
            ));
            return;
        }

        $body = wp_remote_retrieve_body($response);
        $json = json_decode($body, true);
        if (!$json || (!isset($json['gram_in_aed']) && !isset($json['data']['gram']))) {
            $opts['api_status'] = 'inactive';
            $opts['api_reason'] = 'Invalid API response';
            update_option($this->opt_key,$opts);
        
            $this->push_transaction_log(array(
                'time'=>current_time('mysql'),
                'user'=>($is_manual?wp_get_current_user()->user_login:'cron'),
                'type'=>'fetch',
                'changes'=>array(),
                'status'=>'error',
                'note'=>'Invalid API response'
            ));
            return;
        }

        $old = $opts;
        $old24 = floatval($opts['rate_24'] ?? 0);
        $new24 = 0;
        if (isset($json['gram_in_aed'])) {
            $new24 = floatval($json['gram_in_aed']);
        } elseif (isset($json['data']['gram'])) {
            $new24 = floatval($json['data']['gram']);
        }
        $changes = array();
        if ($new24 > 0 && $new24 != $old24){
            $opts['rate_24'] = $new24;
            $changes[] = array('karat'=>'24','old'=>$old24,'new'=>$new24,'dir'=> ($new24 > $old24 ? 'up' : 'down'));
        }

        // Auto-calculate other karats if not overridden
        $karats = array('14'=>14/24, '18'=>18/24, '21'=>21/24, '22'=>22/24);
        foreach ($karats as $k=>$factor){
            $key = 'rate_'.$k;
            $oldv = floatval($old[$key] ?? 0);
            $newv = round($new24 * $factor, 4);
            if (!$oldv || $oldv == round($old24 * $factor, 4)){  // Auto if empty or previously auto
                $opts[$key] = $newv;
                if ($newv != $oldv) $changes[] = array('karat'=>$k,'old'=>$oldv,'new'=>$newv,'dir'=> ($newv > $oldv ? 'up' : 'down'));
            }
        }

        $opts['api_status'] = 'active';
        $opts['api_reason'] = '';
        $opts['last_fetch'] = current_time('mysql');
        update_option($this->opt_key, $opts);

        $this->push_transaction_log(array(
            'time'=>current_time('mysql'),
            'user'=>($is_manual?wp_get_current_user()->user_login:'cron'),
            'type'=>'fetch',
            'changes'=>$changes,
            'status'=>'success'
        ));

        if (!empty($changes) && !empty($opts['apply_to_db'])){
            $this->recalculate_all_products($opts);
        }
    }

    /* Recalculate all products (batched) */
    private function recalculate_all_products($opts){
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
            $q = new WP_Query(array('post_type'=>'product','posts_per_page'=>$this->batch_size,'paged'=>$paged,'fields'=>'ids'));
            if (!$q->have_posts()) break;
            foreach ($q->posts as $id){
                $product = wc_get_product($id);
                if (!$product) continue;
                $price = $this->calc_for_product($product, $rates);
                if ($price !== null){
                    if ($apply){
                        try{
                            $product->set_regular_price($price);
                            $product->set_price($price);
                            $product->save();
                            $updated++;
                        } catch(Exception $e){
                            $errors++;
                        }
                    } else {
                        update_post_meta($id, 'melix_last_calc_price', $price);
                        $updated++;
                    }
                }

                if ($product->is_type('variable')){
                    $vars = $product->get_children();
                    foreach ($vars as $v_id){
                        $v = wc_get_product($v_id);
                        if (!$v) continue;
                        $price = $this->calc_for_variation($v, $product, $rates);
                        if ($price !== null){
                            if ($apply){
                                try{
                                    $v->set_regular_price($price);
                                    $v->set_price($price);
                                    $v->save();
                                    $updated++;
                                } catch(Exception $e){
                                    $errors++;
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

        $this->push_transaction_log(array(
            'time'=>current_time('mysql'),
            'user'=>wp_get_current_user()->user_login,
            'type'=>'recalc',
            'changes'=>array(),
            'status'=>($errors ? 'error' : 'success'),
            'note'=>"Updated $updated products".($errors ? ", $errors errors" : '')
        ));
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

    private function fetch_logs($offset=0,$limit=20,$search=''){
        $all = get_option($this->log_key, array());
        if (empty($all)) return array();
        if ($search){
            $s = strtolower($search);
            $fil = array();
            foreach ($all as $e) if (strpos(strtolower((isset($e['summary'])?$e['summary']:'') . ' ' . (isset($e['user'])?$e['user']:'')), $s) !== false) $fil[]=$e;
            return array_slice($fil,$offset,$limit);
        }
        return array_slice($all,$offset,$limit);
    }

    /* Render single transaction row. handles new and old formats */
    private function render_log_row($entry){
        // if old format exists (summary/status/time)
        if (isset($entry['summary']) && isset($entry['time'])){
            // render old single-row entry for compatibility
            $time = esc_html($entry['time']);
            $user = esc_html($entry['user'] ?? '');
            $summary = esc_html($entry['summary']);
            $status = ($entry['status'] ?? 'success');
            $icon = '→';
            if (strpos($summary,'→')!==false){
                // try to detect up/down
                preg_match('/([\d\.]+)\s*→\s*([\d\.]+)/',$summary,$m);
                if (isset($m[1]) && isset($m[2])){
                    $old = floatval($m[1]); $new = floatval($m[2]);
                    if ($new > $old) $icon = '↑'; elseif ($new < $old) $icon = '↓'; else $icon = '→';
                }
            }
            $status_html = $status === 'error' ? '<span class="melix-status melix-error">Error</span>' : '<span class="melix-status melix-success">Success</span>';
            return '<div class="melix-log-row"><div class="col-transaction"><span class="melix-arrow">'.$icon.'</span> <span class="melix-summary">'.$summary.'</span></div><div class="col-status">'.$status_html.'</div><div class="col-time">'. $time . ' by ' . $user .'</div></div>';
        }

        // New transaction format
        $time = esc_html($entry['time'] ?? '');
        $user = esc_html($entry['user'] ?? '');
        $status = ($entry['status'] ?? 'success');
        $note = isset($entry['note']) ? esc_html($entry['note']) : '';
        $changes = isset($entry['changes']) && is_array($entry['changes']) ? $entry['changes'] : array();

        // build transaction HTML: list changes stacked but inside same col
        $trans_html = '';
        if (!empty($changes)){
            foreach ($changes as $c){
                $karat = esc_html($c['karat'] ?? '');
                $old = esc_html((string)($c['old'] ?? ''));
                $new = esc_html((string)($c['new'] ?? ''));
                $dir = $c['dir'] ?? 'eq';
                $icon = '→';
                if ($dir === 'up') $icon = '↑';
                elseif ($dir === 'down') $icon = '↓';
                elseif ($dir === 'eq') $icon = '→';
                $trans_html .= '<div class="melix-change-row"><span class="melix-change-icon">'.$icon.'</span> <strong>'.$karat.'K</strong> '.$old.' → '.$new.'</div>';
            }
        } else {
            $trans_html = '<div class="melix-change-row">'.($note ? $note : 'No changes').'</div>';
        }

        $status_html = $status === 'error' ? '<span class="melix-status melix-error">Error</span>' : '<span class="melix-status melix-success">Success</span>';

        return '<div class="melix-log-row"><div class="col-transaction">'.$trans_html.'</div><div class="col-status">'.$status_html.'</div><div class="col-time">'.$time.' by '.$user.'</div></div>';
    }

    public function ajax_load_logs(){
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized',403);
        $offset = isset($_POST['offset'])?intval($_POST['offset']):0;
        $limit = isset($_POST['limit'])?intval($_POST['limit']):20;
        $search = isset($_POST['search'])?sanitize_text_field($_POST['search']):'';
        $items = $this->fetch_logs($offset,$limit,$search);
        $rows = array();
        foreach ($items as $e) $rows[] = $this->render_log_row($e);
        $all = get_option($this->log_key,array());
        $count = $search ? count($this->fetch_logs(0,PHP_INT_MAX,$search)) : count($all);
        wp_send_json_success(array('rows'=>$rows,'count'=>$count));
    }

    public function render_logs_page(){
        if (!current_user_can('manage_options')) return;
        ?>
        <div class="wrap melix-wrap">
            <h1>Melix Gold — Logs</h1>
            <div style="margin:12px 0;">
                <input id="melix-log-search" placeholder="Search logs..." style="width:40%; padding:6px;">
                <button id="melix-log-search-btn" class="button">Search</button>
            </div>

            <div class="melix-log-table-headers">
                <div class="col-transaction"><strong>Transaction</strong></div>
                <div class="col-status"><strong>Status</strong></div>
                <div class="col-time"><strong>Time</strong></div>
            </div>

            <div id="melix-logs-full"></div>
            <div id="melix-logs-pagination" style="margin-top:12px;"></div>
        </div>
        <script>
        (function($){
            var perPage=100; var page=1;
            function loadPage(p,q){
                var offset=(p-1)*perPage;
                $.post(ajaxurl,{action:'melix_load_logs',offset:offset,limit:perPage,search:q},function(resp){
                    if (resp.success){
                        $('#melix-logs-full').html(resp.data.rows.join(''));
                        var total = resp.data.count; var pages = Math.ceil(total/perPage)||1;
                        var html='';
                        for(var i=1;i<=pages;i++) html += '<button class="button melix-page-btn" data-page="'+i+'">'+i+'</button> ';
                        $('#melix-logs-pagination').html(html);
                    } else $('#melix-logs-full').html('<p>No logs.</p>');
                });
            }
            $(document).on('click','.melix-page-btn',function(){ page=parseInt($(this).data('page')); loadPage(page,$('#melix-log-search').val()); });
            $('#melix-log-search-btn').on('click',function(){ loadPage(1,$('#melix-log-search').val()); });
            loadPage(1,'');
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