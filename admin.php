<?php
function plugin_admin_add_page() {
    add_options_page('ICEPAY configuration', 'ICEPAY', 'manage_options', 'icepay_config', 'icepay_options_page');
}

function icepay_options_page() {
    ?>

    <div>
        <p><img src="<?php echo WPSC_ICEPAY_URL ?>/images/icepay-logo.png" border="0"/></p>
        <h2><?php _e('ICEPAY configuration', 'icepay'); ?></h2>
        <?php _e('ICEPAY WP commerce module using PHP API version', 'icepay'); ?> <?php echo Icepay_Project_Helper::getInstance()->getReleaseVersion(); ?>.

        <form action="options.php" method="post">
            <?php settings_fields('icepay_options'); ?>
            <?php do_settings_sections('icepay_config'); ?>
            <input name="Submit" type="submit" value="<?php esc_attr_e(__('Save Changes')); ?>" />
        </form>
    </div>

    <?php
}

function eg_settings_api_init() {
    register_setting('icepay', 'eg_setting_name');
    register_setting('icepay_options', 'icepay_options', 'icepay_options_validate');

    // Add Main Settings section
    add_settings_section('icepay_main', __('Main Settings', 'icepay'), 'icepay_main_section_text', 'icepay_config');

    // Add Main Settings fields
    add_settings_field('icepay_url', __('URL for postback, success and error', 'icepay'), 'setting_url', 'icepay_config', 'icepay_main', 
        array("icepay_url", __('Copy-paste this into your ICEPAY Merchant Thank you page, Error page and Postback URL', 'icepay')));
    add_settings_field('icepay_merchantid', __('Merchant ID', 'icepay'), 'setting_textfield', 'icepay_config', 'icepay_main', array("icepay_merchantid"));
    add_settings_field('icepay_secretcode', __('Secretcode (API key)', 'icepay'), 'setting_textfield', 'icepay_config', 'icepay_main', array("icepay_secretcode"));

    // Add Additional Settings
    add_settings_section('icepay_extra', __('Additional Settings', 'icepay'), 'icepay_extra_section_text', 'icepay_config');

    // Add Additional Settings fields
    add_settings_field('icepay_description', __('Description on transaction statement of customer', 'icepay'), 'setting_textfield', 'icepay_config', 'icepay_extra', 
        array("icepay_description", __('Some payment methods allow customized descriptions on the transaction statement. If left empty the WP Order ID is used. (Max 100 char.)', 'icepay')));
    add_settings_field('icepay_ipcheck', __('(Optional) Custom IP Range for IP Check for Postback', 'icepay'), 'setting_textfield', 'icepay_config', 'icepay_extra', 
        array("icepay_ipcheck", __('For example a proxy: 1.222.333.444-100.222.333.444 For multiple ranges use a , seperator: 1.222.333.444-100.222.333.444,2.222.333.444-200.222.333.444', 'icepay')));
}

function icepay_options_validate($input) {
    $options = get_option('icepay_options');

    if (!Icepay_Parameter_Validation::merchantID(intval($input['icepay_merchantid']))) {
        add_settings_error('ICEPAY', 'ICEPAY_error', __('Icepay Merchant ID:', 'icepay') . " '" . $input['icepay_merchantid'] . "' " . __('is invalid', 'icepay'));
        $input['icepay_merchantid'] = $options['icepay_merchantid'];
    }

    if (!Icepay_Parameter_Validation::secretCode($input['icepay_secretcode'])) {
        add_settings_error('ICEPAY', 'ICEPAY_error', __('Icepay Secret Code:', 'icepay') . " '" . $input['icepay_secretcode'] . "' " .__('is invalid', 'icepay'));
        $input['icepay_secretcode'] = $options['icepay_secretcode'];
    }

    return $input;
}

// Function must exists for callback
function icepay_main_section_text() {
    
}

// Function must exists for callback
function icepay_extra_section_text() {
    
}

function setting_url($fields) {
    echo "<strong>" . get_option('siteurl') . "/index.php?page=icepayresult</strong>";
    if (isset($fields[1]))
        echo "<p class='description'>" . $fields[1] . "</p>";
}

function setting_secretcode() {
    $options = get_option('icepay_options');
    echo "<input id='icepay_secretcode' name='icepay_options[icepay_secretcode]' size='40' type='text' value='{$options['icepay_secretcode']}' />";
}

function setting_textfield($fields) {
    $options = get_option('icepay_options');
    $field = $fields[0];
    echo "<input id='" . $field . "' name='icepay_options[" . $field . "]' size='40' type='text' value='{$options[$field]}' />";
    if (isset($fields[1]))
        echo "<p class='description'>" . $fields[1] . "</p>";
}

function icepay_warning() {
    $options = get_option('icepay_options');
    if ($options['icepay_merchantid'] == "" || $options['icepay_secretcode'] == "") {
        echo "<div id='icepay-warning' class='updated fade'><p><strong>" . __('ICEPAY is almost ready.', 'icepay') . "</strong> " . sprintf(__('You must <a href="%1$s">enter your ICEPAY merchant ID and Secretcode</a> for it to work.', 'icepay'), "options-general.php?page=icepay_config") . "</p></div>";
    }
}

add_action('admin_notices', 'icepay_warning');
add_action('admin_menu', 'plugin_admin_add_page');
add_action('admin_init', 'eg_settings_api_init');?>