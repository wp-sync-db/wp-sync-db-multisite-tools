<?php

class WPMDBPro_Multisite_Tools extends WPMDBPro_Addon {
	protected $wpmdbpro;
	protected $accepted_fields;

	/**
	 * @param string $plugin_file_path
	 */
	function __construct( $plugin_file_path ) {
		parent::__construct( $plugin_file_path );
		$this->plugin_slug    = 'wp-migrate-db-pro-multisite-tools';
		$this->plugin_version = $GLOBALS['wpmdb_meta']['wp-migrate-db-pro-multisite-tools']['version'];
		if ( ! $this->meets_version_requirements( '1.6.1' ) ) {
			return;
		}

		$this->accepted_fields = array(
			'multisite_subsite_export', // TODO: Remove backwards compatibility for CLI once Core/MST/CLI dependencies updated.
			'select_subsite', // TODO: Remove backwards compatibility for CLI once Core/MST/CLI dependencies updated.
			'mst_select_subsite',
			'mst_selected_subsite',
			'new_prefix',
			'keep_active_plugins',
		);

		add_action( 'wpmdb_before_migration_options', array( $this, 'migration_form_controls' ) );
		add_action( 'wpmdb_load_assets', array( $this, 'load_assets' ) );
		add_action( 'wpmdb_diagnostic_info', array( $this, 'diagnostic_info' ) );
		add_filter( 'wpmdb_accepted_profile_fields', array( $this, 'accepted_profile_fields' ) );
		add_filter( 'wpmdb_establish_remote_connection_data', array( $this, 'establish_remote_connection_data' ) );
		add_filter( 'wpmdb_data', array( $this, 'js_variables' ) );

		add_filter( 'wpmdb_exclude_table', array( $this, 'filter_table_for_subsite' ), 10, 2 );
		add_filter( 'wpmdb_tables', array( $this, 'filter_tables_for_subsite' ), 10, 2 );
		add_filter( 'wpmdb_table_sizes', array( $this, 'filter_table_sizes_for_subsite' ), 10, 2 );
		add_filter( 'wpmdb_target_table_name', array( $this, 'filter_target_table_name' ), 10, 4 );
		add_filter( 'wpmdb_table_row', array( $this, 'filter_table_row' ), 10, 4 );
		add_filter( 'wpmdb_find_and_replace', array( $this, 'filter_find_and_replace' ), 10, 3 );
		add_filter( 'wpmdb_finalize_target_table_name', array( $this, 'filter_finalize_target_table_name' ), 10, 3 );
		add_filter( 'wpmdb_preserved_options', array( $this, 'filter_preserved_options' ), 10, 2 );
		add_filter( 'wpmdb_preserved_options_data', array( $this, 'filter_preserved_options_data' ), 10, 2 );
		add_filter( 'wpmdb_get_alter_queries', array( $this, 'filter_get_alter_queries' ) );

		global $wpmdbpro;
		$this->wpmdbpro = $wpmdbpro;

		if ( class_exists( 'WPMDBPro_Media_Files' ) ) {
			add_filter( 'wpmdbmf_include_subsite', array( $this, 'include_subsite' ), 10, 3 );
			add_filter( 'wpmdbmf_destination_file_path', array( $this, 'filter_mf_destination_file_path' ), 10, 3 );
			add_filter( 'wpmdbmf_file_not_on_local', array( $this, 'filter_mf_file_not_on_local' ), 10, 3 );
			add_filter( 'wpmdbmf_get_remote_attachment_batch_response', array( $this, 'filter_mf_get_remote_attachment_batch_response', ), 10, 3 );
			add_filter( 'wpmdbmf_exclude_local_media_file_from_removal', array( $this, 'filter_mf_exclude_local_media_file_from_removal', ), 10, 4 );
			add_filter( 'wpmdbmf_file_to_download', array( $this, 'filter_mf_file_to_download', ), 10, 3 );
		}
	}

	/**
	 * Does the given user need to be migrated?
	 *
	 * @param int $user_id
	 * @param int $blog_id Optional.
	 *
	 * @return bool
	 */
	private function is_user_required_for_blog( $user_id, $blog_id = 0 ) {
		static $users = array();

		if ( empty( $user_id ) ) {
			$user_id = 0;
		}

		if ( empty( $blog_id ) ) {
			$blog_id = 0;
		}

		if ( isset( $users[ $blog_id ][ $user_id ] ) ) {
			return $users[ $blog_id ][ $user_id ];
		}

		if ( ! is_multisite() ) {
			$users[ $blog_id ][ $user_id ] = true;

			return $users[ $blog_id ][ $user_id ];
		}

		$subsites = $this->subsites_list();

		if ( empty( $subsites ) || ! array_key_exists( $blog_id, $subsites ) ) {
			$users[ $blog_id ][ $user_id ] = false;

			return $users[ $blog_id ][ $user_id ];
		}

		if ( is_user_member_of_blog( $user_id, $blog_id ) ) {
			$users[ $blog_id ][ $user_id ] = true;

			return $users[ $blog_id ][ $user_id ];
		}

		// If the user has any posts that are going to be migrated, we need the user regardless of whether they still have access.
		switch_to_blog( $blog_id );
		$user_posts = count_user_posts( $user_id );
		restore_current_blog();

		if ( 0 < $user_posts ) {
			$users[ $blog_id ][ $user_id ] = true;

			return $users[ $blog_id ][ $user_id ];
		}

		// If here, user not required.
		$users[ $blog_id ][ $user_id ] = false;

		return $users[ $blog_id ][ $user_id ];
	}

	/**
	 * Return subsite id if subsite selected.
	 *
	 * @param WPMDB_Base $plugin_instance
	 *
	 * @return int Will return 0 if not doing MST migration.
	 *
	 * Will return 0 if not doing MST migration.
	 */
	public function selected_subsite( &$plugin_instance = null ) {
		$blog_id = 0;

		if ( empty( $plugin_instance ) ) {
			$plugin_instance = &$this->wpmdbpro;
		}

		$this->state_data = $plugin_instance->set_post_data();

		if ( ! empty( $this->state_data['form_data'] ) ) {
			$this->form_data = $this->parse_migration_form_data( $this->state_data['form_data'] );

			$select_subsite   = $this->profile_value( 'mst_select_subsite' );
			$selected_subsite = $this->profile_value( 'mst_selected_subsite' );

			// TODO: Remove backwards compatibility for CLI once Core/MST/CLI dependencies updated.
			if ( empty( $select_subsite ) && empty( $selected_subsite ) ) {
				$select_subsite   = $this->profile_value( 'multisite_subsite_export' );
				$selected_subsite = $this->profile_value( 'select_subsite' );
			}

			// During a migration, this is where the subsite's id will be derived.
			if ( empty( $blog_id ) &&
			     ! empty( $select_subsite ) &&
			     ! empty( $selected_subsite ) &&
			     is_numeric( $selected_subsite )
			) {
				$blog_id = $selected_subsite;
			}
		}

		// When loading a saved migration profile, this is where the subsite's id will be derived.
		global $loaded_profile;
		if ( empty( $blog_id ) &&
		     ! empty( $loaded_profile['mst_select_subsite'] ) &&
		     ! empty( $loaded_profile['mst_selected_subsite'] ) &&
		     is_numeric( $loaded_profile['mst_selected_subsite'] )
		) {
			$blog_id = $loaded_profile['mst_selected_subsite'];
		}

		// If on multisite we can check that selected blog exists as all scenarios would require it.
		if ( 1 < $blog_id && is_multisite() && ! $this->subsite_exists( $blog_id ) ) {
			$blog_id = 0;
		}

		return $blog_id;
	}

	/**
	 * Adds the multisite tools settings to the migration setting page in core.
	 */
	public function migration_form_controls() {
		$this->template( 'migrate' );
	}

	/**
	 * Whitelist multisite tools setting fields for use in AJAX save in core
	 *
	 * @param array $profile_fields
	 *
	 * @return array
	 */
	public function accepted_profile_fields( $profile_fields ) {
		return array_merge( $profile_fields, $this->accepted_fields );
	}

	/**
	 * Check the remote site has the multisite tools addon setup
	 *
	 * @param array $data Connection data
	 *
	 * @return array Updated connection data
	 */
	public function establish_remote_connection_data( $data ) {
		$data['mst_available'] = '1';
		$data['mst_version']   = $this->plugin_version;

		return $data;
	}

	/**
	 * Add multisite tools related javascript variables to the page
	 *
	 * @param array $data
	 *
	 * @return array
	 */
	public function js_variables( $data ) {
		$data['mst_version'] = $this->plugin_version;

		return $data;
	}

	/**
	 * Get translated strings for javascript and other functions.
	 *
	 * @return array
	 */
	public function get_strings() {
		static $strings;

		if ( ! empty( $strings ) ) {
			return $strings;
		}

		$strings = array(
			'migration_failed'        => __( 'Migration failed', 'wp-migrate-db-pro-multisite-tools' ),
			'please_select_a_subsite' => __( 'Please select a subsite.', 'wp-migrate-db-pro-multisite-tools' ),
			'please_enter_a_prefix'   => __( 'Please enter a new table prefix.', 'wp-migrate-db-pro-multisite-tools' ),
			'new_prefix_contents'     => __( 'Please only enter letters, numbers or underscores for the new table prefix.', 'wp-migrate-db-pro-multisite-tools' ),
			'export_subsite_option'   => __( 'Export a subsite as a single site install', 'wp-migrate-db-pro-multisite-tools' ),
			'pull_subsite_option'     => __( 'Pull into a specific subsite', 'wp-migrate-db-pro-multisite-tools' ),
			'push_subsite_option'     => __( 'Push a specific subsite', 'wp-migrate-db-pro-multisite-tools' ),
		);

		return $strings;
	}

	/**
	 * Retrieve a specific translated string.
	 *
	 * @param string $key
	 *
	 * @return string
	 */
	public function get_string( $key ) {
		$strings = $this->get_strings();

		return ( isset( $strings[ $key ] ) ) ? $strings[ $key ] : '';
	}

	/**
	 * Load multisite tools related assets in core plugin.
	 */
	public function load_assets() {
		$plugins_url = trailingslashit( plugins_url() ) . trailingslashit( $this->plugin_folder_name );
		$version     = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? time() : $this->plugin_version;
		$ver_string  = '-' . str_replace( '.', '', $this->plugin_version );
		$min         = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		$src = $plugins_url . 'asset/dist/css/styles.css';
		wp_enqueue_style( 'wp-migrate-db-pro-multisite-tools-styles', $src, array( 'wp-migrate-db-pro-styles' ), $version );

		$src = $plugins_url . "asset/dist/js/script{$ver_string}{$min}.js";
		wp_enqueue_script( 'wp-migrate-db-pro-multisite-tools-script',
			$src,
			array(
				'jquery',
				'wp-migrate-db-pro-common',
				'wp-migrate-db-pro-hook',
				'wp-migrate-db-pro-script',
			),
			$version,
			true );

		wp_localize_script( 'wp-migrate-db-pro-multisite-tools-script', 'wpmdbmst_strings', $this->get_strings() );
	}

	/**
	 * Adds extra information to the core plugin's diagnostic info
	 */
	public function diagnostic_info() {
		if ( is_multisite() ) {
			echo 'Sites: ';
			echo number_format( get_blog_count() );
			echo "\r\n";
		}
	}

	/**
	 * Should the given table be excluded from a subsite migration.
	 *
	 * @param bool   $exclude
	 * @param string $table_name
	 *
	 * @return bool
	 */
	public function filter_table_for_subsite( $exclude, $table_name ) {
		if ( ! is_multisite() ) {
			return $exclude;
		}

		$blog_id = $this->selected_subsite();

		if ( 0 < $blog_id ) {
			// wp_users and wp_usermeta are relevant to all sites, shortcut out.
			if ( $this->wpmdbpro->table_is( '', $table_name, 'non_ms_global' ) ) {
				return $exclude;
			}

			// Following tables are Multisite setup tables and can be excluded from migration.
			if ( $this->wpmdbpro->table_is( '', $table_name, 'ms_global' ) ) {
				return true;
			}

			global $wpdb;
			$prefix         = $wpdb->base_prefix;
			$prefix_escaped = preg_quote( $prefix );

			if ( 1 == $blog_id ) {
				// Exclude tables from non-primary subsites.
				if ( preg_match( '/^' . $prefix_escaped . '([0-9]+)_/', $table_name, $matches ) ) {
					$exclude = true;
				}
			} else {
				$prefix .= $blog_id . '_';
				if ( 0 !== stripos( $table_name, $prefix ) ) {
					$exclude = true;
				}
			}
		}

		return $exclude;
	}

	/**
	 * Filter the given tables if doing a subsite migration.
	 *
	 * @param array  $tables
	 * @param string $scope
	 *
	 * @return array
	 */
	public function filter_tables_for_subsite( $tables, $scope = 'regular' ) {
		if ( ! is_multisite() || empty( $tables ) ) {
			return $tables;
		}

		// We will not alter backup or temp tables list.
		if ( in_array( $scope, array( 'backup', 'temp' ) ) ) {
			return $tables;
		}

		$filtered_tables = array();
		$blog_id         = $this->selected_subsite();

		if ( 0 < $blog_id ) {
			foreach ( $tables as $key => $value ) {
				if ( false === $this->filter_table_for_subsite( false, $value ) ) {
					$filtered_tables[ $key ] = $value;
				}
			}
		} else {
			$filtered_tables = $tables;
		}

		return $filtered_tables;
	}

	/**
	 * Filter the given tables with sizes if doing a subsite migration.
	 *
	 * @param array  $table_sizes
	 * @param string $scope
	 *
	 * @return array
	 */
	public function filter_table_sizes_for_subsite( $table_sizes, $scope = 'regular' ) {
		if ( ! is_multisite() || empty( $table_sizes ) ) {
			return $table_sizes;
		}

		$tables = $this->filter_tables_for_subsite( array_keys( $table_sizes ), $scope );

		return array_intersect_key( $table_sizes, array_flip( $tables ) );
	}

	/**
	 * Change the name of the given table if subsite selected and migration profile has new prefix.
	 *
	 * @param string $table_name
	 * @param string $action
	 * @param string $stage
	 * @param array  $site_details
	 *
	 * @return string
	 */
	public function filter_target_table_name( $table_name, $action, $stage, $site_details = array() ) {
		$blog_id = $this->selected_subsite();

		if ( 1 > $blog_id || 'backup' == $stage ) {
			return $table_name;
		}

		$new_prefix = $this->wpmdbpro->profile_value( 'new_prefix' );

		if ( empty( $new_prefix ) ) {
			return $table_name;
		}

		global $wpdb;
		$old_prefix = $wpdb->base_prefix;
		if ( is_multisite() && 1 < $blog_id && ! $this->wpmdbpro->table_is( '', $table_name, 'global', '', $blog_id ) ) {
			$old_prefix .= $blog_id . '_';
		}

		// We do not want to overwrite the global tables unless exporting or target is a single site install.
		if ( 'savefile' !== $action &&
		     (
			     ( 'pull' === $action && 'true' === $site_details['local']['is_multisite'] ) ||
			     ( 'push' === $action && 'true' === $site_details['remote']['is_multisite'] )
		     ) &&
		     $this->wpmdbpro->table_is( '', $table_name, 'global' )
		) {
			$new_prefix .= 'wpmdbglobal_';
		}

		if ( 0 === stripos( $table_name, $old_prefix ) ) {
			$table_name = substr_replace( $table_name, $new_prefix, 0, strlen( $old_prefix ) );
		}

		return $table_name;
	}

	/**
	 * Handler for the wpmdb_table_row filter.
	 * The given $row can be modified, but if we return false the row will not be used.
	 *
	 * @param stdClass $row
	 * @param string   $table_name
	 * @param string   $action
	 * @param string   $stage
	 *
	 * @return bool
	 */
	public function filter_table_row( $row, $table_name, $action, $stage ) {
		$use     = true;
		$blog_id = $this->selected_subsite();

		if ( 1 > $blog_id || 'backup' == $stage ) {
			return $use;
		}

		$new_prefix = $this->wpmdbpro->profile_value( 'new_prefix' );

		if ( empty( $new_prefix ) ) {
			return $row;
		}

		global $wpdb;

		$old_prefix = $wpdb->base_prefix;
		if ( is_multisite() && 1 < $blog_id ) {
			$old_prefix .= $blog_id . '_';
		}

		if ( $this->wpmdbpro->table_is( 'options', $table_name ) ) {
			// Rename options records like wp_X_user_roles to wp_Y_user_roles otherwise no users can do anything in the migrated site.
			if ( 0 === stripos( $row->option_name, $old_prefix ) ) {
				$row->option_name = substr_replace( $row->option_name, $new_prefix, 0, strlen( $old_prefix ) );
			}
		}

		if ( $this->wpmdbpro->table_is( 'usermeta', $table_name ) ) {
			if ( ! $this->is_user_required_for_blog( $row->user_id, $blog_id ) ) {
				$use = false;
			} elseif ( 1 == $blog_id ) {
				$prefix_escaped = preg_quote( $wpdb->base_prefix );
				if ( 1 === preg_match( '/^' . $prefix_escaped . '([0-9]+)_/', $row->meta_key, $matches ) ) {
					// Remove non-primary subsite records from usermeta when migrating primary subsite.
					$use = false;
				} elseif ( 0 === stripos( $row->meta_key, $old_prefix ) ) {
					// Rename prefixed keys.
					$row->meta_key = substr_replace( $row->meta_key, $new_prefix, 0, strlen( $old_prefix ) );
				}
			} else {
				if ( 0 === stripos( $row->meta_key, $old_prefix ) ) {
					// Rename prefixed keys.
					$row->meta_key = substr_replace( $row->meta_key, $new_prefix, 0, strlen( $old_prefix ) );
				} elseif ( 0 === stripos( $row->meta_key, $wpdb->base_prefix ) ) {
					// Remove wp_* records from usermeta not for extracted subsite.
					$use = false;
				}
			}
		}

		if ( $this->wpmdbpro->table_is( 'users', $table_name ) ) {
			if ( ! $this->is_user_required_for_blog( $row->ID, $blog_id ) ) {
				$use = false;
			}
		}

		return $use;
	}

	/**
	 * Handler for the wpmdb_find_and_replace filter.
	 *
	 * @param array  $tmp_find_replace_pairs
	 * @param string $intent
	 * @param string $site_url
	 *
	 * @return array
	 */
	public function filter_find_and_replace( $tmp_find_replace_pairs, $intent, $site_url ) {
		// TODO: Remove this condition when MST usable from single site install.
		if ( ! is_multisite() ) {
			return $tmp_find_replace_pairs;
		}

		$blog_id = $this->selected_subsite();

		if ( 1 > $blog_id ) {
			return $tmp_find_replace_pairs;
		}

		$source = ( 'pull' === $intent ) ? 'remote' : 'local';
		$target = ( 'pull' === $intent ) ? 'local' : 'remote';

		if ( 'true' === $this->state_data['site_details'][ $source ]['is_multisite'] ) {
			$source_site_url        = $this->state_data['site_details'][ $source ]['subsites_info'][ $blog_id ]['site_url'];
			$source_uploads_baseurl = $this->state_data['site_details'][ $source ]['subsites_info'][ $blog_id ]['uploads']['baseurl'];
			$source_short_basedir   = $this->state_data['site_details'][ $source ]['subsites_info'][ $blog_id ]['uploads']['short_basedir'];
		} else {
			$source_site_url        = $this->state_data['site_details'][ $source ]['site_url'];
			$source_uploads_baseurl = $this->state_data['site_details'][ $source ]['uploads']['baseurl'];
			$source_short_basedir   = '';
		}
		$source_site_url        = '//' . untrailingslashit( $this->scheme_less_url( $source_site_url ) );
		$source_uploads_baseurl = '//' . untrailingslashit( $this->scheme_less_url( $source_uploads_baseurl ) );

		if ( 'savefile' === $intent ) {
			$target_site_url        = '';
			$target_uploads_baseurl = '';
			$target_short_basedir   = '';

			foreach ( $tmp_find_replace_pairs as $find => $replace ) {
				if ( $find == $source_site_url ) {
					$target_site_url = $replace;
					break;
				}
			}

			// Append extra path elements from uploads url, removing unneeded subsite specific elements.
			if ( ! empty( $target_site_url ) ) {
				$target_uploads_baseurl = $target_site_url . substr( $source_uploads_baseurl, strlen( $source_site_url ) );

				if ( ! empty( $source_short_basedir ) ) {
					$target_uploads_baseurl = substr( untrailingslashit( $target_uploads_baseurl ), 0, -strlen( untrailingslashit( $source_short_basedir ) ) );
				}
			}
		} elseif ( 'true' === $this->state_data['site_details'][ $target ]['is_multisite'] ) {
			$target_site_url        = $this->state_data['site_details'][ $target ]['subsites_info'][ $blog_id ]['site_url'];
			$target_uploads_baseurl = $this->state_data['site_details'][ $target ]['subsites_info'][ $blog_id ]['uploads']['baseurl'];
			$target_short_basedir   = $this->state_data['site_details'][ $target ]['subsites_info'][ $blog_id ]['uploads']['short_basedir'];
		} else {
			$target_site_url        = $this->state_data['site_details'][ $target ]['site_url'];
			$target_uploads_baseurl = $this->state_data['site_details'][ $target ]['uploads']['baseurl'];
			$target_short_basedir   = '';
		}

		// If we have a target uploads url, we can add in the find/replace we need.
		if ( ! empty( $target_uploads_baseurl ) ) {
			$target_site_url        = '//' . untrailingslashit( $this->scheme_less_url( $target_site_url ) );
			$target_uploads_baseurl = '//' . untrailingslashit( $this->scheme_less_url( $target_uploads_baseurl ) );

			// As we're appending to the find/replace rows, we need to use the already replaced values for altering uploads url.
			$old_uploads_url                            = substr_replace( $source_uploads_baseurl, $target_site_url, 0, strlen( $source_site_url ) );
			$tmp_find_replace_pairs[ $old_uploads_url ] = $target_uploads_baseurl;
		}

		return $tmp_find_replace_pairs;
	}

	/**
	 * Change the name of the given table depending on migration profile settings and source and target site setup.
	 *
	 * @param string $table_name
	 * @param string $intent
	 * @param array  $site_details
	 *
	 * @return string
	 *
	 * This is run in response to the wpmdb_finalize_target_table_name filter on the target site.
	 */
	public function filter_finalize_target_table_name( $table_name, $intent, $site_details ) {
		$blog_id = $this->selected_subsite();

		if ( 1 > $blog_id ) {
			return $table_name;
		}

		$new_prefix = $this->wpmdbpro->profile_value( 'new_prefix' );

		if ( empty( $new_prefix ) ) {
			return $table_name;
		}

		// During a MST migration we add a custom prefix to the global tables so that we can manipulate their data before use.
		if ( is_multisite() && $this->wpmdbpro->table_is( '', $table_name, 'global', $new_prefix, $blog_id ) ) {
			$new_prefix .= 'wpmdbglobal_';
		}

		$old_prefix = ( 'pull' === $intent ? $site_details['remote']['prefix'] : $site_details['local']['prefix'] );
		if ( ! is_multisite() && 1 < $blog_id && ! $this->wpmdbpro->table_is( '', $table_name, 'global', $new_prefix, $blog_id ) ) {
			$old_prefix .= $blog_id . '_';
		}

		if ( 0 === stripos( $table_name, $old_prefix ) ) {
			$table_name = substr_replace( $table_name, $new_prefix, 0, strlen( $old_prefix ) );
		}

		return $table_name;
	}

	/**
	 * Returns validated and sanitized form data.
	 *
	 * @param array|string $data
	 *
	 * @return array|string
	 */
	public function parse_migration_form_data( $data ) {
		$form_data = parent::parse_migration_form_data( $data );

		$form_data = array_intersect_key( $form_data, array_flip( $this->accepted_fields ) );

		return $form_data;
	}

	/**
	 * Alter given destination file path depending on local and remote site setup.
	 *
	 * @param string                    $file_path
	 * @param string                    $intent
	 * @param WPMDBPro_Media_Files_Base $wpmdbmf
	 *
	 * @return string
	 */
	public function filter_mf_destination_file_path( $file_path, $intent, $wpmdbmf ) {
		$blog_id = $this->selected_subsite( $wpmdbmf );

		if ( 1 > $blog_id ) {
			return $file_path;
		}

		if ( ! is_multisite() && 'push' === $intent && ! empty( $this->state_data['site_details']['local']['subsites_info'][ $blog_id ]['uploads']['short_basedir'] ) ) {
			$file_path = substr_replace( $file_path, '', 0, strlen( $this->state_data['site_details']['local']['subsites_info'][ $blog_id ]['uploads']['short_basedir'] ) );
		}

		return $file_path;
	}

	/**
	 * Alter given destination file path depending on local and remote site setup.
	 *
	 * @param string                    $file
	 * @param string                    $intent
	 * @param WPMDBPro_Media_Files_Base $wpmdbmf
	 *
	 * @return string
	 */
	public function filter_mf_file_not_on_local( $file, $intent, $wpmdbmf ) {
		$blog_id = $this->selected_subsite( $wpmdbmf );

		if ( 1 > $blog_id ) {
			return $file;
		}

		if ( is_multisite() && 'push' === $intent &&
		     $this->state_data['site_details']['local']['is_multisite'] !== $this->state_data['site_details']['remote']['is_multisite'] &&
		     ! empty( $this->state_data['site_details']['local']['subsites_info'][ $blog_id ]['uploads']['short_basedir'] )
		) {
			$file = $this->state_data['site_details']['local']['subsites_info'][ $blog_id ]['uploads']['short_basedir'] . $file;
		} elseif ( ! is_multisite() && 'pull' === $intent &&
		           $this->state_data['site_details']['local']['is_multisite'] !== $this->state_data['site_details']['remote']['is_multisite'] &&
		           ! empty( $this->state_data['site_details']['local']['subsites_info'][ $blog_id ]['uploads']['short_basedir'] )
		) {
			$file = substr( $file, strlen( $this->state_data['site_details']['local']['subsites_info'][ $blog_id ]['uploads']['short_basedir'] ) );
		}

		return $file;
	}

	/**
	 * Alter given source file path depending on local and remote site setup.
	 *
	 * @param array                     $response
	 * @param string                    $intent
	 * @param WPMDBPro_Media_Files_Base $wpmdbmf
	 *
	 * @return string
	 */
	public function filter_mf_get_remote_attachment_batch_response( $response, $intent, $wpmdbmf ) {
		$blog_id = $this->selected_subsite( $wpmdbmf );

		if ( 1 > $blog_id ) {
			return $response;
		}

		if ( is_multisite() && 'pull' === $intent &&
		     $this->state_data['site_details']['local']['is_multisite'] !== $this->state_data['site_details']['remote']['is_multisite']
		) {
			$remote_attachments = unserialize( stripslashes( $response['remote_attachments'] ) );

			if ( ! empty( $remote_attachments[1] ) ) {
				foreach ( $remote_attachments[1] as $index => $attachment ) {
					$attachment['blog_id'] = $blog_id;
					$attachment['file']    = ltrim( trailingslashit( $this->state_data['site_details']['local']['subsites_info'][ $blog_id ]['uploads']['short_basedir'] ) . $attachment['file'], '/' );

					if ( ! empty( $attachment['sizes'] ) ) {
						foreach ( $attachment['sizes'] as $size_idx => $size ) {
							$attachment['sizes'][ $size_idx ]['file'] = ltrim( trailingslashit( $this->state_data['site_details']['local']['subsites_info'][ $blog_id ]['uploads']['short_basedir'] ) . $size['file'], '/' );
						}
					}

					$remote_attachments[1][ $index ] = $attachment;
				}
			}
			$response['remote_attachments'] = addslashes( serialize( $remote_attachments ) );
		}

		return $response;
	}

	/**
	 * Alter given source file path depending on local and remote site setup.
	 *
	 * @param string                    $file
	 * @param string                    $intent
	 * @param WPMDBPro_Media_Files_Base $wpmdbmf
	 *
	 * @return string
	 */
	public function filter_mf_file_to_download( $file, $intent, $wpmdbmf ) {
		$blog_id = $this->selected_subsite( $wpmdbmf );

		if ( 1 > $blog_id ) {
			return $file;
		}

		if ( is_multisite() && 'pull' === $intent &&
		     $this->state_data['site_details']['local']['is_multisite'] !== $this->state_data['site_details']['remote']['is_multisite'] &&
		     ! empty( $this->state_data['site_details']['local']['subsites_info'][ $blog_id ]['uploads']['short_basedir'] )
		) {
			$file = substr( $file, strlen( $this->state_data['site_details']['local']['subsites_info'][ $blog_id ]['uploads']['short_basedir'] ) );
		}

		return $file;
	}

	/**
	 * Should the given file be excluded from removal?
	 *
	 * @param bool                      $value
	 * @param string                    $upload_dir
	 * @param string                    $short_file_path
	 * @param WPMDBPro_Media_Files_Base $wpmdbmf
	 *
	 * @return bool
	 */
	public function filter_mf_exclude_local_media_file_from_removal( $value, $upload_dir, $short_file_path, $wpmdbmf ) {
		// Already excluded, don't override.
		if ( $value ) {
			return $value;
		}

		$blog_id = $this->selected_subsite( $wpmdbmf );

		if ( 1 > $blog_id ) {
			return $value;
		}

		if ( is_multisite() && 'pull' === $this->state_data['intent'] &&
		     ! empty( $this->state_data['site_details']['local']['subsites_info'][ $blog_id ]['uploads']['basedir'] )
		) {
			$file_given  = $upload_dir . $short_file_path;
			$file_munged = trailingslashit( $this->state_data['site_details']['local']['subsites_info'][ $blog_id ]['uploads']['basedir'] ) . substr( $short_file_path, strlen( $this->state_data['site_details']['local']['subsites_info'][ $blog_id ]['uploads']['short_basedir'] ) );

			if ( $file_given !== $file_munged ) {
				$value = true;
			}
		}

		return $value;
	}

	/**
	 * Handler for "wpmdbmf_include_subsite" filter to disallow subsite's media to be migrated if not selected.
	 *
	 * @param bool                      $value
	 * @param int                       $blog_id
	 * @param WPMDBPro_Media_Files_Base $wpmdbmf
	 *
	 * @return bool
	 */
	public function include_subsite( $value, $blog_id, $wpmdbmf ) {
		$selected_blog_id = $this->selected_subsite( $wpmdbmf );

		if ( 1 > $selected_blog_id ) {
			return $value;
		}

		if ( $blog_id !== $selected_blog_id ) {
			$value = false;
		}

		return $value;
	}

	/**
	 * Maybe change options keys to be preserved.
	 *
	 * @param array  $preserved_options
	 * @param string $intent
	 *
	 * @return array
	 */
	public function filter_preserved_options( $preserved_options, $intent = '' ) {
		$blog_id = $this->selected_subsite();

		if ( 1 > $blog_id || 'push' !== $intent ) {
			return $preserved_options;
		}

		$keep_active_plugins = $this->profile_value( 'keep_active_plugins' );

		if ( empty( $keep_active_plugins ) ) {
			$preserved_options[] = 'active_plugins';
		}

		return $preserved_options;
	}

	/**
	 * Maybe preserve the WPMDB plugins if they aren't already preserved.
	 *
	 * @param array  $preserved_options_data
	 * @param string $intent
	 *
	 * @return array
	 */
	public function filter_preserved_options_data( $preserved_options_data, $intent = '' ) {
		$blog_id = $this->selected_subsite();

		$keep_active_plugins = $this->profile_value( 'keep_active_plugins' );

		if ( 1 > $blog_id || 'push' !== $intent || ! empty( $keep_active_plugins ) ) {
			return $preserved_options_data;
		}

		if ( ! empty( $preserved_options_data ) ) {
			foreach ( $preserved_options_data as $table => $data ) {
				foreach ( $data as $key => $option ) {
					if ( 'active_plugins' === $option['option_name'] ) {
						global $wpdb;

						$table_name       = esc_sql( $table );
						$option_value     = unserialize( $option['option_value'] );
						$migrated_plugins = array();
						$wpmdb_plugins    = array();

						if ( $result = $wpdb->get_var( "SELECT option_value FROM $table_name WHERE option_name = 'active_plugins'" )  ) {
							$unserialized = unserialize( $result );
							if ( is_array( $unserialized ) ) {
								$migrated_plugins = $unserialized;
							}
						}

						foreach ( $option_value as $plugin_key => $plugin ) {
							if ( 0 === strpos( $plugin, 'wp-migrate-db' ) ) {
								$wpmdb_plugins[] = $plugin;
							}
						}

						$merged_plugins                           = array_unique( array_merge( $wpmdb_plugins, $migrated_plugins ) );
						$option['option_value']                   = serialize( $merged_plugins );
						$preserved_options_data[ $table ][ $key ] = $option;
						break;
					}
				}
			}
		}

		return $preserved_options_data;
	}

	/**
	 * Append more queries to be run at finalize_migration.
	 *
	 * @param array $queries
	 *
	 * @return array
	 */
	public function filter_get_alter_queries( $queries ) {
		$blog_id = $this->selected_subsite();

		if ( 1 > $blog_id ) {
			return $queries;
		}

		if ( is_multisite() && 'pull' === $this->state_data['intent'] && ! empty( $this->state_data['tables'] ) ) {
			global $wpdb;

			$tables = explode( ',', $this->state_data['tables'] );

			$target_users_table    = null;
			$source_users_table    = null;
			$target_usermeta_table = null;
			$source_usermeta_table = null;
			$posts_imported        = false;
			$target_posts_table    = null;
			$comments_imported     = false;
			$target_comments_table = null;
			foreach ( $tables as $table ) {
				if ( empty( $source_users_table ) && $this->wpmdbpro->table_is( 'users', $table ) ) {
					$target_users_table = $table;
					$source_users_table = $this->filter_finalize_target_table_name( $table, $this->state_data['intent'], $this->state_data['site_details'] );
					continue;
				}
				if ( empty( $source_usermeta_table ) && $this->wpmdbpro->table_is( 'usermeta', $table ) ) {
					$target_usermeta_table = $table;
					$source_usermeta_table = $this->filter_finalize_target_table_name( $table, $this->state_data['intent'], $this->state_data['site_details'] );
					continue;
				}
				if ( ! $posts_imported && $this->wpmdbpro->table_is( 'posts', $table ) ) {
					$posts_imported     = true;
					$target_posts_table = $this->filter_finalize_target_table_name( $table, $this->state_data['intent'], $this->state_data['site_details'] );
					continue;
				}
				if ( ! $comments_imported && $this->wpmdbpro->table_is( 'comments', $table ) ) {
					$comments_imported     = true;
					$target_comments_table = $this->filter_finalize_target_table_name( $table, $this->state_data['intent'], $this->state_data['site_details'] );
					continue;
				}
			}

			// Find users that already exist and update their content to adopt existing user id and remove from import.
			if ( ! empty( $source_users_table ) ) {
				$updated_user_ids        = array();
				$temp_prefix             = $this->state_data['temp_prefix'];
				$temp_source_users_table = $temp_prefix . $source_users_table;

				$sql = "
					SELECT source.id AS source_id, target.id AS target_id FROM `{$temp_source_users_table}` AS source, `{$target_users_table}` AS target
					WHERE target.user_login = source.user_login
					AND target.user_email = source.user_email
				";

				$user_ids_to_update = $wpdb->get_results( $sql, ARRAY_A );

				if ( ! empty( $user_ids_to_update ) ) {
					foreach ( $user_ids_to_update as $user_ids ) {
						$blogs_of_user = get_blogs_of_user( $user_ids['target_id'] );

						if ( empty( $blogs_of_user ) || array_key_exists( $blog_id, $blogs_of_user ) ) {
							// Only update content ownership if user id has changed.
							if ( $user_ids['source_id'] !== $user_ids['target_id'] ) {
								if ( $posts_imported ) {
									$queries[]['query'] = "
									UPDATE `{$target_posts_table}`
									SET post_author = {$user_ids['target_id']}
									WHERE post_author = {$user_ids['source_id']}
									;\n
								";
								}

								if ( $comments_imported ) {
									$queries[]['query'] = "
									UPDATE `{$target_comments_table}`
									SET user_id = {$user_ids['target_id']}
									WHERE user_id = {$user_ids['source_id']}
									;\n
								";
								}
							}

							// Log user for exclusion from import.
							$updated_user_ids[] = $user_ids['source_id'];
						}
					}
				}

				$queries[]['query'] = "ALTER TABLE `{$target_users_table}` ADD COLUMN wpmdb_user_id BIGINT(20) UNSIGNED;\n";

				$where = '';
				if ( ! empty( $updated_user_ids ) ) {
					$where = 'WHERE u2.id NOT IN (' . join( ',', $updated_user_ids ) . ')';
				}
				$queries[]['query'] = "INSERT INTO `{$target_users_table}` (user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name, wpmdb_user_id)
					SELECT u2.user_login, u2.user_pass, u2.user_nicename, u2.user_email, u2.user_url, u2.user_registered, u2.user_activation_key, u2.user_status, u2.display_name, u2.id
					FROM `{$source_users_table}` AS u2
					{$where};\n";

				if ( ! empty( $source_usermeta_table ) ) {
					$queries[]['query'] = "INSERT INTO `{$target_usermeta_table}` (user_id, meta_key, meta_value)
						SELECT u.id, m2.meta_key, m2.meta_value
						FROM `{$source_usermeta_table}` AS m2
						JOIN `{$target_users_table}` AS u ON m2.user_id = u.wpmdb_user_id;\n";
				}

				if ( $posts_imported ) {
					$queries[]['query'] = "
						UPDATE `{$target_posts_table}` AS p, `{$target_users_table}` AS u
						SET p.post_author = u.id
						WHERE p.post_author = u.wpmdb_user_id
						;\n";
				}

				if ( $comments_imported ) {
					$queries[]['query'] = "
						UPDATE `{$target_comments_table}` AS c, `{$target_users_table}` AS u
						SET c.user_id = u.id
						WHERE c.user_id = u.wpmdb_user_id
						;\n";
				}
				$queries[]['query'] = "DROP TABLE `{$source_users_table}`;\n";

				$queries[]['query'] = "ALTER TABLE `{$target_users_table}` DROP COLUMN wpmdb_user_id;\n";
			}

			// Cleanup imported usermeta table, whether used by above user related queries or not.
			// TODO: Maybe support updating usermeta without imported users table?
			if ( ! empty( $source_usermeta_table ) ) {
				$queries[]['query'] = "DROP TABLE `{$source_usermeta_table}`;\n";
			}
		}

		return $queries;
	}

	/**
	 * Does the passed subsite (ID) exist?
	 *
	 * @param int $blog_id
	 *
	 * @return bool
	 */
	public function subsite_exists( $blog_id ) {
		if ( ! is_multisite() ) {
			return false;
		}

		$blogs = wp_get_sites( array( 'limit' => 0 ) );

		if ( empty( $blogs ) ) {
			return false;
		}

		foreach ( $blogs as $blog ) {
			if ( ! empty( $blog['blog_id'] ) && $blog_id == $blog['blog_id'] ) {
				return true;
			}
		}

		return false;
	}
}
