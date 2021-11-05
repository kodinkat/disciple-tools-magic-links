<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
} // Exit if accessed directly

/**
 * Class Disciple_Tools_Magic_Links_API
 */
class Disciple_Tools_Magic_Links_API {

    public static $option_dt_magic_links_objects = 'dt_magic_links_objects';
    public static $option_dt_magic_links_all_scheduling_enabled = 'dt_magic_links_all_scheduling_enabled';
    public static $option_dt_magic_links_all_channels_enabled = 'dt_magic_links_all_channels_enabled';
    public static $option_dt_magic_links_logging = 'dt_magic_links_logging';
    public static $option_dt_magic_links_last_cron_run = 'dt_magic_links_last_cron_run';
    public static $option_dt_magic_links_local_time_zone = 'dt_magic_links_local_time_zone';
    public static $option_dt_magic_links_defaults_email = 'dt_magic_links_defaults_email';

    public static $schedule_last_schedule_run = 'last_schedule_run';
    public static $schedule_last_success_send = 'last_success_send';
    public static $schedule_links_expire_base_ts = 'links_expire_within_base_ts';

    public static $channel_email_id = 'dt_channel_email';
    public static $channel_email_name = 'Email Sending Channel';

    public static $assigned_user_type_id_users = 'users';
    public static $assigned_user_type_id_contacts = 'contacts';
    public static $assigned_user_type_id_groups = 'groups';

    public static function fetch_magic_link_types(): array {
        $filtered_types = apply_filters( 'dt_settings_apps_list', [] );

        // Only focus on magic link related app settings
        $magic_link_types = [];
        if ( ! empty( $filtered_types ) ) {
            foreach ( $filtered_types as $key => $app ) {
                if ( isset( $app['meta'] ) && $app['meta']['app_type'] === 'magic_link' ) {
                    $magic_link_types[] = $app;
                }
            }
        }

        return $magic_link_types;
    }

    public static function fetch_magic_link_type( $key ) {
        foreach ( self::fetch_magic_link_types() as $app ) {
            if ( $app['key'] === $key ) {
                return $app;
            }
        }

        return null;
    }

    public static function fetch_dt_users(): array {
        global $wpdb;

        // Fetch user ids
        $user_ids = $wpdb->get_results( "
            SELECT u.ID, u.display_name
            FROM $wpdb->users u
        ", ARRAY_A );

        if ( ! empty( $user_ids ) ) {
            $users = [];
            foreach ( $user_ids as $user ) {

                // Must have a corresponding contact_id
                $contact_id = Disciple_Tools_Users::get_contact_for_user( $user['ID'] );
                if ( ! empty( $contact_id ) && ! is_wp_error( $contact_id ) ) {
                    $users[] = [
                        'user_id'    => $user['ID'],
                        'contact_id' => $contact_id,
                        'name'       => $user['display_name'],
                        'phone'      => self::fetch_dt_contacts_comms_info( $contact_id, 'contact_phone' ),
                        'email'      => self::fetch_wp_users_comms_info( $user['ID'], 'email' )
                    ];
                }
            }

            return $users;
        }

        return [];
    }

    public static function fetch_dt_teams(): array {
        global $wpdb;

        // Fetch team ids
        $team_ids = $wpdb->get_results( "
        SELECT DISTINCT(pm.post_id)
        FROM $wpdb->posts p
        LEFT JOIN $wpdb->postmeta as pm ON (p.ID = pm.post_id AND pm.meta_key = 'group_type')
        WHERE pm.meta_value = 'team';
        ", ARRAY_A );

        // Fetch team objects
        if ( ! empty( $team_ids ) ) {
            $teams = [];
            foreach ( $team_ids as $id ) {
                $team = DT_Posts::get_post( 'groups', $id['post_id'], true, false );
                if ( ! empty( $team ) && ! is_wp_error( $team ) && isset( $team['group_type']['key'] ) && $team['group_type']['key'] === 'team' ) {

                    // Ensure team members also contain corresponding user ids
                    $updated_team = self::capture_team_member_user_ids( $team );

                    // Only capture what we need
                    $teams[] = [
                        'id'      => $updated_team['ID'],
                        'name'    => $updated_team['name'],
                        'members' => $updated_team['members']
                    ];
                }
            }

            return $teams;
        }

        return [];
    }

    private static function capture_team_member_user_ids( $team ): array {
        if ( ! empty( $team ) && isset( $team['members'] ) ) {
            foreach ( $team['members'] as $key => $member ) {

                // Fetch corresponding user ids for team members; which currently deal in contact post ids!
                $corresponds_to_user_id = get_post_meta( $member['ID'], "corresponds_to_user", true );
                if ( ! empty( $corresponds_to_user_id ) ) {
                    $team['members'][ $key ]['user_id'] = $corresponds_to_user_id;
                    $team['members'][ $key ]['phone']   = self::fetch_dt_contacts_comms_info( $member['ID'], 'contact_phone' );
                    $team['members'][ $key ]['email']   = self::fetch_wp_users_comms_info( $corresponds_to_user_id, 'email' );
                }
            }
        }

        return $team;
    }

    private static function fetch_dt_contacts_comms_info( $contact_id, $field_id ): array {
        $contact = DT_Posts::get_post( 'contacts', $contact_id, true, false );

        $comms = [];
        if ( ! empty( $contact ) && ! is_wp_error( $contact ) ) {
            foreach ( $contact[ $field_id ] ?? [] as $comm ) {
                $comms[] = $comm['value'];
            }
        }

        return $comms;
    }

    private static function fetch_wp_users_comms_info( $user_id, $field_id ): array {
        $user_info = get_userdata( $user_id );

        $comms = [];
        if ( ! empty( $user_info ) && ! is_wp_error( $user_info ) ) {
            switch ( $field_id ) {
                case 'email':
                    if ( isset( $user_info->data->user_email ) ) {
                        $comms[] = $user_info->data->user_email;
                    }
                    break;
            }
        }

        return $comms;
    }

    public static function fetch_option_link_objs() {
        $option = get_option( self::$option_dt_magic_links_objects );

        return ( ! empty( $option ) ) ? json_decode( $option ) : (object) [];
    }

    public static function fetch_option_link_obj( $link_obj_id ) {
        $option_link_objs = self::fetch_option_link_objs();

        return ( isset( $option_link_objs->{$link_obj_id} ) ) ? $option_link_objs->{$link_obj_id} : (object) [];
    }

    public static function update_option_link_obj( $link_obj ) {
        $option_link_objs = self::fetch_option_link_objs();

        $option_link_objs->{$link_obj->id} = $link_obj;

        // Save changes.
        update_option( self::$option_dt_magic_links_objects, json_encode( $option_link_objs ) );
    }

    public static function fetch_option( $option ) {
        return get_option( $option );
    }

    public static function update_option( $option, $value ) {
        update_option( $option, $value );
    }

    public static function update_user_app_magic_links( $magic_key, $assigned, $delete ) {
        if ( ! empty( $magic_key ) && ! empty( $assigned ) ) {
            foreach ( $assigned ?? [] as $user ) {

                // Only process types: user + members
                if ( isset( $user->type ) && in_array( strtolower( trim( $user->type ) ), [ 'user', 'member' ] ) ) {
                    if ( isset( $user->dt_id ) ) {

                        // Delete/Create accordingly, based on flag!
                        if ( $delete === true ) {
                            delete_user_option( $user->dt_id, $magic_key );
                        } else {
                            update_user_option( $user->dt_id, $magic_key, dt_create_unique_key() );
                        }
                    }
                }
            }
        }
    }

    public static function fetch_sending_channels(): array {
        $filtered_channels = apply_filters( 'dt_sending_channels', [] );

        // Only focus on enabled channels
        $sending_channels = [];
        if ( ! empty( $filtered_channels ) ) {
            foreach ( $filtered_channels as $key => $channel ) {
                if ( isset( $channel['enabled'] ) && $channel['enabled'] ) {
                    $sending_channels[] = $channel;
                }
            }
        }

        return $sending_channels;
    }

    public static function fetch_sending_channel( $id ) {
        foreach ( self::fetch_sending_channels() as $channel ) {
            if ( $channel['id'] === $id ) {
                return $channel;
            }
        }

        return null;
    }

    public static function logging_load(): array {
        return ! empty( get_option( self::$option_dt_magic_links_logging ) ) ? json_decode( get_option( self::$option_dt_magic_links_logging ) ) : [];
    }

    public static function logging_create( $msg ) {
        return (object) [
            'timestamp' => time(),
            'log'       => $msg
        ];
    }

    public static function logging_update( $logs ) {
        update_option( self::$option_dt_magic_links_logging, json_encode( $logs ) );
    }

    public static function logging_add( $log ) {
        $logs   = self::logging_load();
        $logs[] = self::logging_create( $log );
        self::logging_update( $logs );
    }

    public static function logging_aged() {
        // Remove entries older than specified aged period!
        $logs = self::logging_load();
        if ( ! empty( $logs ) ) {
            $cut_off_point_ts  = time() - ( 3600 * 1 ); // 1 hr ago!
            $cut_off_point_idx = 0;

            $count = count( $logs );
            for ( $x = 0; $x < $count; $x ++ ) {

                // Stale logs will typically be found at the start! Therefore, capture transition point!
                if ( $logs[ $x ]->timestamp > $cut_off_point_ts ) {
                    $cut_off_point_idx = $x;
                    $x                 = $count;
                }
            }

            // Age off any stale logs
            if ( $cut_off_point_idx > 0 ) {
                $stale_logs = array_splice( $logs, 0, $cut_off_point_idx );
                self::logging_update( $logs );
            }
        }
    }

    public static function is_scheduled_to_run( $link_obj ): bool {
        if ( ! isset( $link_obj->schedule->last_schedule_run ) || empty( $link_obj->schedule->last_schedule_run ) ) {
            return true;

        } else if ( isset( $link_obj->schedule->enabled ) && ! $link_obj->schedule->enabled ) {
            return false;
        }

        $frequency         = '+' . $link_obj->schedule->freq_amount . ' ' . $link_obj->schedule->freq_time_unit;
        $last_schedule_run = $link_obj->schedule->last_schedule_run;

        return strtotime( $frequency, $last_schedule_run ) < time(); // In the past!
    }

    public static function send( $link_obj, $user, &$logs ) {
        $logs[] = self::logging_create( 'Processing Assigned User: ' . $user->name );

        // Fetch magic link type details
        $magic_link_type = self::fetch_magic_link_type( $link_obj->type );
        if ( ! empty( $magic_link_type ) ) {

            // Fetch sending channel details
            $sending_channel = self::fetch_sending_channel( $link_obj->schedule->sending_channel );
            if ( ! empty( $sending_channel ) ) {

                // Construct message to be sent
                $message = self::build_send_msg( $link_obj, $user, $magic_link_type['url_base'] );
                if ( $message !== '' ) {

                    // Dispatch message using specified sending channel
                    $send_result = call_user_func( $sending_channel['send'], [
                        'user'    => $user,
                        'message' => $message
                    ] );

                    // A successful outcome....?
                    if ( ! is_wp_error( $send_result ) && $send_result ) {

                        // Update last successful send timestamp
                        self::update_schedule_settings( $link_obj->id, self::$schedule_last_success_send, time() );
                        $logs[] = self::logging_create( 'Last successful send timestamp updated.' );

                    } else {
                        $logs[] = self::logging_create( 'Unable to successfully send using sending channel id: ' . $link_obj->schedule->sending_channel );

                        // If WP Error, then extract exception information
                        if ( is_wp_error( $send_result ) ) {
                            $logs[] = self::logging_create( 'Exception: ' . $send_result->get_error_message() );
                        }
                    }
                } else {
                    $logs[] = self::logging_create( 'Unable to construct magic link message! Has the link expired?' );
                }
            } else {
                $logs[] = self::logging_create( 'Unable to fetch sending channel details for id: ' . $link_obj->schedule->sending_channel );
            }
        } else {
            $logs[] = self::logging_create( 'Unable to fetch magic link type details for key: ' . $link_obj->type );
        }
    }

    public static function determine_assigned_user_type( $user ): string {
        if ( in_array( strtolower( trim( $user->type ) ), [
            'user',
            'member'
        ] ) ) { // TODO: Determine if member is of type user or contact!
            return self::$assigned_user_type_id_users;

        } else if ( strtolower( trim( $user->type ) ) == 'contact' ) {
            return self::$assigned_user_type_id_contacts;

        } else if ( strtolower( trim( $user->type ) ) == 'group' ) {
            return self::$assigned_user_type_id_groups;
        }

        return '';
    }

    public static function determine_assigned_user_name( $user ) {
        $name = '';
        switch ( self::determine_assigned_user_type( $user ) ) {
            case self::$assigned_user_type_id_users:

                $user_info = get_userdata( $user->dt_id );
                if ( ! empty( $user_info ) && ! is_wp_error( $user_info ) && isset( $user_info->data->display_name ) ) {
                    $name = $user_info->data->display_name;
                }
                break;

            case self::$assigned_user_type_id_contacts:

                $user_contact = DT_Posts::get_post( 'contacts', self::get_contact_id_by_user_id( $user->dt_id ), true, false );
                if ( ! empty( $user_contact ) && ! is_wp_error( $user_contact ) ) {
                    $name = $user_contact['title'] ?? '';
                }
                break;

            case self::$assigned_user_type_id_groups:
                // TODO
                break;
        }

        return $name;
    }

    private static function build_send_msg( $link_obj, $user, $magic_link_url_base ): string {
        $msg = '';

        // First, build magic link url
        $link_url = self::build_magic_link_url( $link_obj, $user->dt_id, $magic_link_url_base );
        if ( ! empty( $link_obj->message ) && ! empty( $link_url ) && $link_url !== '' ) {

            // Format expired date
            $expire_on = self::fetch_links_expired_formatted_date( $link_obj->schedule->links_never_expires, $link_obj->schedule->links_expire_within_base_ts, $link_obj->schedule->links_expire_within_amount, $link_obj->schedule->links_expire_within_time_unit );

            // Construct message, having replaced placeholders
            $msg = str_replace(
                [
                    '{{name}}',
                    '{{link}}',
                    '{{time}}'
                ],
                [
                    self::determine_assigned_user_name( $user ),
                    $link_url,
                    $expire_on
                ],
                $link_obj->message
            );
        }

        return $msg;
    }

    public static function fetch_default_send_msg(): string {
        return 'Hello {{name}},

Please follow the link below and update records!

{{link}}

As a reminder, the above link will expire on {{time}}

Thanks!';
    }

    public static function fetch_default_email_subject(): string {
        return __( 'Smart Link', 'disciple_tools' );
    }

    private static function build_magic_link_url( $link_obj, $user_id, $magic_link_url_base ): string {
        $hash = get_user_option( $link_obj->type, $user_id );
        if ( ! empty( $hash ) ) {
            return trailingslashit( trailingslashit( site_url() ) . $magic_link_url_base ) . $hash;
        }

        return '';
    }

    public static function update_schedule_settings( $link_obj_id, $setting, $value ) {
        $link_obj = self::fetch_option_link_obj( $link_obj_id );

        if ( ! empty( $link_obj ) ) {
            $link_obj->schedule->{$setting} = $value;
            self::update_option_link_obj( $link_obj );
        }
    }

    public static function has_obj_expired( $never_expires, $expiry_point ): bool {
        if ( $never_expires === true ) {
            return false;
        }

        return $expiry_point < time(); // In the past!
    }

    public static function has_links_expired( $never_expires, $base_ts, $amt, $time_unit ): bool {
        if ( $never_expires === true ) {
            return false;
        }

        $expiry_point = strtotime( '+' . $amt . ' ' . $time_unit, $base_ts );

        return $expiry_point < time(); // In the past!
    }

    public static function fetch_links_expired_formatted_date( $never_expires, $base_ts, $amt, $time_unit ): string {
        if ( $never_expires === true ) {
            return 'Never';
        }

        $expiry_point = strtotime( '+' . $amt . ' ' . $time_unit, $base_ts );

        return self::format_timestamp_in_local_time_zone( $expiry_point );
    }

    public static function format_timestamp_in_local_time_zone( $timestamp ): string {
        $option            = self::fetch_option( self::$option_dt_magic_links_local_time_zone );
        $default_time_zone = ! empty( $option ) ? $option : 'UTC';

        try {
            $dt = new DateTime();
            $dt->setTimezone( new DateTimeZone( $default_time_zone ) );
            $dt->setTimestamp( $timestamp );

            return $dt->format( 'F j, Y h:i:s A T' );

        } catch ( Exception $e ) {
            return dt_format_date( $timestamp, 'long' );
        }
    }

    public static function fetch_endpoint_send_now_url(): string {
        return trailingslashit( site_url() ) . 'wp-json/disciple_tools_magic_links/v1/send_now';
    }

    public static function fetch_endpoint_report_url(): string {
        return trailingslashit( site_url() ) . 'wp-json/disciple_tools_magic_links/v1/report';
    }

    public static function fetch_report( $id ) {
        switch ( $id ) {
            case 'sent-vs-updated':
                return self::fetch_report_sent_vs_updated();
        }
    }

    private static function fetch_report_sent_vs_updated(): array {

        $report = [];

        // Loop through all active link objects
        $link_objs = self::fetch_option_link_objs();
        foreach ( $link_objs ?? [] as $id => $link_obj ) {

            // Ensure object is enabled, and we have a valid last successful send timestamp
            if ( $link_obj->enabled && ! empty( $link_obj->schedule->last_success_send ) ) {

                // Next, estimate total counts across all assigned user contacts
                $totals = self::report_sent_vs_updated_totals( $link_obj->assigned, $link_obj->schedule->last_success_send );

                // Package and return findings
                $report[] = [
                    'id'                     => $link_obj->id,
                    'name'                   => $link_obj->name,
                    'last_success_send'      => self::format_timestamp_in_local_time_zone( $link_obj->schedule->last_success_send ),
                    'total_contacts'         => $totals['total_contacts'],
                    'total_updated_contacts' => $totals['total_updated_contacts']
                ];
            }
        }

        return $report;
    }

    private static function report_sent_vs_updated_totals( $users, $last_success_send ): array {

        $contacts      = [];
        $updated_count = 0;

        $original_user = wp_get_current_user();
        foreach ( $users ?? [] as $user ) {
            if ( in_array( trim( strtolower( $user->type ) ), [ 'user', 'member' ] ) ) {

                // Switch current user id status and fetch associated contacts list
                wp_set_current_user( $user->dt_id );

                // Fetch associated contacts list
                $posts = DT_Posts::list_posts( 'contacts', [ 'limit' => 1000 ] );

                // Iterate and return valid posts
                if ( ! empty( $posts ) && isset( $posts['posts'], $posts['total'] ) ) {
                    foreach ( $posts['posts'] ?? [] as $post ) {

                        // Ensure post has not already been processed
                        $key = '_' . $post['ID'];
                        if ( ! array_key_exists( $key, $contacts ) ) {
                            $updated          = ( isset( $post['last_modified']['timestamp'] ) && ( intval( $post['last_modified']['timestamp'] ) > intval( $last_success_send ) ) ) ?? false;
                            $contacts[ $key ] = [
                                'id'      => $post['ID'],
                                'updated' => $updated
                            ];

                            // If required, increment updated count
                            if ( $updated ) {
                                $updated_count ++;
                            }
                        }
                    }
                }
            }
        }

        // Revert to original user
        if ( ! empty( $original_user ) && isset( $original_user->ID ) ) {
            wp_set_current_user( $original_user->ID );
        }

        return [
            'total_contacts'         => count( $contacts ),
            'total_updated_contacts' => $updated_count
        ];
    }

    public static function list_available_time_zones(): array {
        $time_zones = [
            'UTC',
            'Africa/Abidjan',
            'Africa/Accra',
            'Africa/Addis_Ababa',
            'Africa/Algiers',
            'Africa/Asmara',
            'Africa/Bamako',
            'Africa/Bangui',
            'Africa/Banjul',
            'Africa/Bissau',
            'Africa/Blantyre',
            'Africa/Brazzaville',
            'Africa/Bujumbura',
            'Africa/Cairo',
            'Africa/Casablanca',
            'Africa/Ceuta',
            'Africa/Conakry',
            'Africa/Dakar',
            'Africa/Dar_es_Salaam',
            'Africa/Djibouti',
            'Africa/Douala',
            'Africa/El_Aaiun',
            'Africa/Freetown',
            'Africa/Gaborone',
            'Africa/Harare',
            'Africa/Johannesburg',
            'Africa/Juba',
            'Africa/Kampala',
            'Africa/Khartoum',
            'Africa/Kigali',
            'Africa/Kinshasa',
            'Africa/Lagos',
            'Africa/Libreville',
            'Africa/Lome',
            'Africa/Luanda',
            'Africa/Lubumbashi',
            'Africa/Lusaka',
            'Africa/Malabo',
            'Africa/Maputo',
            'Africa/Maseru',
            'Africa/Mbabane',
            'Africa/Mogadishu',
            'Africa/Monrovia',
            'Africa/Nairobi',
            'Africa/Ndjamena',
            'Africa/Niamey',
            'Africa/Nouakchott',
            'Africa/Ouagadougou',
            'Africa/Porto-Novo',
            'Africa/Sao_Tome',
            'Africa/Tripoli',
            'Africa/Tunis',
            'Africa/Windhoek',
            'America/New_York',
            'America/Chicago',
            'America/Denver',
            'America/Phoenix',
            'America/Los_Angeles',
            'America/Anchorage',
            'America/Adak',
            'America/St_Johns',
            'America/Halifax',
            'America/Blanc-Sablon',
            'America/Toronto',
            'America/Atikokan',
            'America/Winnipeg',
            'America/Regina',
            'America/Edmonton',
            'America/Creston',
            'America/Vancouver',
            'America/Tijuana',
            'America/Mazatlan',
            'America/Chihuahua',
            'America/Mexico_City',
            'America/Matamoros',
            'America/Monterrey',
            'America/Merida',
            'America/Cancun',
            'America/Rio_branco',
            'America/Belem',
            'America/Bahia',
            'America/Sao_Paulo',
            'America/Cuiaba',
            'America/Fortaleza',
            'America/Recife',
            'America/Boa_Vista',
            'America/Maceio',
            'America/Araguaia',
            'America/Manaus',
            'America/Campo_Grande',
            'America/Porto_Velho',
            'Asia/Aden',
            'Asia/Almaty',
            'Asia/Amman',
            'Asia/Anadyr',
            'Asia/Aqtau',
            'Asia/Aqtobe',
            'Asia/Ashgabat',
            'Asia/Atyrau',
            'Asia/Baghdad',
            'Asia/Bahrain',
            'Asia/Baku',
            'Asia/Bangkok',
            'Asia/Barnaul',
            'Asia/Beirut',
            'Asia/Bishkek',
            'Asia/Brunei',
            'Asia/Chita',
            'Asia/Choibalsan',
            'Asia/Colombo',
            'Asia/Damascus',
            'Asia/Dhaka',
            'Asia/Dili',
            'Asia/Dubai',
            'Asia/Dushanbe',
            'Asia/Famagusta',
            'Asia/Gaza',
            'Asia/Hebron',
            'Asia/Ho_Chi_Minh',
            'Asia/Hong_Kong',
            'Asia/Hovd',
            'Asia/Irkutsk',
            'Asia/Jakarta',
            'Asia/Jayapura',
            'Asia/Jerusalem',
            'Asia/Kabul',
            'Asia/Kamchatka',
            'Asia/Karachi',
            'Asia/Kathmandu',
            'Asia/Khandyga',
            'Asia/Kolkata',
            'Asia/Krasnoyarsk',
            'Asia/Kuala_Lumpur',
            'Asia/Kuching',
            'Asia/Kuwait',
            'Asia/Macau',
            'Asia/Magadan',
            'Asia/Makassar',
            'Asia/Manila',
            'Asia/Muscat',
            'Asia/Nicosia',
            'Asia/Novokuznetsk',
            'Asia/Novosibirsk',
            'Asia/Omsk',
            'Asia/Oral',
            'Asia/Phnom_Penh',
            'Asia/Pontianak',
            'Asia/Pyongyang',
            'Asia/Qatar',
            'Asia/Qostanay',
            'Asia/Qyzylorda',
            'Asia/Riyadh',
            'Asia/Sakhalin',
            'Asia/Samarkand',
            'Asia/Seoul',
            'Asia/Shanghai',
            'Asia/Singapore',
            'Asia/Srednekolymsk',
            'Asia/Taipei',
            'Asia/Tashkent',
            'Asia/Tbilisi',
            'Asia/Tehran',
            'Asia/Thimphu',
            'Asia/Tokyo',
            'Asia/Tomsk',
            'Asia/Ulaanbaatar',
            'Asia/Urumqi',
            'Asia/Ust-Nera',
            'Asia/Vientiane',
            'Asia/Vladivostok',
            'Asia/Yakutsk',
            'Asia/Yangon',
            'Asia/Yekaterinburg',
            'Asia/Yerevan',
            'Atlantic/Azores',
            'Atlantic/Bermuda',
            'Atlantic/Canary',
            'Atlantic/Cape_Verde',
            'Atlantic/Faroe',
            'Atlantic/Madeira',
            'Atlantic/Reykjavik',
            'Atlantic/South_Georgia',
            'Atlantic/St_Helena',
            'Atlantic/Stanley',
            'Australia/Adelaide',
            'Australia/Brisbane',
            'Australia/Broken_Hill',
            'Australia/Darwin',
            'Australia/Eucla',
            'Australia/Hobart',
            'Australia/Lindeman',
            'Australia/Lord_Howe',
            'Australia/Melbourne',
            'Australia/Perth',
            'Australia/Sydney',
            'Europe/Amsterdam',
            'Europe/Athens',
            'Europe/Belgrade',
            'Europe/Berlin',
            'Europe/Bratislava',
            'Europe/Brussels',
            'Europe/Bucharest',
            'Europe/Budapest',
            'Europe/Copenhagen',
            'Europe/Dublin',
            'Europe/Gibraltar',
            'Europe/Guernsey',
            'Europe/Helsinki',
            'Europe/Isle_of_Man',
            'Europe/Istanbul',
            'Europe/Jersey',
            'Europe/Kaliningrad',
            'Europe/Kiev',
            'Europe/Lisbon',
            'Europe/London',
            'Europe/Luxembourg',
            'Europe/Madrid',
            'Europe/Malta',
            'Europe/Monaco',
            'Europe/Moscow',
            'Europe/Oslo',
            'Europe/Paris',
            'Europe/Prague',
            'Europe/Rome',
            'Europe/Samara',
            'Europe/Stockholm',
            'Europe/Vatican',
            'Europe/Vienna',
            'Europe/Warsaw',
            'Indian/Antananarivo',
            'Indian/Chagos',
            'Indian/Christmas',
            'Indian/Cocos',
            'Indian/Comoro',
            'Indian/Kerguelen',
            'Indian/Mahe',
            'Indian/Maldives',
            'Indian/Mauritius',
            'Indian/Mayotte',
            'Indian/Reunion',
            'Pacific/Pago_Pago',
            'Pacific/Chuuk',
            'Pacific/Guam',
            'Pacific/Honolulu',
            'Pacific/Majuro',
            'Pacific/Saipan',
            'Pacific/Palau'
        ];

        asort( $time_zones, SORT_STRING );

        return $time_zones;
    }

    public static function get_contact_id_by_user_id( $user_id ) {
        $contact_id = get_user_option( "corresponds_to_contact", $user_id );

        if ( ! empty( $contact_id ) && get_post( $contact_id ) ) {
            return (int) $contact_id;
        }
        $args     = [
            'post_type'  => 'contacts',
            'relation'   => 'AND',
            'meta_query' => [
                [
                    'key'   => "corresponds_to_user",
                    "value" => $user_id
                ],
                [
                    'key'   => "type",
                    "value" => "user"
                ],
            ],
        ];
        $contacts = new WP_Query( $args );
        if ( isset( $contacts->post->ID ) ) {
            update_user_option( $user_id, "corresponds_to_contact", $contacts->post->ID );

            return $contacts->post->ID;
        } else {
            return null;
        }
    }

}
