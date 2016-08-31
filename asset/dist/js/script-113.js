var wpmdb = wpmdb || {};
wpmdb.mst = {
	remote_mst_unavailable: false
};

(function( $, wpmdb ) {
	var $mst_options = $( '.mst-options' );
	var $mst_select_subsite = $( '#mst-select-subsite' );
	var $mst_options_content = $( '.mst-options .expandable-content' );
	var $mst_selected_subsite = $( '#mst-selected-subsite' );
	var $mst_new_prefix_field = $( '.new-prefix-field' );
	var $mst_new_prefix = $( '#new-prefix' );
	var $mst_new_prefix_hidden = $( '#new-prefix-hidden' );
	var $mst_new_prefix_readonly = $( '#new-prefix-readonly' );
	var $mst_unavailable = $( '.mst-unavailable' );
	var $mst_different_plugin_version_notice = $( '.mst-different-plugin-version-notice' );
	var $mst_different_prefix_notice = $( '.mst-different-prefix-notice' );

	var finished_loading = false;
	var original_local_url = null;
	var reverse_replace = false;
	var table_prefix = $( '.table-select-wrap .table-prefix' ).text();

	function doing_mst_select_subsite() {
		return '1' === $mst_select_subsite.attr( 'data-available' ) && $mst_select_subsite.is( ':checked' ) ? true : false;
	}

	function disable_mst_options() {
		$.wpmdb.do_action( 'wpmdb_lock_replace_url', false );
		$.wpmdb.do_action( 'wpmdb_enable_table_migration_options' );
		$mst_select_subsite.attr( 'data-available', '0' );
		$mst_select_subsite.prop( 'checked', false );
		$mst_select_subsite.attr( 'disabled', 'disabled' );
		$( '.mst' ).addClass( 'disabled' );
		$mst_options_content.hide();
	}

	function enable_mst_options() {
		$mst_select_subsite.attr( 'data-available', '1' );
		$mst_select_subsite.removeAttr( 'disabled' );
		$( '.mst' ).removeClass( 'disabled' );
	}

	function hide_show_options( unavailable ) {

		// MST options can only be used from a multisite install at present.
		// TODO: Remove this test if MST is to be usable from a single site install.
		if ( 'false' === wpmdb_data.site_details.is_multisite ) {
			disable_mst_options();
			$mst_options.hide();
			return;
		}

		// For Pull/Push remote should be a single site install.
		// TODO: Remove this test if MST is to manage subsite to subsite.
		if ( 'savefile' !== wpmdb_migration_type() &&
			'undefined' !== typeof wpmdb.mst.remote_connection_data &&
			'true' === wpmdb.mst.remote_connection_data.site_details.is_multisite ) {
			disable_mst_options();
			$mst_options.hide();
			return;
		}

		// For Pull/Push remote should have same base prefix.
		// TODO: Allow migrating to different base prefix.
		if ( 'savefile' !== wpmdb_migration_type() &&
			false === unavailable &&
			'undefined' !== typeof wpmdb.mst.remote_connection_data &&
			'undefined' !== typeof wpmdb.mst.remote_connection_data.site_details &&
			wpmdb_data.site_details.prefix !== wpmdb.mst.remote_connection_data.site_details.prefix ) {
			disable_mst_options();
			maybe_update_local_url_for_subsite( false );
			$( '.mst-remote-location' ).html( wpmdb.mst.remote_connection_data.url );
			$( '.mst-remote-prefix' ).html( wpmdb.mst.remote_connection_data.site_details.prefix );
			$mst_different_prefix_notice.show();
			return;
		}
		$mst_different_prefix_notice.hide();

		// For Pull/Push remote also needs MST.
		if ( unavailable && 'true' === wpmdb_data.site_details.is_multisite && 'savefile' !== wpmdb_migration_type() ) {
			disable_mst_options();
			maybe_update_local_url_for_subsite( false );
			$mst_unavailable.show();
			return;
		}
		$mst_unavailable.hide();

		if ( 'savefile' !== wpmdb_migration_type() &&
			'undefined' !== typeof wpmdb.mst.remote_connection_data &&
			wpmdb_data.mst_version !== wpmdb.mst.remote_connection_data.mst_version ) {
			disable_mst_options();
			maybe_update_local_url_for_subsite( false );
			$( '.mst-remote-location' ).html( wpmdb.mst.remote_connection_data.url );
			$( '.mst-remote-version' ).html( wpmdb.mst.remote_connection_data.mst_version );
			$mst_different_plugin_version_notice.show();
			return;
		}
		$mst_different_plugin_version_notice.hide();

		enable_mst_options();
		if ( doing_mst_select_subsite() ) {
			$mst_options_content.show();
			selected_subsite_changed();
		} else {
			$mst_options_content.hide();
		}

		maybe_lock_replace_url();

		$mst_options.show();
	}

	function maybe_lock_replace_url() {
		if ( doing_mst_select_subsite() && 'savefile' !== wpmdb_migration_type() ) {
			$.wpmdb.do_action( 'wpmdb_lock_replace_url', true );
		} else {
			$.wpmdb.do_action( 'wpmdb_lock_replace_url', false );
		}
	}

	function hide_show_new_prefix_field() {
		if ( '' === $mst_selected_subsite.val() ) {
			$mst_new_prefix_field.hide();
			return;
		}

		// If not an Export, we know what the new table prefix should be.
		if ( 'savefile' !== wpmdb_migration_type() ) {
			$mst_new_prefix.hide();
			$mst_new_prefix_readonly.show();
			$mst_new_prefix_field.children( 'label' ).addClass( 'disabled' );

			var new_prefix = table_prefix;
			var selected_subsite = get_selected_subsite();

			if ( 'pull' === wpmdb_migration_type() ) {
				if ( undefined !== selected_subsite.blog_id && 1 < selected_subsite.blog_id ) {
					new_prefix = new_prefix + selected_subsite.blog_id + '_';
				}
			} else {

				// TODO: Determine new table prefix if target has different base or is not single site install.
			}
			$mst_new_prefix.val( new_prefix );
			$mst_new_prefix_hidden.val( new_prefix );
			$mst_new_prefix_readonly.text( new_prefix );
		} else {
			$mst_new_prefix.show();
			$mst_new_prefix_readonly.hide();
			$mst_new_prefix_field.children( 'label' ).removeClass( 'disabled' );
		}
		$mst_new_prefix_field.show();
	}

	function select_subsite_changed() {
		var args = doing_mst_select_subsite();

		maybe_lock_replace_url();

		$.wpmdb.do_action( 'wpmdbmst_select_subsite_changed', args );
	}

	function get_selected_subsite() {
		var details = {};
		if ( doing_mst_select_subsite() ) {
			var blog_id = $mst_selected_subsite.find( 'option:selected' ).val();

			if ( '' === blog_id ) {
				details = false;
			} else {
				details.blog_id = blog_id;
				details.domain_and_path = $mst_selected_subsite.find( 'option:selected' ).text();
			}
		}

		return details;
	}

	function selected_subsite_changed() {
		var selected_subsite = get_selected_subsite();
		$.wpmdb.do_action( 'wpmdbmst_selected_subsite_changed', selected_subsite );
	}

	var subsite_for_tables = false;

	function update_table_selects( selected_subsite ) {

		// Force table select lists to be refreshed (and filtered again).
		$.wpmdb.do_action( 'wpmdb_refresh_table_selects' );

		if ( 'pull' === wpmdb_migration_type() ) {
			$.wpmdb.do_action( 'wpmdb_update_pull_table_select' );
		} else {
			$.wpmdb.do_action( 'wpmdb_update_push_table_select' );
		}

		// We may need to enable or disable the ability to select the "Migrate all tables with prefix ..." option.
		if ( doing_mst_select_subsite() ) {
			$.wpmdb.do_action( 'wpmdb_disable_table_migration_options' );

			// When switching subsites select all the tables unless still loading saved profile.
			if ( finished_loading &&
				(
					false === subsite_for_tables ||
					( undefined !== subsite_for_tables.blog_id && undefined !== selected_subsite.blog_id && subsite_for_tables.blog_id !== selected_subsite.blog_id )
				)
			) {
				$.wpmdb.do_action( 'wpmdb_select_all_tables' );
			}
		} else {
			$.wpmdb.do_action( 'wpmdb_enable_table_migration_options' );
		}

		subsite_for_tables = selected_subsite;
	}

	function select_subsite_tables_on_change_action( data ) {
		if ( undefined !== wpmdb.mst.remote_connection_data &&
			'true' !== wpmdb.mst.remote_connection_data.site_details.is_multisite &&
			doing_mst_select_subsite() &&
			data.last_migration_type !== data.migration_type
		) {

			// Timeout required otherwise wpmdb_update_push/pull_table_select action runs after this
			setTimeout( function() {
				$.wpmdb.do_action( 'wpmdb_select_all_tables' );
			} );
		}
	}

	function maybe_update_local_url_for_subsite( selected_subsite ) {
		var new_local_url = original_local_url;

		if ( undefined === selected_subsite ) {
			return;
		} else if ( undefined !== selected_subsite.domain_and_path ) {
			new_local_url = '//' + selected_subsite.domain_and_path;
		}

		var replace_right = false;
		if ( 'pull' === wpmdb_migration_type() ) {
			replace_right = true;
		}

		if ( reverse_replace ) {
			replace_right = !replace_right;
		}

		if ( replace_right ) {
			$( '.replace-row.pin .replace-right-col input[type="text"]' ).val( new_local_url );
		} else {
			$( '.replace-row.pin .old-replace-col input[type="text"]' ).val( new_local_url );
		}
	}

	function is_subsite_table( table_prefix, table_name ) {
		var selected_subsite = get_selected_subsite();

		if ( undefined !== selected_subsite.blog_id ) {
			if ( 1 < selected_subsite.blog_id ) {
				table_prefix = table_prefix + selected_subsite.blog_id + '_';
				var pos = table_name.toLowerCase().indexOf( table_prefix.toLowerCase() );

				if ( 0 === pos ) {
					return true;
				}
			} else {
				var escaped_table_name = wpmdb.preg_quote( table_name );
				var regex = new RegExp( table_prefix + '([0-9]+)_', 'i' );
				var results = regex.exec( escaped_table_name );
				return null == results; // If null is returned, there was no match which is good in this case.
			}
		}

		return false;
	}

	function filter_table_for_subsite( exclude, table_name ) {
		if ( 'false' === wpmdb_data.site_details.is_multisite ) {
			return exclude;
		}

		if ( doing_mst_select_subsite() ) {

			// If there is no subsite selected then we should exclude all tables.
			if ( false === get_selected_subsite() ) {
				return true;
			}

			// If table does not have correct base table prefix for site then exclude from subsite export.
			if ( table_name.substr( 0, table_prefix.length ) !== table_prefix ) {
				return true;
			}

			if ( 1 === wpmdb.subsite_for_table( table_prefix, table_name ) ) {

				// wp_users and wp_usermeta are relevant to all sites, shortcut out.
				if ( wpmdb.table_is( table_prefix, 'users', table_name ) || wpmdb.table_is( table_prefix, 'usermeta', table_name ) ) {
					return exclude;
				}

				// Following tables are Multisite setup tables and can be excluded from migration.
				// We'll handle getting any data we need from these tables elsewhere.
				var ms_tables = [ 'blog_versions', 'blogs', 'registration_log', 'signups', 'site', 'sitemeta' ];

				$.each( ms_tables, function( index, ms_table ) {
					if ( wpmdb.table_is( table_prefix, ms_table, table_name ) ) {
						exclude = true;
					}
				} );
			}

			// If pulling from a single site install, all properly prefixed tables are relevant.
			if ( 'pull' === wpmdb_migration_type() &&
				'undefined' !== typeof wpmdb.mst.remote_connection_data &&
				'true' !== wpmdb.mst.remote_connection_data.site_details.is_multisite ) {
				return exclude;
			}

			if ( false === is_subsite_table( table_prefix, table_name ) ) {
				exclude = true;
			}
		}

		return exclude;
	}

	function validate_new_prefix( new_prefix ) {
		var escaped_new_prefix = wpmdb.preg_quote( new_prefix );

		var regex = new RegExp( '[^a-z0-9_]', 'i' );
		var results = regex.exec( escaped_new_prefix );
		return null == results; // If null is returned there was no match, which is good in this case.
	}

	function filter_migration_profile_ready( value, args ) {
		if ( 'false' === wpmdb_data.site_details.is_multisite || false === doing_mst_select_subsite() ) {
			return value;
		}

		var new_prefix = $mst_new_prefix.val();

		if ( false === get_selected_subsite() ) {
			alert( wpmdbmst_strings.please_select_a_subsite );
			value = false;
		} else if ( 0 === new_prefix.trim().length ) {
			alert( wpmdbmst_strings.please_enter_a_prefix );
			value = false;
		} else if ( false === validate_new_prefix( new_prefix ) ) {
			alert( wpmdbmst_strings.new_prefix_contents );
			value = false;
		}

		return value;
	}

	function filter_backup_selected_tables( selected_tables ) {
		if ( doing_mst_select_subsite() ) {
			var selected_subsite = get_selected_subsite();

			// If dealing with non-primary subsite tables and a single site install, we may need to adjust table names to be backed up.
			if ( undefined !== selected_subsite.blog_id && 1 < selected_subsite.blog_id &&
				( 'false' === wpmdb_data.site_details.is_multisite || 'true' !== wpmdb.mst.remote_connection_data.site_details.is_multisite ) ) {
				$.each( selected_tables, function( index, table_name ) {
					if ( false === wpmdb.table_is( table_prefix, 'users', table_name ) && false === wpmdb.table_is( table_prefix, 'usermeta', table_name ) ) {

						// Table to backup needs subsite prefix?
						if ( false === is_subsite_table( table_prefix, table_name ) && (
								( 'pull' === wpmdb_migration_type() && 'true' === wpmdb_data.site_details.is_multisite ) ||
								( 'push' === wpmdb_migration_type() && 'true' === wpmdb.mst.remote_connection_data.site_details.is_multisite )
							) ) {
							selected_tables[ index ] = $mst_new_prefix.val() + table_name.substr( table_prefix.length );
						}

						// Table to backup needs subsite prefix removed?
						if ( true === is_subsite_table( table_prefix, table_name ) && (
								( 'pull' === wpmdb_migration_type() && 'false' === wpmdb_data.site_details.is_multisite ) ||
								( 'push' === wpmdb_migration_type() && 'true' !== wpmdb.mst.remote_connection_data.site_details.is_multisite )
							) ) {
							selected_tables[ index ] = $mst_new_prefix.val() + table_name.substr( ( table_prefix + selected_subsite.blog_id + '_' ).length );
						}
					}
				} );
			}
		}

		return selected_tables;
	}

	function filter_mf_enable_select_subsites( enable ) {
		if ( false !== enable && doing_mst_select_subsite() ) {
			enable = false;
		}

		return enable;
	}

	function update_remote_connection_data( connection_data ) {
		wpmdb.mst.remote_connection_data = connection_data;
		wpmdb.mst.remote_mst_unavailable = ( 'undefined' === typeof connection_data.mst_available );
	}

	// IMPORTANT: This action fires before find/replace columns are swapped for pull/push.
	$.wpmdb.add_action( 'move_connection_info_box', function( args ) {
		table_prefix = $( '.table-select-wrap .table-prefix' ).text();
		if ( null === original_local_url ) {
			original_local_url = $.wpmdb.apply_filters( 'wpmdb_base_old_url' );
		}

		if ( undefined !== args.migration_type && undefined !== args.last_migration_type ) {
			if ( args.migration_type !== args.last_migration_type && ( 'pull' === args.migration_type || 'pull' === args.last_migration_type ) ) {
				reverse_replace = true;
			}
		}
		wpmdb_toggle_migration_action_text();
		hide_show_options( wpmdb.mst.remote_mst_unavailable );
		$.wpmdb.do_action( 'wpmdb_refresh_table_selects' );
		if ( reverse_replace ) {
			reverse_replace = false;
		}
	} );

	$.wpmdb.add_action( 'verify_connection_to_remote_site', function( connection_data ) {
		update_remote_connection_data( connection_data );
		hide_show_options( wpmdb.mst.remote_mst_unavailable );
	} );

	$.wpmdb.add_action( 'wpmdbmst_select_subsite_changed', selected_subsite_changed );
	$.wpmdb.add_action( 'wpmdbmst_selected_subsite_changed', update_table_selects );
	$.wpmdb.add_action( 'wpmdbmst_selected_subsite_changed', maybe_update_local_url_for_subsite );
	$.wpmdb.add_action( 'wpmdbmst_selected_subsite_changed', hide_show_new_prefix_field );
	$.wpmdb.add_action( 'move_connection_info_box', select_subsite_tables_on_change_action );

	$.wpmdb.add_action( 'wpmdb_connection_data_updated', update_remote_connection_data );

	$.wpmdb.add_filter( 'wpmdb_exclude_table', filter_table_for_subsite );
	$.wpmdb.add_filter( 'wpmdb_migration_profile_ready', filter_migration_profile_ready );
	$.wpmdb.add_filter( 'wpmdb_backup_selected_tables', filter_backup_selected_tables );

	$.wpmdb.add_filter( 'wpmdbmf_enable_select_subsites', filter_mf_enable_select_subsites );

	$( document ).ready( function() {
		$( 'body' ).on( 'change', '#mst-select-subsite', function( e ) {
			select_subsite_changed();
		} );

		$( 'body' ).on( 'change', '#mst-selected-subsite', function( e ) {
			selected_subsite_changed();
		} );

		hide_show_options( wpmdb.mst.remote_mst_unavailable );
		finished_loading = true;
	} );

})( jQuery, wpmdb );
