<?php

/**
 * Clients.
 */

// -------------------------------------------------------------------------------------------------

namespace FormTools;


class Clients {


    /**
     * Updates a client account. Used for whomever is currently logged in.
     *
     * @param array $info This parameter should be a hash (e.g. $_POST or $_GET) containing keys
     *               named the same as the database fields.
     * @return array [0]: true/false (success / failure)
     *               [1]: message string
     */
    public static function updateClient($account_id, $info)
    {
        global $g_table_prefix, $LANG, $g_password_special_chars;

        $success = true;
        $message = $LANG["notify_account_updated"];

        extract(Hooks::processHookCalls("start", compact("account_id", "info"), array("info")), EXTR_OVERWRITE);

        $client_info = Accounts::getAccountInfo($account_id);

        $page = $info["page"];
        switch ($page) {
            case "main":
                $first_name   = $info["first_name"];
                $last_name    = $info["last_name"];
                $email        = $info["email"];
                $username     = $info["username"];

                $password_clause = "";
                $rules           = array();
                if (!empty($info["password"]))
                {
                    $required_password_chars = explode(",", $client_info["settings"]["required_password_chars"]);
                    if (in_array("uppercase", $required_password_chars))
                        $rules[] = "reg_exp,password,[A-Z],{$LANG["validation_client_password_missing_uppercase"]}";
                    if (in_array("number", $required_password_chars))
                        $rules[] = "reg_exp,password,[0-9],{$LANG["validation_client_password_missing_number"]}";
                    if (in_array("special_char", $required_password_chars))
                    {
                        $error = General::evalSmartyString($LANG["validation_client_password_missing_special_char"], array("chars" => $g_password_special_chars));
                        $password_special_chars = preg_quote($g_password_special_chars);
                        $rules[] = "reg_exp,password,[$password_special_chars],$error";
                    }
                    if (!empty($client_info["settings"]["min_password_length"]))
                    {
                        $rule = General::evalSmartyString($LANG["validation_client_password_too_short"], array("number" => $client_info["settings"]["min_password_length"]));
                        $rules[] = "length>={$client_info["settings"]["min_password_length"]},password,$rule";
                    }

                    // encrypt the password on the assumption that it passes validation. It'll be used in the update query
                    $password = md5(md5($info['password']));
                    $password_clause = "password = '$password',";
                }

                $errors = validate_fields($info, $rules);

                // check to see if username is already taken
                list($valid_username, $problem) = Accounts::isValidUsername($username, $account_id);
                if (!$valid_username)
                    $errors[] = $problem;

                // check the password isn't already in password history (if relevant)
                if (!empty($info["password"]))
                {
                    if (!empty($client_info["settings"]["num_password_history"]))
                    {
                        $encrypted_password = md5(md5($info["password"]));
                        if (ft_password_in_password_history($account_id, $encrypted_password, $client_info["settings"]["num_password_history"])) {
                            $errors[] = General::evalSmartyString($LANG["validation_password_in_password_history"],
                            array("history_size" => $client_info["settings"]["num_password_history"]));
                        } else {
                            Accounts::addPasswordToPasswordHistory($account_id, $encrypted_password);
                        }
                    }
                }

                if (!empty($errors)) {
                    $success = false;
                    array_walk($errors, create_function('&$el','$el = "&bull;&nbsp; " . $el;'));
                    $message = implode("<br />", $errors);
                    return array($success, $message);
                }

                $query = "
          UPDATE  {$g_table_prefix}accounts
          SET     $password_clause
                  first_name = '$first_name',
                  last_name = '$last_name',
                  username = '$username',
                  email = '$email'
          WHERE   account_id = $account_id
               ";
                if (mysql_query($query))
                {
                    // if the password wasn't empty, reset the temporary password, in case it was set
                    if (!empty($info["password"]))
                        mysql_query("UPDATE {$g_table_prefix}accounts SET temp_reset_password = NULL where account_id = $account_id");
                }
                else {
                    ft_handle_error("Failed query in <b>" . __FUNCTION__ . "</b>: <i>$query</i>", mysql_error());
                }
                break;

            case "settings":
                $rules = array();
                if ($client_info["settings"]["may_edit_page_titles"] == "yes")
                    $rules[] = "required,page_titles,{$LANG["validation_no_titles"]}";
                if ($client_info["settings"]["may_edit_theme"] == "yes")
                    $rules[] = "required,theme,{$LANG["validation_no_theme"]}";
                if ($client_info["settings"]["may_edit_logout_url"] == "yes")
                    $rules[] = "required,logout_url,{$LANG["validation_no_logout_url"]}";
                if ($client_info["settings"]["may_edit_language"] == "yes")
                    $rules[] = "required,ui_language,{$LANG["validation_no_ui_language"]}";
                if ($client_info["settings"]["may_edit_timezone_offset"] == "yes")
                    $rules[] = "required,timezone_offset,{$LANG["validation_no_timezone_offset"]}";
                if ($client_info["settings"]["may_edit_sessions_timeout"] == "yes")
                {
                    $rules[] = "required,sessions_timeout,{$LANG["validation_no_sessions_timeout"]}";
                    $rules[] = "digits_only,sessions_timeout,{$LANG["validation_invalid_sessions_timeout"]}";
                }
                if ($client_info["settings"]["may_edit_date_format"] == "yes")
                    $rules[] = "required,date_format,{$LANG["validation_no_date_format"]}";

                $errors = validate_fields($info, $rules);

                if (!empty($errors))
                {
                    $success = false;
                    array_walk($errors, create_function('&$el','$el = "&bull;&nbsp; " . $el;'));
                    $message = implode("<br />", $errors);
                    return array($success, $message);
                }

                // update the main accounts table. Only update those settings they're ALLOWED to
                $settings = array();
                if ($client_info["settings"]["may_edit_language"] == "yes")
                    $settings["ui_language"] = $info["ui_language"];
                if ($client_info["settings"]["may_edit_timezone_offset"] == "yes")
                    $settings["timezone_offset"] = $info["timezone_offset"];
                if ($client_info["settings"]["may_edit_logout_url"] == "yes")
                    $settings["logout_url"] = $info["logout_url"];
                if ($client_info["settings"]["may_edit_sessions_timeout"] == "yes")
                    $settings["sessions_timeout"] = $info["sessions_timeout"];
                if ($client_info["settings"]["may_edit_theme"] == "yes")
                {
                    $settings["theme"] = $info["theme"];
                    $settings["swatch"] = "";
                    if (isset($info["{$info["theme"]}_theme_swatches"]))
                        $settings["swatch"] = $info["{$info["theme"]}_theme_swatches"];
                }
                if ($client_info["settings"]["may_edit_date_format"] == "yes")
                    $settings["date_format"] = $info["date_format"];

                if (!empty($settings))
                {
                    $sql_rows = array();
                    while (list($column, $value) = each($settings))
                        $sql_rows[] = "$column = '$value'";

                    $sql = implode(",\n", $sql_rows);
                    $query = "
            UPDATE  {$g_table_prefix}accounts
            SET     $sql
            WHERE   account_id = $account_id
                 ";
                    mysql_query($query)
                    or ft_handle_error("Failed query in <b>" . __FUNCTION__ . "</b>: <i>$query</i>", mysql_error());
                }

                $settings = array();
                if (isset($info["page_titles"]))
                    $settings["page_titles"] = $info["page_titles"];
                if (isset($info["footer_text"]))
                    $settings["footer_text"] = $info["footer_text"];
                if (isset($info["max_failed_login_attempts"]))
                    $settings["max_failed_login_attempts"] = $info["max_failed_login_attempts"];

                if (!empty($settings)) {
                    Accounts::setAccountSettings($account_id, $settings);
                }
                break;
        }

        extract(Hooks::processHookCalls("end", compact("account_id", "info"), array("success", "message")), EXTR_OVERWRITE);

        // update sessions
        $_SESSION["ft"]["settings"] = Settings::get();
        $_SESSION["ft"]["account"]  = Accounts::getAccountInfo($account_id);
        $_SESSION["ft"]["account"]["is_logged_in"] = true;

        return array($success, $message);
    }


    /**
     * TODO move to administrator?
     * Completely removes a client account from the database, including any email-related stuff that requires
     * their user account.
     *
     * @param integer $account_id the unique account ID
     * @return array [0]: true/false (success / failure)
     *               [1]: message string
     */
    public static function deleteClient($account_id)
    {
        $db = Core::$db;
        $db->beginTransaction();

        $db->query("DELETE FROM {PREFIX}accounts WHERE account_id = :account_id");
        $db->bind(":account_id", $account_id);
        $db->execute();

        $db->query("DELETE FROM {PREFIX}account_settings WHERE account_id = :account_id");
        $db->bind(":account_id", $account_id);
        $db->execute();

        $db->query("DELETE FROM {PREFIX}client_forms WHERE account_id = :account_id");
        $db->bind(":account_id", $account_id);
        $db->execute();

        $db->query("DELETE FROM {PREFIX}email_template_recipients WHERE account_id = :account_id");
        $db->bind(":account_id", $account_id);
        $db->execute();

        $db->query("DELETE FROM {PREFIX}email_templates WHERE email_from account_id = :account_id OR email_to_account_id = :account_id");
        $db->bind(":account_id", $account_id);
        $db->execute();

        $db->query("DELETE FROM {PREFIX}public_form_omit_list WHERE account_id = :account_id");
        $db->bind(":account_id", $account_id);
        $db->execute();

        $db->query("DELETE FROM {PREFIX}public_view_omit_list WHERE account_id = :account_id");
        $db->bind(":account_id", $account_id);
        $db->execute();

        $db->processTransaction();

        $success = true;
        $message = Core::$L["notify_account_deleted"];
        extract(Hooks::processHookCalls("end", compact("account_id"), array("success", "message")), EXTR_OVERWRITE);

        return array($success, $message);
    }


    /**
     * Disables a client account.
     *
     * @param integer $account_id
     */
    public static function disableClient($account_id)
    {
        $db = Core::$db;
        if (empty($account_id) || !is_numeric($account_id)) {
            return;
        }

        $db->query("
            UPDATE {PREFIX}accounts
            SET account_status = 'disabled'
            WHERE account_id = :account_id
        ");
        $db->bind(":account_id", $account_id);
        $db->execute();

        extract(Hooks::processHookCalls("end", compact("account_id"), array()), EXTR_OVERWRITE);
    }

    /**
     * Retrieves a list of all clients in the database ordered by last name. N.B. As of 2.0.0, this function
     * no longer returns a MySQL resource.
     *
     * @return array $clients an array of hashes. Each hash is the client info.
     */
    public static function getList()
    {
        $db = Core::$db;

        $db->query("
            SELECT *
            FROM {PREFIX}accounts
            WHERE account_type = 'client'
            ORDER BY last_name
        ");
        $db->execute();

        $clients = array();
        foreach ($db->fetchAll() as $client) {
            $clients[] = $client;
        }

        return $clients;
    }


    /**
     * Returns the total number of clients in the database.
     *
     * @return int the number of clients
     */
    public static function getNumClients()
    {
        $db = Core::$db;
        $db->query("SELECT count(*) as c FROM {PREFIX}accounts WHERE account_type = 'client'");
        $db->execute();
        $result = $db->fetch();

        return $result["c"];
    }


    /**
     * Basically a wrapper function for ft_search_forms.
     *
     * @return array
     */
    public static function getClientForms($account_id)
    {
        return ft_search_forms($account_id, true);
    }

    /**
     * Simple function to find out how many forms are in the database, regardless of status or anything else.
     *
     * @return integer the number of forms.
     */
    public static function getFormCount()
    {
        $db = Core::$db;

        $db->query("SELECT count(*) as c FROM {PREFIX}forms");
        $db->execute();
        $result = $db->fetch();

        return $result["c"];
    }


    /**
     * This returns all forms and form Views that a client account may access.
     *
     * @param array $account_id
     */
    public static function getClientFormViews($account_id)
    {
        $client_forms = ft_search_forms($account_id);

        $info = array();
        foreach ($client_forms as $form_info) {
            $form_id = $form_info["form_id"];
            $views = ft_get_form_views($form_id, $account_id);

            $view_ids = array();
            foreach ($views as $view_info) {
                $view_ids[] = $view_info["view_id"];
            }
            $info[$form_id] = $view_ids;
        }

        extract(Hooks::processHookCalls("end", compact("account_id", "info"), array("info")), EXTR_OVERWRITE);

        return $info;
    }

    /**
     * This function updates the default theme for multiple accounts simultaneously. It's called when
     * an administrator disables a theme that's current used by some client accounts. They're presented with
     * the option of setting the theme ID for all the clients.
     *
     * There's very little error checking done here...
     *
     * @param string $account_id_str a comma delimited list of account IDs
     * @param integer $theme_id the theme ID
     */
    public static function updateClientThemes($account_ids, $theme_id)
    {
        global $LANG, $g_table_prefix;

        if (empty($account_ids) || empty($theme_id)) {
            return;
        }

        $client_ids = explode(",", $account_ids);

        $theme_info = Themes::getTheme($theme_id);
        $theme_name = $theme_info["theme_name"];
        $theme_folder = $theme_info["theme_folder"];

        foreach ($client_ids as $client_id) {
            mysql_query("UPDATE {$g_table_prefix}accounts SET theme='$theme_folder' WHERE account_id = $client_id");
        }

        $placeholders = array("theme" => $theme_name);
        $message = General::evalSmartyString($LANG["notify_client_account_themes_updated"], $placeholders);
        $success = true;

        return array($success, $message);
    }


    /**
     * Performs a simple search of the client list, returning ALL results (not in pages).
     *
     * @param array $search_criteria optional search / sort criteria. Keys are:
     *                               "order" - (string) client_id-ASC, client_id-DESC, last_name-DESC,
     *                                         last_name-ASC, email-ASC, email-DESC
     *                               "keyword" - (string) searches the client name and email fields.
     *                               "status" - (string) "account_status", "disabled", or empty (all)
     */
    public static function searchClients($search_criteria = array())
    {
        $db = Core::$db;

        extract(Hooks::processHookCalls("start", compact("search_criteria"), array("search_criteria")), EXTR_OVERWRITE);

        if (!isset($search_criteria["order"])) {
            $search_criteria["order"] = "client_id-DESC";
        }

        $order_clause = self::getClientOrderClause($search_criteria["order"]);

        $status_clause = "";
        if (isset($search_criteria["status"])) {
            switch ($search_criteria["status"]) {
                case "active":
                    $status_clause = "account_status = 'active' ";
                    break;
                case "disabled":
                    $status_clause = "account_status = 'disabled'";
                    break;
                case "pending":
                    $status_clause = "account_status = 'pending'";
                    break;
                default:
                    $status_clause = "";
                    break;
            }
        }

        $keyword_clause = "";
        if (isset($search_criteria["keyword"]) && !empty($search_criteria["keyword"])) {
            $string = $search_criteria["keyword"];
            $fields = array("last_name", "first_name", "email", "account_id");

            $clauses = array();
            foreach ($fields as $field) {
                $clauses[] = "$field LIKE '%$string%'";
            }
            $keyword_clause = implode(" OR ", $clauses);
        }

        // add up the where clauses
        $where_clauses = array("account_type = 'client'");
        if (!empty($status_clause)) {
            $where_clauses[] = "($status_clause)";
        }
        if (!empty($keyword_clause)) {
            $where_clauses[] = "($keyword_clause)";
        }

        $where_clause = "WHERE " . implode(" AND ", $where_clauses);

        // get the clients
        $db->query("
            SELECT *
            FROM {PREFIX}accounts
            $where_clause
            $order_clause
        ");
        $db->execute();

        $clients = array();
        foreach ($db->fetchAll() as $row) {
            $clients[] = $row;
        }

        extract(Hooks::processHookCalls("end", compact("search_criteria", "clients"), array("clients")), EXTR_OVERWRITE);

        return $clients;
    }


    /**
     * This returns the IDs of the previous and next client accounts, as determined by the administrators current
     * search and sort.
     *
     * Not happy with this function! Getting this info is surprisingly tricky, once you throw in the sort clause.
     * Still, the number of client accounts are liable to be quite small, so it's not such a sin.
     *
     * @param integer $account_id
     * @param array $search_criteria
     * @return hash prev_account_id => the previous account ID (or empty string)
     *              next_account_id => the next account ID (or empty string)
     */
    function ft_get_client_prev_next_links($account_id, $search_criteria = array())
    {
        global $g_table_prefix;

        $keyword_clause = "";
        if (isset($search_criteria["keyword"]) && !empty($search_criteria["keyword"]))
        {
            $string = $search_criteria["keyword"];
            $fields = array("last_name", "first_name", "email", "account_id");

            $clauses = array();
            foreach ($fields as $field)
                $clauses[] = "$field LIKE '%$string%'";

            $keyword_clause = implode(" OR ", $clauses);
        }

        // add up the where clauses
        $where_clauses = array("account_type = 'client'");
        if (!empty($status_clause)) $where_clauses[] = "($status_clause)";
        if (!empty($keyword_clause)) $where_clauses[] = "($keyword_clause)";

        $where_clause = "WHERE " . implode(" AND ", $where_clauses);

        $order_clause = self::getClientOrderClause($search_criteria["order"]);

        // get the clients
        $client_query_result = mysql_query("
    SELECT account_id
    FROM   {$g_table_prefix}accounts
    $where_clause
    $order_clause
           ");

        $sorted_account_ids = array();
        while ($row = mysql_fetch_assoc($client_query_result))
        {
            $sorted_account_ids[] = $row["account_id"];
        }
        $current_index = array_search($account_id, $sorted_account_ids);

        $return_info = array("prev_account_id" => "", "next_account_id" => "");
        if ($current_index === 0)
        {
            if (count($sorted_account_ids) > 1)
                $return_info["next_account_id"] = $sorted_account_ids[$current_index+1];
        }
        else if ($current_index === count($sorted_account_ids)-1)
        {
            if (count($sorted_account_ids) > 1)
                $return_info["prev_account_id"] = $sorted_account_ids[$current_index-1];
        }
        else
        {
            $return_info["prev_account_id"] = $sorted_account_ids[$current_index-1];
            $return_info["next_account_id"] = $sorted_account_ids[$current_index+1];
        }

        return $return_info;
    }


    // --------------------------------------------------------------------------------------------

    /**
     * Used in a couple of places, so I stuck it here. (Refactor this hideousness!)
     *
     * @param string $order
     * @return string the ORDER BY clause
     */
    private static function getClientOrderClause($order = "")
    {
        $order_clause = "";
        switch ($order)
        {
            case "client_id-DESC":
                $order_clause = "account_id DESC";
                break;
            case "client_id-ASC":
                $order_clause = "account_id ASC";
                break;
            case "first_name-DESC":
                $order_clause = "first_name DESC";
                break;
            case "first_name-ASC":
                $order_clause = "first_name ASC";
                break;
            case "last_name-DESC":
                $order_clause = "last_name DESC";
                break;
            case "last_name-ASC":
                $order_clause = "last_name ASC";
                break;
            case "email-DESC":
                $order_clause = "email DESC";
                break;
            case "email-ASC":
                $order_clause = "email ASC";
                break;
            case "status-DESC":
                $order_clause = "account_status DESC";
                break;
            case "status-ASC":
                $order_clause = "account_status ASC";
                break;
            case "last_logged_in-DESC":
                $order_clause = "last_logged_in DESC";
                break;
            case "last_logged_in-ASC":
                $order_clause = "last_logged_in ASC";
                break;

            default:
                $order_clause = "account_id DESC";
                break;
        }

        if (!empty($order_clause))
            $order_clause = "ORDER BY $order_clause";

        return $order_clause;
    }
}
