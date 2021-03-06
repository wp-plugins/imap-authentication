<?php
/*
Plugin Name: IMAP Authentication
Version: 0.6
Plugin URI: http://norman.rasmussen.co.za/
Description: Authenticate users using IMAP authentication. WARNING: Make sure the secret key is non-blank, otherwise anyone will be able to login as a valid user by altering their cookies manually.
Author: Norman Rasmussen
Author URI: http://norman.rasmussen.co.za/

If you have existing users when you install this plugin, or if you ever change the secret key after adding users to your database, you will have to run the following sql:
  select @secret_key := option_value from wp_options where option_name = 'imap_authentication_secret_key';
  update wp_users set user_pass = md5(concat(@secret_key, user_login)) where user_login != 'admin';
*/

add_action('admin_menu', array('IMAPAuthentication', 'admin_menu'));
add_action('wp_authenticate', array('IMAPAuthentication', 'login'));
add_action('lost_password', array('IMAPAuthentication', 'disable_function'));
add_action('retrieve_password', array('IMAPAuthentication', 'disable_function'));
add_action('password_reset', array('IMAPAuthentication', 'disable_function'));
add_action('check_passwords', array('IMAPAuthentication', 'check_passwords'));
add_filter('show_password_fields', array('IMAPAuthentication', 'show_password_fields'));


if (is_plugin_page()) {
    $mailbox = IMAPAuthentication::get_mailbox();
    $user_suffix = IMAPAuthentication::get_user_suffix();
    $secret_key = IMAPAuthentication::get_secret_key();
?>
<div class="wrap">
  <h2>IMAP Authentication Options</h2>
  <form name="imapauthenticationoptions" method="post" action="options.php">
    <input type="hidden" name="action" value="update" />
    <input type="hidden" name="page_options" value="'imap_authentication_mailbox','imap_authentication_secret_key','imap_authentication_user_suffix'" />
    <fieldset class="options">
      <table width="100%" cellspacing="2" cellpadding="5" class="editform"> 
        <tr valign="top"> 
        <th width="33%" scope="row"><label for="imap_authentication_mailbox">Mailbox</label></th> 
        <td><input name="imap_authentication_mailbox" type="text" id="imap_authentication_mailbox" value="<?php echo htmlspecialchars($mailbox) ?>" size="80" /><br />eg: {mail.example.com/readonly}INBOX or {mail.example.com:993/ssl/novalidate-cert/readonly}INBOX</td> 
        </tr>
        <tr valign="top">
        <th scope="row"><label for="imap_authentication_secret_key">Secret Key</label></th>
        <td><input name="imap_authentication_secret_key" type="password" id="imap_authentication_secret_key" value="<?php echo htmlspecialchars($secret_key) ?>" size="50" /><br />WARNING: Make sure this secret key is non-blank, otherwise anyone will be able to login as a registered user!</td>
        </tr>
        <tr valign="top">
        <th scope="row"><label for="imap_authentication_user_suffix">User Suffix</label></th>
        <td><input name="imap_authentication_user_suffix" type="text" id="imap_authentication_user_suffix" value="<?php echo htmlspecialchars($user_suffix) ?>" size="50" /><br />A suffix to add to usernames (typically used to automatically add the domain part of the login).<br />eg: @example.com</td>
        </tr>
      </table>      
    </fieldset>
    <p class="submit">
      <input type="submit" name="Submit" value="Update Options &raquo;" />
    </p>
  </form>
</div>
<?php
}

if (! class_exists('IMAPAuthentication')) {
    class IMAPAuthentication {
        /*
         * Add an options pane for this plugin.
         */
        function admin_menu() {
            add_options_page('IMAP Authentication', 'IMAP Authentication', 10, __FILE__);
        }

        /*
         * Return the mailbox option from the database, creating the option if it doesn't exist.
         */
        function get_mailbox() {
            global $cache_nonexistantoptions;

            $mailbox = get_settings('imap_authentication_mailbox');
            if (! $mailbox or $cache_nonexistantoptions['imap_authentication_mailbox']) {
                $mailbox = '{localhost:143}INBOX';
                IMAPAuthentication::add_mailbox_option($mailbox);
            }

            return $mailbox;
        }

        /*
         * Add the mailbox option to the database.
         */
        function add_mailbox_option($mailbox) {
            add_option('imap_authentication_mailbox', $mailbox, 'The mailbox to try and log into.');
        }

        /*
         * Return the secret_key option from the database, creating the option if it doesn't exist.
         */
        function get_secret_key() {
            global $cache_nonexistantoptions;

            $secret_key = get_settings('imap_authentication_secret_key');
            if (! $secret_key or $cache_nonexistantoptions['imap_authentication_secret_key']) {
                $secret_key = '';
                IMAPAuthentication::add_secret_key_option($secret_key);
            }

            return $secret_key;
        }

        /*
         * Add the secret_key option to the database.
         */
        function add_secret_key_option($secret_key) {
            add_option('imap_authentication_secret_key', $secret_key, 'A prefix to add to usernames to create the secret password.');
        }

        /*
         * Return the user_suffix option from the database, creating the option if it doesn't exist.
         */
        function get_user_suffix() {
            global $cache_nonexistantoptions;

            $user_suffix = get_settings('imap_authentication_user_suffix');
            if (! $user_suffix or $cache_nonexistantoptions['imap_authentication_user_suffix']) {
                $user_suffix = '';
                IMAPAuthentication::add_user_suffix_option($user_suffix);
            }

            return $user_suffix;
        }

        /*
         * Add the user_suffix option to the database.
         */
        function add_user_suffix_option($user_suffix) {
            add_option('imap_authentication_user_suffix', $user_suffix, 'A suffix to add to usernames (typically used to automatically add the domain part of the login).');
        }

        // custom error handler
        function eh($type, $msg, $file, $line, $context)
        {
            $error = $error.$msg;
        }

        /*
         * If the REMOTE_USER evironment is set, use it as the username.
         * This assumes that you have externally authenticated the user.
         */
        function login($username, $password) {
            if ($username == "admin") return;
            if ($username == "") return;
            set_error_handler(array('IMAPAuthentication', 'eh'));
            $mbox = imap_open(IMAPAuthentication::get_mailbox(), $username.IMAPAuthentication::get_user_suffix(), $password, OP_HALFOPEN) or $error = imap_last_error();
            if ($mbox) {
                $password = IMAPAuthentication::get_secret_key().$username;
            } else {
                $password = "\0"; // should be pretty difficult to get a null into the db, and this needs to be non-blank
            }
            imap_close($mbox);
            restore_error_handler();
        }

        /*
         * "Verify" the user's password entries by returning the value
         * used by this plugin.
         */
        function check_passwords($username, $password1, $password2) {
            if ($username == "admin") return;
            if ($username == "") return;
            $password1 = $password2 = IMAPAuthentication::get_secret_key().$username;
        }

        /*
         * Used to disable certain login functions, e.g. retrieving a
         * user's password.
         */
        function disable_function() {
            die('Disabled');
        }

        /*
         * Used to disable certain display elements, e.g. password
         * fields on profile screen.
         */
        function show_password_fields($username) {
            if ($username == "admin") return true;
            if ($username == "") return false;
            return false;
        }
    }
}
?>