<?php

/*
Plugin Name: Traceparent Crowdfunding Widget
Plugin URI: https://github.com/mammique/tp-wp-crowdfunding-widget
Description: A crowdfunding widget, displaying a funding gauge integrated with Traceparent's API and a PayPal a donation button.
Version: 0.1-alpha
Author: Camille Harang
Author URI: http://traceparent.com/
License: AGPL3
*/

wp_enqueue_script('jquery');

/**
 * Add function to widgets_init that'll load our widget.
 * @since 0.1
 */
add_action('widgets_init', 'tp_crowdfunding_load_widgets');

/**
 * Register our widget.
 * 'Traceparent_Crowdfunding_Widget' is the widget class used below.
 *
 * @since 0.1
 */
function tp_crowdfunding_load_widgets() {

	register_widget('Traceparent_Crowdfunding_Widget');
    wp_register_style('tp_crowdfunding_widget_css', WP_PLUGIN_URL . '/tp-wp-crowdfunding-widget/traceparent.css');
    wp_enqueue_style('tp_crowdfunding_widget_css');
    load_theme_textdomain('traceparent', __DIR__.'/', __DIR__.'/');
}

/**
 * Traceparent Crowdfunding Widget class.
 * This class handles everything that needs to be handled with the widget:
 * the settings, form, display, and update.  Nice!
 *
 * @since 0.1
 */
class Traceparent_Crowdfunding_Widget extends WP_Widget {

	/**
	 * Widget setup.
	 */
	function Traceparent_Crowdfunding_Widget() {
		/* Widget settings. */
		$widget_ops = array('classname' => 'tp-crowdfunding-widget', 'description' =>
                            __("A crowdfunding widget, displaying a funding gauge integrated with Traceparent's API and a PayPal a donation button.", 'traceparent'));

		/* Widget control settings. */
		$control_ops = array('width' => 300, 'height' => 350, 'id_base' => 'tp-crowdfunding-widget');

		/* Create the widget. */
		$this->WP_Widget('tp-crowdfunding-widget', __('Traceparent Crowdfunding', 'traceparent'), $widget_ops, $control_ops);
	}

	/**
	 * How to display the widget on the screen.
	 */
	function widget($args, $instance) {
    
		extract($args);

		/* Our variables from the widget settings. */
        $tp_url                         = $instance['url'];
        $tp_auth_token                  = $instance['auth_token'];
        $tp_auth_user                   = $instance['auth_user'];
        $tp_scope                       = $instance['scope'];
        $tp_unit                        = $instance['unit'];
        $tp_quantity_decimals           = $instance['quantity_decimals'];
        $tp_quantity_separator          = $instance['quantity_separator'];
        $tp_quantity_decimals_separator = $instance['quantity_decimals_separator'];
        $tp_counter                     = $instance['counter'];
        $tp_jurisdiction                = $instance['jurisdiction'];

        $bootstrap = false;
        if($instance['bootstrap'] == 'on') $bootstrap = true;

        $pp_url        = $instance['pp_url'];
        $pp_auth_token = $instance['pp_auth_token'];
        $pp_email      = $instance['pp_email'];
        $pp_item_name  = $instance['pp_item_name'];
        $pp_button     = $instance['pp_button'];

        $email_content_append = $instance['email_content_append'];

		/* Before widget (defined by themes). */
		echo $before_widget;

?>

<div class="tp_crowdfunding_widget" id="<?php echo $tp_scope ?>">

<?php

    if(rawurldecode($_GET['cm']) == "scope=$tp_scope") {

        require_once 'vendor/autoload.php';

        $pp_result = array();
        $req = 'cmd=_notify-synch';
        $tx_token = $_GET['tx'];
        $req .= "&tx=$tx_token&at=$pp_auth_token";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "$pp_url/cgi-bin/webscr");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
        curl_setopt($ch, CURLOPT_CAINFO, __DIR__.'/cacert.pem');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        //curl_setopt($ch, CURLOPT_HTTPHEADER, array("Host: $pp_url"));
        //curl_setopt($ch, CURLOPT_HTTPHEADER, array("Host: www.sandbox.paypal.com"));
        $res = curl_exec($ch);

        $error_el = '<p class="tp_feedback tp_error">'.
                    __("Something went wrong, our staff is working on it, sorry for the inconvenience.", 'traceparent').
                    '</p>';

        if(!$res) {

            echo $error_el;

            wp_mail(get_option('admin_email'),
                    '['.get_bloginfo('name').'] Error report',
                    'curl_error: '.curl_error($ch)."\n\nreq: ".$req."\n\nres: ".$res);

            curl_close($ch);

        } else {

            curl_close($ch);

            // parse the data
            $lines = explode("\n", $res);

            if (strcmp($lines[0], "SUCCESS") == 0) {

                for ($i = 1 ; $i < count($lines) ; $i++){

                    if(strlen($lines[$i])) {

                        list($key, $val) = explode("=", $lines[$i]);
                        $pp_result[urldecode($key)] = urldecode($val);
                    }
                }

                global $tp_client;
                $tp_client = new Guzzle\Http\Client($tp_url,
                                 array('curl.options' => array(
                                    CURLOPT_HTTPHEADER => array(
                                        "Authorization: Token $tp_auth_token"))));

                $pp_id = $pp_result['txn_id'];

                $request = $tp_client->get("value/unit/$tp_unit/");
                $unit = $request->send()->json();

                $request = $tp_client->get('metadata/snippet/filter/');
                $request->getQuery()->set('slug_0', "paypal_pdt_$pp_id");
                $request->getQuery()->set('slug_1', 'exact');
                $request->getQuery()->set('page_size', '0');
                $metadata_prev = $request->send()->json();

                if($pp_result['receiver_email'] != $pp_email) {

                    echo '<p class="tp_feedback tp_error">'.
                         __("receiver email address mismatch.", 'traceparent').
                         '</p>';

                } else if($pp_result['mc_currency'] != $unit['slug']) {

                    echo '<p class="tp_feedback tp_error">'.
                         __("currency mismatch.", 'traceparent').
                         '</p>';

                } else if(count($metadata_prev)) {

                    echo '<p class="tp_feedback tp_error">'.
                         __("Transaction already registered.", 'traceparent').
                         '</p>';
//                    echo '<script type="text/javascript">document.location="./";</script>';

                } else { // If not already registered.
                               
                    function get_or_create_user($email, $name='', $details=false) {

                        global $tp_client;

                        $request = $tp_client->get('auth/user/filter/');
                        $request->getQuery()->set('email', $email);
                        $request->getQuery()->set('page_size', 1);

                        $response = $request->send();
                        $data = $response->json();

                        if(count($data['results'])) {

                            if(!$details) return $data['results'][0];
                            else return $tp_client->get('auth/user/'.$data['results'][0]['uuid'].'/')->send()->json();
                        }

                        $request = $tp_client->post('auth/user/create/')
                                       ->addPostFields(array('email'    => $email,
                                                             'name'     => $name,
                                                             'password' => ''));
                        return $request->send()->json();
                    }

                    $user = get_or_create_user($pp_result['payer_email'], $pp_result['first_name'].' '.$pp_result['last_name']);

                    $request = $tp_client->post('value/quantity/create/')
                                   ->addPostFields(array('unit'            => $tp_unit,
                                                         'quantity'        => $pp_result['mc_gross'],
                                                         'user'            => $user['uuid'],
                                                         'user_visibility' => 'public',
                                                         'status'          => 'present'));
                    $quantity = $request->send()->json();

                    $request = $tp_client->post('metadata/snippet/create/')
                                   ->addPostFields(array('user'                => $tp_auth_user,
                                                         'visibility'          => 'private',
                                                         'mimetype'            => 'application/json',
                                                         'slug'                => "paypal_pdt_$pp_id",
                                                         'type'                => 'paypal_pdt',
                                                         'assigned_quantities' => $quantity['uuid'],
                                                         'content'             => json_encode($pp_result)));
                    $metadata = $request->send()->json();

                    $request = $tp_client->post("monitor/scope/$tp_scope/update/quantities/add/")
                                   ->addPostFields(array('uuid' => $quantity['uuid']))->send();

                    
                    if($email_content_append != '') $mail_append = "\n\n".$email_content_append;
                    else $mail_append = '';

                    wp_mail($pp_result['payer_email'],

                            '['.get_bloginfo('name').'] '.__('Thank you for your participation!', 'traceparent'),

                            __("Hello", 'traceparent').' '.$user['name'].", ".
                            __("thank you for your participation to our project!", 'traceparent').
                            "\n\n".
                            __("You can check information about your payment on your PayPal account at https://paypal.com/", 'traceparent').
                            "\n\n".
                            __("Tracability and transparency about the use of your money is monitored ".
                               "by the free and Open Source software Traceparent (http://traceparent.com/) ".
                               "that is running the funding gauge on our website.", 'traceparent').
                            "\n\n".
                            sprintf(
                                __("Your profile information is managed by Gravatar, ".
                                   'if you want to set your avatar up, please set it using this very same email (%1$s) '.
                                   "at http://gravatar.com/", 'traceparent'),
                                $pp_result['payer_email']).
                            $mail_append.
                            "\n\n".
                            __("Best regards,", 'traceparent').
                            "\n\n".
                            get_bloginfo('name').'.');

                    echo '<p class="tp_feedback">'.__("Thank you!", 'traceparent').
                         '<small>'.
                         __("Your participation has been succesfuly registered", 'traceparent').
                         ', '.
                         __("please check your email for more details.", 'traceparent').
                         '</small></p>';

                    $request = $tp_client->get("metadata/snippet/filter/");
                    $request->getQuery()->set('assigned_counters', $tp_counter);
                    $request->getQuery()->set('user', $tp_auth_user);
                    $request->getQuery()->set('type_0', 'goody');
                    $request->getQuery()->set('type_1', 'exact');
                    $request->getQuery()->set('mimetype', 'application/json');
                    $request->getQuery()->set('content_nested', '');
                    $request->getQuery()->set('page_size', 0);

                    $goodies = $request->send()->json();
                    $goodict = array();

                    for ($i = 0 ; $i < count($goodies) ; $i++) {

                        $goody = $goodies[$i];
                        $goody['content'] = json_decode($goody['content'], true);
                        $goodict[$goody['content']['q_range'][$unit['uuid']][0]] = $goody;
                    }

                    krsort($goodict);

                    foreach($goodict as $q => $goody) {

                        if(floatval($quantity['quantity']) >= floatval($q)) {

                            $goody_uuid = $goody['uuid'];
                            $tp_client->post("metadata/snippet/$goody_uuid/update/assigned_quantities/add/")
                                ->addPostFields(array('uuid' => $quantity['uuid']))->send();

                            break;
                        }
                    }
                }

            } else { // if (strcmp ($lines[0], "FAIL") == 0) {

                echo $error_el;

                wp_mail(get_option('admin_email'),
                        '['.get_bloginfo('name').'] Error report',
                        "req: ".$req."\n\nres: ".$res);
            }
        }
    }
?>

    <div class="tp_info">
        <span class="tp_current"></span>
        <span class="tp_max"></span>
        <span class="tp_quantities_number"></span>
        <span class="tp_remaining_days"></span>
        <span class="tp_deadline"></span>
    </div>

    <div class="<?php if($bootstrap) echo "progress progress-striped active "; ?>tp_gauge"><div class="<?php if($bootstrap) echo "bar "; ?>tp_mercury"></div></div>

    <form class="tp_post" action="<?php echo $pp_url ?>/cgi-bin/webscr" method="post" style="display: none;">
        <input type="hidden" name="business" value="<?php echo $pp_email ?>" />
        <input type="hidden" name="cmd" value="_donations" />
        <input type="hidden" name="item_name" value="<?php echo $pp_item_name ?>" />
        <input type="hidden" name="currency_code" value="" />
        <input type="hidden" name="custom" value="scope=<?php echo $tp_scope ?>" />
        <div class="tp_goodies"></div>
        <input type="<?php if($bootstrap) echo "btn "; ?>submit" value="<?php echo $pp_button ?>" />
        <div class="tp_quantities"></div>
    </form>

</div>

<script type="text/javascript">

var max, current;

var tp_url                         = '<?php echo $tp_url ?>';
var tp_auth_token                  = '<?php echo $tp_auth_token ?>';
var tp_auth_user                   = '<?php echo $tp_auth_user ?>';
var tp_scope                       = '<?php echo $tp_scope ?>';
var tp_unit                        = '<?php echo $tp_unit ?>';
var tp_quantity_decimals           = parseInt('<?php echo $tp_quantity_decimals ?>');
var tp_quantity_separator          = '<?php echo $tp_quantity_separator ?>';
var tp_quantity_decimals_separator = '<?php echo $tp_quantity_decimals_separator ?>';
var tp_counter                     = '<?php echo $tp_counter ?>';
var tp_jurisdiction                = '<?php echo $tp_jurisdiction ?>';

if (tp_users == undefined) var tp_users = {};

var $ = jQuery;

function tp_unit_format(u, q, dec_pl) {

    var n;
    var dec = null;
    if(dec_pl == undefined) dec_pl = tp_quantity_decimals;

    if(dec_pl == 0) n = '' + parseInt(q)

    else {

        var places;
        if (dec_pl < 0) places = u['decimal_places'];
        else places = dec_pl

        var f = parseFloat(q).toFixed(places).split('.');
        n     = f[0];
        dec   = f[1];
    }

    n_sep = '';

    if (tp_quantity_separator != '') {

        var inc = 0;

        for(i=n.length-1 ; i>=0 ; i--) {

            if(i !=0 && inc % 3 == 0 && i != n.length-1) n_sep = tp_quantity_separator + n_sep;
            n_sep = n[i] + n_sep;
            inc++;
        }

        n = n_sep;
    }

    var r;
    if(dec != null) r = n+tp_quantity_decimals_separator+dec;
    else r = n;

    var s;
    if (tp_quantity_separator != '') s = ' '+u['symbol'];
    else s = u['symbol']

    return r+s;
}

// For Safari: http://stackoverflow.com/a/9282695
var re_iso_8601 = /^(\d{4})-(\d{2})-(\d{2})((T)(\d{2}):(\d{2})(:(\d{2})(\.\d*)?)?)?(Z)?$/;

function iso_8601(val) {

    var m;
    m = typeof val === 'string' && val.match(re_iso_8601);
    if (m) return new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[6] || 0, +m[7] || 0, +m[9] || 0, parseInt((+m[10]) * 1000) || 0));

    return null;
}

$.getJSON(tp_url + "/monitor/counter/" + tp_counter + "/",

          function(counter) {

              if(counter['datetime_stop']) {

                  // deadline = new Date(counter['datetime_stop']); // Safari bugs on this.
                  var deadline = iso_8601(counter['datetime_stop']);
                  today        = new Date();
                  delta        = new Date(deadline - today);

                  $('#' + tp_scope + ' .tp_remaining_days').text(parseInt(delta.getTime()/(1000*60*60*24)));
                  $('#' + tp_scope + ' .tp_deadline').text(deadline.toLocaleDateString());
              }
          }
);

$.getJSON(tp_url + "/value/unit/" + tp_unit + "/",

          function(unit) {

              tp_unit = unit;

              $('#' + tp_scope + ' .tp_post input[name="currency_code"]').val(tp_unit['slug']);
              $('#' + tp_scope + ' .tp_post').show();

              $.getJSON(tp_url + "/monitor/mark/filter/",
                        {'counters': tp_counter, 'user': tp_auth_user,
                         'unit': tp_unit['uuid'], 'page_size': 0},

                        function(marks) {

                            max = marks[0]['quantity'];

                            $.getJSON(tp_url + "/monitor/result/sum/filter/",
                                      {'counter': tp_counter, 'unit': tp_unit['uuid'],
                                       'status': 'present', 'page_size': 0},

                                      function(sums) {

                                          current = sums[0]['quantity'];

                                          $('#' + tp_scope + ' .tp_mercury').width((current / max * 100) + '%');
                                          $('#' + tp_scope + ' .tp_current').text(tp_unit_format(tp_unit, current));
                                          $('#' + tp_scope + ' .tp_max').text(tp_unit_format(tp_unit, max));
                                      }
                            );
                        }
              );

              $.getJSON(tp_url + "/metadata/snippet/filter/",
                        {'assigned_counters': tp_counter,
                         'user': tp_auth_user, 'type_0': 'goody', 'type_1': 'exact',
                         'mimetype': 'application/json', 'content_nested': '',
                         'page_size': 0},

                        function(goodies) {

                            var goodies_q = [];
                            var goodict   = {};

                            $(goodies).each(function(k, v) {

                                var content = $.parseJSON(v['content']);
                                var q = parseFloat(content['q_range'][tp_unit['uuid']][0]);
                                goodies_q.push(q);
                                goodict[q] = content;
                            });

                            goodies_q.sort(function(a, b) { return a - b; } );

                            $(goodies_q).each(function(k, v) {

                                var goody = goodict[v];
                                var q_min = goody['q_range'][tp_unit['uuid']][0];
                                var el    = $('<div class="tp_goody"><strong class="tp_quantity_min">' +
                                              '</strong><span class="<?php if($bootstrap) echo "label "; ?>tp_label"></span>' +
                                              '<span class="tp_tax_free"></span><p class="tp_desc"></p>' +
                                              '</div>');
                                $('.tp_quantity_min', el).text(tp_unit_format(tp_unit, q_min));
                                $('.tp_label', el).text(goody['label']);
                                $('.tp_desc', el).text(goody['desc']);

                                if(goody['tax_free'] != undefined &&
                                   goody['tax_free'][tp_jurisdiction] != undefined &&
                                   goody['tax_free'][tp_jurisdiction]['individual'] != undefined)
                                    $('.tp_tax_free', el).text(tp_unit_format(tp_unit, q_min * (100 - goody['tax_free'][tp_jurisdiction]['individual']) / 100, -1));

                                el.click(function() {

                                            var form = $('#' + tp_scope + ' form')
                                            form.append('<input type="hidden" name="amount" value="' + q_min + '" />')
                                            form.submit();
                                         }
                                );
                                
                                $('#' + tp_scope + ' .tp_goodies').append(el);
                            } );
                        }
              );
          }
);

$.getJSON(tp_url + "/value/quantity/filter/",
          {'counters': tp_counter, 'status': 'present', 'page_size': 0},

          function(quantities) {

              $('#' + tp_scope + ' .tp_quantities_number').text(''+quantities.length);

              $(quantities).each(function(k, q) {

                  var img = $('<img src="http://www.gravatar.com/avatar/none?s=32" title="' + tp_unit_format(tp_unit, q['quantity']) + '" />');

                  if (q['user'] != undefined) {

                        function img_set_data(u) {

                            img.attr('src', "http://www.gravatar.com/avatar/" + u['email_md5'] + "?s=32");
                            img.attr('title', u['name'] + ' ' + img.attr('title'));
                        }

                        if (tp_users[q['user']] == undefined) {

                            tp_users[q['user']] = {'data': null, 'callbacks': [img_set_data]}

                            $.getJSON(q['user'],

                                      function(user) {

                                          tp_users[q['user']]['data'] = user;
                                          $(tp_users[q['user']]['callbacks']).each( function(k, f) { f(user); } );
                                      }
                            );

                        } else {

                            if(!tp_users[q['user']]['data']) tp_users[q['user']]['callbacks'].push(img_set_data);
                            else img_set_data(tp_users[q['user']]['data']);
                        }
                  }

                  $('#' + tp_scope + ' .tp_quantities').append(img);
              });
          }
);

</script>

<?php

		/* After widget (defined by themes). */
		echo $after_widget;
	}

	/**
	 * Update the widget settings.
	 */
	function update($new_instance, $old_instance) {

		return $new_instance;
	}

	/**
	 * Displays the widget settings controls on the widget panel.
	 * Make use of the get_field_id() and get_field_name() function
	 * when creating your form elements. This handles the confusing stuff.
	 */
	function form($instance) {

		/* Set up some default widget settings. */
		$defaults = array('url' => 'http://sandbox.api.traceparent.com/0.1-beta', 'auth_token' => '', 'auth_user' => '',
                          'scope' => '', 'unit' => '122cc224-7572-11e2-adfe-78929c525f0e',
                          'quantity_decimals' => -1, 'quantity_separator' => ',', 'quantity_decimals_separator' => '.',
                          'counter' => '', 'jurisdiction' => 'FR', 'bootstrap' => '', 'pp_url' => 'https://www.sandbox.paypal.com', 'pp_auth_token' => '',
                          'pp_email' => '', 'email_content_append' => '', 'pp_item_name' => 'Donation', 'pp_button' => __('Donate!', 'traceparent'));
		$instance = wp_parse_args((array) $instance, $defaults); ?>

		<p>
			<label for="<?php echo $this->get_field_id('url'); ?>"><?php _e('url:', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('url'); ?>" name="<?php echo $this->get_field_name('url'); ?>" value="<?php echo $instance['url']; ?>" style="width: 100%;" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('auth_token'); ?>"><?php _e('auth_token:', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('auth_token'); ?>" name="<?php echo $this->get_field_name('auth_token'); ?>" value="<?php echo $instance['auth_token']; ?>" style="width: 100%;" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('auth_user'); ?>"><?php _e('auth_user:', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('auth_user'); ?>" name="<?php echo $this->get_field_name('auth_user'); ?>" value="<?php echo $instance['auth_user']; ?>" style="width: 100%;" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('scope'); ?>"><?php _e('scope:', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('scope'); ?>" name="<?php echo $this->get_field_name('scope'); ?>" value="<?php echo $instance['scope']; ?>" style="width: 100%;" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('unit'); ?>"><?php _e('unit:', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('unit'); ?>" name="<?php echo $this->get_field_name('unit'); ?>" value="<?php echo $instance['unit']; ?>" style="width: 100%;" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('quantity_decimals'); ?>"><?php _e('quantity_decimals:', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('quantity_decimals'); ?>" name="<?php echo $this->get_field_name('quantity_decimals'); ?>" value="<?php echo $instance['quantity_decimals']; ?>" style="width: 100%;" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('quantity_separator'); ?>"><?php _e('quantity_separator:', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('quantity_separator'); ?>" name="<?php echo $this->get_field_name('quantity_separator'); ?>" value="<?php echo $instance['quantity_separator']; ?>" style="width: 100%;" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('quantity_decimals_separator'); ?>"><?php _e('quantity_decimals_separator:', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('quantity_decimals_separator'); ?>" name="<?php echo $this->get_field_name('quantity_decimals_separator'); ?>" value="<?php echo $instance['quantity_decimals_separator']; ?>" style="width: 100%;" />
		</p>

<!--		<p>
			<label for="<?php echo $this->get_field_id('unit_pp_code'); ?>"><?php _e('unit_pp_code:', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('unit_pp_code'); ?>" name="<?php echo $this->get_field_name('unit_pp_code'); ?>" value="<?php echo $instance['unit_pp_code']; ?>" style="width: 100%;" />
		</p> -->

		<p>
			<label for="<?php echo $this->get_field_id('counter'); ?>"><?php _e('counter:', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('counter'); ?>" name="<?php echo $this->get_field_name('counter'); ?>" value="<?php echo $instance['counter']; ?>" style="width: 100%;" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('jurisdiction'); ?>"><?php _e('jurisdiction (ISO 3166-2):', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('jurisdiction'); ?>" name="<?php echo $this->get_field_name('jurisdiction'); ?>" value="<?php echo $instance['jurisdiction']; ?>" style="width: 100%;" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('bootstrap'); ?>"><?php _e('bootstrap:', 'traceparent'); ?></label>
			<input type="checkbox" id="<?php echo $this->get_field_id('bootstrap'); ?>" <?php if($instance['bootstrap'] == 'on') echo 'checked="checked" '; ?>name="<?php echo $this->get_field_name('bootstrap'); ?>" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('pp_url'); ?>"><?php _e('pp_url:', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('pp_url'); ?>" name="<?php echo $this->get_field_name('pp_url'); ?>" value="<?php echo $instance['pp_url']; ?>" style="width: 100%;" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('pp_auth_token'); ?>"><?php _e('pp_auth_token:', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('pp_auth_token'); ?>" name="<?php echo $this->get_field_name('pp_auth_token'); ?>" value="<?php echo $instance['pp_auth_token']; ?>" style="width: 100%;" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('pp_email'); ?>"><?php _e('pp_email:', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('pp_email'); ?>" name="<?php echo $this->get_field_name('pp_email'); ?>" value="<?php echo $instance['pp_email']; ?>" style="width: 100%;" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('email_content_append'); ?>"><?php _e('email_content_append:', 'traceparent'); ?></label>
			<textarea id="<?php echo $this->get_field_id('email_content_append'); ?>" name="<?php echo $this->get_field_name('email_content_append'); ?>"  style="width: 100%;"><?php echo $instance['email_content_append']; ?></textarea>
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('pp_item_name'); ?>"><?php _e('pp_item_name:', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('pp_item_name'); ?>" name="<?php echo $this->get_field_name('pp_item_name'); ?>" value="<?php echo $instance['pp_item_name']; ?>" style="width: 100%;" />
		</p>

		<p>
			<label for="<?php echo $this->get_field_id('pp_button'); ?>"><?php _e('pp_button:', 'traceparent'); ?></label>
			<input id="<?php echo $this->get_field_id('pp_button'); ?>" name="<?php echo $this->get_field_name('pp_button'); ?>" value="<?php echo $instance['pp_button']; ?>" style="width: 100%;" />
		</p>

	<?php
	}
}

?>
