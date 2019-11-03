<?php
/**
 * Classic Editor
 *
 * Plugin Name: MKM API
 * Plugin URI:  https://wordpress.org
 * Version:     1.0.0
 * Description: The plugin receives data MKM API
 * Author:      Dmitriy Kovalev
 * Author URI:  https://www.upwork.com/freelancers/~014907274b0c121eb9
 * License:     GPLv2 or later
 * License URI: http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: classic-editor
 * Domain Path: /languages
 *
 */

    register_activation_hook( __FILE__, 'mkm_api_create_table' );
    register_activation_hook( __FILE__, 'mkm_api_activation' );
    register_deactivation_hook( __FILE__, 'mkm_api_deactivation' );
    add_action( 'admin_menu', 'mkm_api_admin_menu' );
    add_action( 'admin_init', 'mkm_api_admin_settings' );
    add_action( 'wp_ajax_mkm_api_delete_key', 'mkm_api_ajax_delete_key' );
    add_action( 'wp_ajax_mkm_api_ajax_data', 'mkm_api_ajax_get_data' );
    add_action( 'wp_ajax_mkm_api_change_cron_select', 'mkm_api_ajax_change_cron_select' );
    add_action( 'admin_enqueue_scripts', 'mkm_api_enqueue_admin' );
    add_action( 'admin_print_footer_scripts-toplevel_page_mkm-api-options', 'mkm_api_modal_to_footer');
    add_filter( 'cron_schedules', 'mkm_api_add_schedules', 20 );

    if ( !function_exists( 'dump' ) ) {
		function dump( $var ) {
			echo '<pre style="color: #c3c3c3; background-color: #282923;">';
			print_r( $var );
			echo '</pre>';
		}
    }

    function mkm_api_deactivation() {
        $options = get_option( 'mkm_api_options' );
        if ( is_array( $options ) && count( $options ) > 0 ) {
            foreach( $options as $key => $value ) {
                if ( wp_next_scheduled( 'mkm_api_cron_' . $key, array( array( 'key' => $key ) ) ) ) {
                    wp_clear_scheduled_hook( 'mkm_api_cron_' . $key, array( array( 'key' => $key ) ) );
                }
            }
        }
    }

    function mkm_api_activation() {
        $options = get_option( 'mkm_api_options' );
        if ( is_array( $options ) && count( $options ) > 0 ) {
            foreach( $options as $key => $value ) {
                if ( !wp_next_scheduled( 'mkm_api_cron_' . $key, array( array( 'key' => $key ) ) ) ) {
                    wp_schedule_event( time(), $value['cron'], 'mkm_api_cron_' . $key, array( array( 'key' => $key ) ) );
                }
            }
        }
    }

    function mkm_api_modal_to_footer() {

        ?>
            <div id="content-for-modal">
                <div class="mkm-api-progress-bar">
                    <span class="mkm-api-progress" style="width: 30%"></span>
                    <span class="proc">30%</span>
                </div>
            </div>
        <?php
    }

    function mkm_api_enqueue_admin() {
        wp_enqueue_script( 'mkm-api-admin', plugins_url( 'js/admin_scripts.js', __FILE__ ) );
        wp_enqueue_style( 'mkm-api-admin', plugins_url( 'css/admin_style.css', __FILE__ ) );
    }

    function mkm_api_create_table() {
        global $wpdb;

        $query = "CREATE TABLE IF NOT EXISTS `mkm_api_orders` (
            `id` INT(11) unsigned NOT NULL AUTO_INCREMENT,
            `id_order` INT(10) NOT NULL,
            `states` VARCHAR(50) NOT NULL,
            `date_bought` INT(11) NOT NULL,
            `date_paid` INT(11) NOT NULL,
            `date_sent` INT(11) NOT NULL,
            `date_received` INT(11) NOT NULL,
            `price` VARCHAR(50) NOT NULL,
            `is_insured` BOOLEAN NOT NULL,
            `city` VARCHAR(255) NOT NULL,
            `country` VARCHAR(255) NOT NULL,
            `article_count` INT(5) NOT NULL,
            `evaluation_grade` VARCHAR(255) NOT NULL,
            `item_description` VARCHAR(255) NOT NULL,
            `packaging` VARCHAR(255) NOT NULL,
            `article_value` VARCHAR(255) NOT NULL,
            `total_value` VARCHAR(255) NOT NULL,
            `appname` VARCHAR(50) NOT NULL,
            PRIMARY KEY (`id`)) ENGINE = InnoDBDEFAULT CHARSET=utf8;";

        $wpdb->query($query);
    }

    function mkm_api_delete_app_orders( $app ) {
        global $wpdb;
        $wpdb->delete( 'mkm_api_orders', array( 'appname' => $app ), array( '%s' ) );
    }

    function mkm_api_ajax_delete_key() {

        $post    = $_POST;

        $flag    = 0;
        $options = get_option( 'mkm_api_options' );

        if ( is_array ( $options ) && count( $options ) > 0 ) {
            $appname = $options[$post['data']]['name'];
            $arr     = array();
            foreach( $options as $item ) {
                if ( $item['app_token'] == $post['data'] ) continue;
                $arr[$item['app_token']] = $item;
            }
        }

        $up = update_option( 'mkm_api_options', $arr );

        if ( $up ) {
            mkm_api_delete_app_orders( $appname );
            wp_clear_scheduled_hook( 'mkm_api_cron_' . $post['data'], array( array( 'key' => $post['data'] ) ) );
            echo 1;
            wp_die();
        };

        die;
    }

    function mkm_api_ajax_get_data() {
        $post    = $_POST;
        $arr     = array();
        $key     = $post['key'];

        if( $key == '' ) wp_die( 'end' );

        $option = get_option( 'mkm_api_options' );

        if ( $post['count'] == 1 ) {
            $count = mkm_api_auth( "https://api.cardmarket.com/ws/v2.0/account", $option[$key]['app_token'], $option[$key]['app_secret'], $option[$key]['access_token'], $option[$key]['token_secret']);
            $arr['count'] = esc_sql( $count->account->sellCount );
        } else {
            $arr['count'] = $post['count'];
        }

        $data = mkm_api_auth( "https://api.cardmarket.com/ws/v2.0/orders/1/8/" . $post['data'], $option[$key]['app_token'], $option[$key]['app_secret'], $option[$key]['access_token'], $option[$key]['token_secret'] );
        if ( $data ) {
            mkm_api_add_data_from_db( $data, $key );
            $arr['data'] = $post['data'] + 100;
            $arr['key']  = $key;
            echo json_encode( $arr );
        } else {
            $option[$key]['get_data'] = 1;
            update_option( 'mkm_api_options', $option );
            wp_die( 'end' );
        }

        die;
    }

    function mkm_api_ajax_change_cron_select() {
        $post    = $_POST;
        $arr     = array();
        $key     = $post['key'];

        if( $key == '' ) wp_die( 'error' );

        $option    = get_option( 'mkm_api_options' );
        $schedules = wp_get_schedules();

        if ( !array_key_exists( $post['data'], $schedules ) ) wp_die( 'error' );

        $option[$key]['cron'] = $post['data'];

        if ( wp_next_scheduled( 'mkm_api_cron_' . $key, array( array( 'key' => $key ) ) ) ) {
            wp_clear_scheduled_hook( 'mkm_api_cron_' . $key, array( array( 'key' => $key ) ) );
        }

        wp_schedule_event( time(), $post['data'], 'mkm_api_cron_' . $key, array( array( 'key' => $key ) ) );
        update_option( 'mkm_api_options', $option );
    }

    function mkm_api_admin_menu() {
        add_menu_page( 'MKM API', 'MKM API', 'manage_options', 'mkm-api-options', 'mkm_api_options', 'dashicons-groups' );

        add_submenu_page( 'mkm-api-options', 'MKM API DATA', 'API Orders', 'manage_options', 'mkm-api-subpage', 'mkm_api_orders' );
    }

    function mkm_api_admin_settings() {

        register_setting( 'mkm_api_group_options', 'mkm_api_options', 'mkm_api_sanitize' );

    }

    function mkm_api_sanitize( $option ) {

        if ( isset( $_POST['data'] ) ) return $option;

        $add_array  = array();
        $schedules  = wp_get_schedules();
        $arr        = ( is_array( get_option( 'mkm_api_options' ) ) && count( get_option( 'mkm_api_options' ) ) > 0 ) ? get_option( 'mkm_api_options' ) : array();

        if ( $option['name'] == '' ) return $arr;
        if ( $option['app_token'] == '' ) return $arr;
        if ( $option['app_secret'] == '' ) return $arr;
        if ( $option['access_token'] == '' ) return $arr;
        if ( $option['token_secret'] == '' ) return $arr;
        if ( !array_key_exists( $option['cron'], $schedules ) ) return $arr;

        $add_array['token_secret'] = $option['token_secret'];
        $add_array['access_token'] = $option['access_token'];
        $add_array['app_secret']   = $option['app_secret'];
        $add_array['app_token']    = $option['app_token'];
        $add_array['name']         = $option['name'];
        $add_array['cron']         = $option['cron'];
        $add_array['get_data']     = 0;

        $arr[$option['app_token']] = $add_array;

        if ( !wp_next_scheduled( 'mkm_api_cron_' . $option['app_token'] ) ) {
            wp_schedule_event( time(), $option['cron'], 'mkm_api_cron_' . $option['app_token'], array( array( 'key' => $option['app_token'] ) ) );
        }

        return $arr;
    }

    function mkm_api_options( ) {
        $option    = get_option( 'mkm_api_options' );
        $schedules = wp_get_schedules();

        ?>

            <div class="wrap">
                <h2><?php _e( 'MKM API Settings', 'mkm-api' ); ?></h2>
                <form action="options.php" method="post">
                    <?php settings_fields( 'mkm_api_group_options' ); ?>
                    <table class="form-table">
                    <?php if ( is_array( $option ) && count( $option ) > 0 ) {  ?>
                        <tr>
                            <th></th>
                            <td class="mkm-api-app-td">
                                <table class="mkm-api-apps-show">
                                    <?php foreach( $option as $item ){ ?>
                                    <?php $interval = ''; ?>
                                        <tr class="mkm-api-key-row">
                                            <td><?php echo $item['name']; ?></td>
                                            <td>
                                                <select class="mkm-api-cron-select" data-key="<?php echo $item['app_token']; ?>">
                                                    <?php foreach( $schedules as $sch_key => $sch_val ) { ?>
                                                        <option <?php echo $sch_key == $item['cron'] ? 'selected ' : ''; ?>value="<?php echo $sch_key; ?>"><?php echo $sch_val['display']; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </td>
                                            <td class="mkm-api-get-all-data-td"><?php echo (bool)$item['get_data'] ? __( 'Data received', 'mkm-api' ) : submit_button( __( 'Get all data', 'mkm-api' ), 'primary mkm-api-get-all-data', 'submit', true, array( 'data-key' => $item['app_token'] ) ) ?></td>
                                            <td class="mkm-api-delete-key"><a href="" data-key="<?php echo $item['app_token']; ?>"><?php _e( 'Delete', 'mkm-api' ); ?></a></td>
                                        </tr>
                                    <?php } ?>
                                </table>
                            </td>
                        </tr>
                        <?php } ?>
                        <tr>
                            <th></th>
                            <td>
                                <p>
                                    <label class="mkm-api-app-form-label" for="mkm_api_setting_name_id"><?php _e( 'Name App', 'mkm-api' ); ?></label>
                                    <input type="text" value="" class="regular-text" name="mkm_api_options[name]" id="mkm_api_setting_name_id" required>
                                    <label for="mkm_api_setting_cron_id"><?php _e( 'Interval', 'mkm-api' ); ?></label>
                                    <select name="mkm_api_options[cron]" id="mkm_api_setting_cron_id">
                                    <?php foreach ( $schedules as $time_key => $time_val ) { ?>
                                        <option value="<?php echo $time_key; ?>"><?php echo $time_val['display']; ?></option>
                                    <?php } ?>
                                    </select>
                                </p>
                                <p>
                                    <label class="mkm-api-app-form-label" for="mkm_api_setting_app_token_id"><?php _e( 'App Token', 'mkm-api' ); ?></label>
                                    <input type="text" value="" class="regular-text" name="mkm_api_options[app_token]" id="mkm_api_setting_app_token_id" required>
                                </p>
                                <p>
                                    <label class="mkm-api-app-form-label" for="mkm_api_setting_app_secret_id"><?php _e( 'App Secret', 'mkm-api' ); ?></label>
                                    <input type="text" value="" class="regular-text" name="mkm_api_options[app_secret]" id="mkm_api_setting_app_secret_id" required>
                                </p>
                                <p>
                                    <label class="mkm-api-app-form-label" for="mkm_api_setting_access_token_id"><?php _e( 'Access Token', 'mkm-api' ); ?></label>
                                    <input type="text" value="" class="regular-text" name="mkm_api_options[access_token]" id="mkm_api_setting_access_token_id" required>
                                </p>
                                <p>
                                    <label class="mkm-api-app-form-label" for="mkm_api_setting_token_secret_id"><?php _e( 'Access Token Secret', 'mkm-api' ); ?></label>
                                    <input type="text" value="" class="regular-text" name="mkm_api_options[token_secret]" id="mkm_api_setting_token_secret_id" required>
                                </p>
                            </td>
                        </tr>
                    </table>

                <?php submit_button( __( 'Add App', 'mkm-api' ) ); ?>
                </form>
            </div>

        <?php
    }

    function mkm_api_add_data_from_db( $data, $key ) {
        global $wpdb;
        $option = get_option( 'mkm_api_options' );

        foreach ( $data->order as $value ) {
            $idOrder         = esc_sql( (int)$value->idOrder );
            $state           = esc_sql( $value->state->state );
            $dateBought      = strtotime( esc_sql( $value->state->dateBought ) );
            $datePaid        = strtotime( esc_sql( $value->state->datePaid ) );
            $dateSent        = strtotime( esc_sql( $value->state->dateSent ) );
            $dateReceived    = strtotime( esc_sql( $value->state->dateReceived ) );
            $price           = esc_sql( $value->shippingMethod->price );
            $isInsured       = (int)esc_sql( $value->shippingMethod->isInsured );
            $city            = esc_sql( $value->shippingAddress->city );
            $country         = esc_sql( $value->shippingAddress->country );
            $articleCount    = (int)esc_sql( $value->articleCount );
            $evaluationGrade = esc_sql( $value->evaluation->evaluationGrade );
            $itemDescription = esc_sql( $value->evaluation->itemDescription );
            $packaging       = esc_sql( $value->evaluation->packaging );
            $articleValue    = esc_sql( $value->articleValue );
            $totalValue      = esc_sql( $value->totalValue );
            $appName         = esc_sql( $option[$key]['name'] );


            if (!$wpdb->get_var( "SELECT id_order FROM mkm_api_orders WHERE id_order = $idOrder" ) ){
                $wpdb->query($wpdb->prepare("INSERT INTO mkm_api_orders (id_order, states, date_bought, date_paid, date_sent, date_received, price, is_insured, city, country, article_count, evaluation_grade, item_description, packaging, article_value, total_value, appname ) VALUES ( %d, %s, %d, %d, %d, %d, %f, %d, %s, %s, %d, %s, %s, %s, %f, %f, %s )", $idOrder, $state, $dateBought, $datePaid, $dateSent, $dateReceived, $price, $isInsured, $city, $country, $articleCount, $evaluationGrade, $itemDescription, $packaging, $articleValue, $totalValue, $appName ) );
            }
        }
    }

    function mkm_api_auth( $url, $appToken, $appSecret, $accessToken, $accessSecret ) {

        /**
        * Declare and assign all needed variables for the request and the header
        *
        * @var $method string Request method
        * @var $url string Full request URI
        * @var $appToken string App token found at the profile page
        * @var $appSecret string App secret found at the profile page
        * @var $accessToken string Access token found at the profile page (or retrieved from the /access request)
        * @var $accessSecret string Access token secret found at the profile page (or retrieved from the /access request)
        * @var $nonce string Custom made unique string, you can use uniqid() for this
        * @var $timestamp string Actual UNIX time stamp, you can use time() for this
        * @var $signatureMethod string Cryptographic hash function used for signing the base string with the signature, always HMAC-SHA1
        * @var version string OAuth version, currently 1.0
        */

        $method             = "GET";
        $nonce              = wp_create_nonce();
        $timestamp          = time();
        $signatureMethod    = "HMAC-SHA1";
        $version            = "1.0";

        /**
            * Gather all parameters that need to be included in the Authorization header and are know yet
            *
            * Attention: If you have query parameters, they MUST also be part of this array!
            *
            * @var $params array|string[] Associative array of all needed authorization header parameters
            */
        $params             = array(
            'realm'                     => $url,
            'oauth_consumer_key'        => $appToken,
            'oauth_token'               => $accessToken,
            'oauth_nonce'               => $nonce,
            'oauth_timestamp'           => $timestamp,
            'oauth_signature_method'    => $signatureMethod,
            'oauth_version'             => $version,
        );

        /**
            * Start composing the base string from the method and request URI
            *
            * Attention: If you have query parameters, don't include them in the URI
            *
            * @var $baseString string Finally the encoded base string for that request, that needs to be signed
            */
        $baseString         = strtoupper($method) . "&";
        $baseString        .= rawurlencode($url) . "&";

        /*
            * Gather, encode, and sort the base string parameters
            */
        $encodedParams      = array();
        foreach ($params as $key => $value)
        {
            if ("realm" != $key)
            {
                $encodedParams[rawurlencode($key)] = rawurlencode($value);
            }
        }
        ksort($encodedParams);

        /*
            * Expand the base string by the encoded parameter=value pairs
            */
        $values             = array();
        foreach ($encodedParams as $key => $value)
        {
            $values[] = $key . "=" . $value;
        }
        $paramsString       = rawurlencode(implode("&", $values));
        $baseString        .= $paramsString;

        /*
            * Create the signingKey
            */
        $signatureKey       = rawurlencode($appSecret) . "&" . rawurlencode($accessSecret);

        /**
            * Create the OAuth signature
            * Attention: Make sure to provide the binary data to the Base64 encoder
            *
            * @var $oAuthSignature string OAuth signature value
            */
        $rawSignature       = hash_hmac("sha1", $baseString, $signatureKey, true);
        $oAuthSignature     = base64_encode($rawSignature);

        /*
            * Include the OAuth signature parameter in the header parameters array
            */
        $params['oauth_signature'] = $oAuthSignature;

        /*
            * Construct the header string
            */
        $header             = "Authorization: OAuth ";
        $headerParams       = array();
        foreach ($params as $key => $value)
        {
            $headerParams[] = $key . "=\"" . $value . "\"";
        }
        $header            .= implode(", ", $headerParams);

        /*
            * Get the cURL handler from the library function
            */
        $curlHandle         = curl_init();

        /*
            * Set the required cURL options to successfully fire a request to MKM's API
            *
            * For more information about cURL options refer to PHP's cURL manual:
            * http://php.net/manual/en/function.curl-setopt.php
            */
        curl_setopt($curlHandle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curlHandle, CURLOPT_URL, $url);
        curl_setopt($curlHandle, CURLOPT_HTTPHEADER, array($header));
        curl_setopt($curlHandle, CURLOPT_SSL_VERIFYPEER, false);

        /**
            * Execute the request, retrieve information about the request and response, and close the connection
            *
            * @var $content string Response to the request
            * @var $info array Array with information about the last request on the $curlHandle
            */
        $content            = curl_exec($curlHandle);
        $info               = curl_getinfo($curlHandle);
        curl_close($curlHandle);

        /*
            * Convert the response string into an object
            *
            * If you have chosen XML as response format (which is standard) use simplexml_load_string
            * If you have chosen JSON as response format use json_decode
            *
            * @var $decoded \SimpleXMLElement|\stdClass Converted Object (XML|JSON)
            */

        // $decoded            = json_decode($content);

        $decoded            = simplexml_load_string($content);

        return $decoded;
    }

    function mkm_api_get_orders() {
        global $wpdb;
        $query = "SELECT * FROM mkm_api_orders";
        return $wpdb->get_results($query);
    }

    function mkm_api_orders() {

        $data = mkm_api_get_orders();

        ?>
            <div class="wrap">
                <h2><?php _e( 'MKM API Orders', 'mkm-api' ); ?></h2>
            </div>
            <table class="form-table mkm-api-orders-table">
                <tr class="mkm-api-list-orders">
                    <td><?php _e( 'ID Order', 'mkm-api' ); ?></td>
                    <td><?php _e( 'State', 'mkm-api' ); ?></td>
                    <td><?php _e( 'Date bought', 'mkm-api' ); ?></td>
                    <td><?php _e( 'Price', 'mkm-api' ); ?></td>
                    <td><?php _e( 'City/Country', 'mkm-api' ); ?></td>
                    <td><?php _e( 'Article count', 'mkm-api' ); ?></td>
                    <td><?php _e( 'Article value', 'mkm-api' ); ?></td>
                    <td><?php _e( 'Total value', 'mkm-api' ); ?></td>
                    <td><?php _e( 'App name', 'mkm-api' ); ?></td>
                </tr>
                <?php foreach ( $data as $value ) { ?>
                    <tr class="mkm-api-list-order-row">
                    <td><?php echo $value->id_order; ?></td>
                    <td><?php echo $value->states; ?></td>
                    <td><?php echo $value->date_bought; ?></td>
                    <td><?php echo number_format( $value->price, 2, '.', '' ); ?></td>
                    <td><?php echo $value->city . ' ' . $value->country;  ?></td>
                    <td><?php echo $value->article_count; ?></td>
                    <td><?php echo number_format( $value->article_count, 2, '.', '' ); ?></td>
                    <td><?php echo number_format( $value->total_value, 2, '.', '' ); ?></td>
                    <td><?php echo $value->appname; ?></td>
                </tr>
                <?php } ?>
            </table>
        <?php
    }

    function mkm_api_add_schedules( $schedules ) {
        $schedules['mkm-api-minute'] = array(
            'interval' => 60,
            'display'  => __( 'Every 1 minute', 'mkm-api' ),
        );

        $schedules['mkm-api-ten-minutes'] = array(
            'interval' => 600,
            'display'  => __( 'Every 10 minutes', 'mkm-api' ),
        );

        $schedules['mkm-api-four-hours'] = array(
            'interval' => 4* HOUR_IN_SECONDS,
            'display'  => __( 'Every 4 hours', 'mkm-api' ),
        );

        uasort( $schedules, function( $a, $b ){
            if ( $a['interval'] == $b['interval'] )return 0;
            return $a['interval'] < $b['interval'] ? -1 : 1;
        });
        return $schedules;
    }

    $options = get_option( 'mkm_api_options' );

    if ( is_array( $options ) && count( $options ) > 0 ) {
        
        foreach ( $options as $options_key => $options_val ) {
            add_action( 'mkm_api_cron_' . $options_key, 'mkm_cron_setup' );
        }
    }

    function mkm_cron_setup( $args ) {
        $options = get_option( 'mkm_api_options' );
        $key     = $args['key'];
        $data    = mkm_api_auth( "https://api.cardmarket.com/ws/v2.0/orders/1/8/1", $options[$key]['app_token'], $options[$key]['app_secret'], $options[$key]['access_token'], $options[$key]['token_secret'] );
        if ( $data ) {
            mkm_api_add_data_from_db( $data, $key );
        }
    }