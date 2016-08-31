<?php
if ( $this->is_valid_licence() ) {
	global $loaded_profile, $wpdb;
	$table_prefix = $wpdb->base_prefix;

	if ( isset( $loaded_profile['multisite_subsite_export'] ) ) {
		$loaded_profile['mst_select_subsite'] = $loaded_profile['multisite_subsite_export'];
	}

	if ( isset( $loaded_profile['select_subsite'] ) ) {
		$loaded_profile['mst_selected_subsite'] = $loaded_profile['select_subsite'];
	}
	?>
	<div class="option-section mst-options">

		<label class="mst checkbox-label" for="mst-select-subsite">
			<input type="checkbox" name="mst_select_subsite" value="1" data-available="1" id="mst-select-subsite"<?php echo( isset( $loaded_profile['mst_select_subsite'] ) ? ' checked="checked"' : '' ); ?> />
			<span class="action-text savefile"><?php echo $this->get_string( 'export_subsite_option' ); ?></span>
			<span class="action-text pull" style="display: none;"><?php echo $this->get_string( 'pull_subsite_option' ); ?></span>
			<span class="action-text push" style="display: none;"><?php echo $this->get_string( 'push_subsite_option' ); ?></span>
		</label>

		<div class="indent-wrap expandable-content">
			<select name="mst_selected_subsite" class="mst-selected-subsite" id="mst-selected-subsite" autocomplete="off">
				<?php
				printf(
					'<option value="">%1$s</option>',
					esc_html( '-- ' . __( 'Select a subsite', 'wp-migrate-db-pro-multisite-tools' ) . ' --' )
				);
				foreach ( $this->subsites_list() as $blog_id => $subsite_path ) {
					$selected = '';
					if ( ! empty( $loaded_profile['mst_selected_subsite'] ) && $blog_id == $loaded_profile['mst_selected_subsite'] ) {
						$selected = ' selected="selected"';
					}
					printf(
						'<option value="%1$s"' . $selected . '>%2$s</option>',
						esc_attr( $blog_id ),
						esc_html( $subsite_path )
					);
				}
				?>
			</select>

			<div class="new-prefix-field">
				<label>
					<?php echo esc_html( __( 'New Table Name Prefix', 'wp-migrate-db-pro-multisite-tools' ) ) . ':'; ?>
					<input type="hidden" id="new-prefix-hidden" class="new-prefix" name="new_prefix" value="<?php echo esc_attr( ! empty( $loaded_profile['new_prefix'] ) ? $loaded_profile['new_prefix'] : $table_prefix ); ?>"/>
					<input type="text" id="new-prefix" size="15" name="new_prefix" class="new-prefix code" placeholder="<?php echo esc_attr( __( 'New Prefix', 'wp-migrate-db-pro-multisite-tools' ) ); ?>" value="<?php echo esc_attr( ! empty( $loaded_profile['new_prefix'] ) ? $loaded_profile['new_prefix'] : $table_prefix ); ?>" autocomplete="off"/>
					<span id="new-prefix-readonly" class="new-prefix"><?php echo esc_attr( ! empty( $loaded_profile['new_prefix'] ) ? $loaded_profile['new_prefix'] : $table_prefix ); ?></span>
				</label>
			</div>
		</div>

		<p class="mst-unavailable inline-message warning" style="display: none; margin: 10px 0 0 0;">
			<strong><?php _e( 'Addon Missing', 'wp-migrate-db-pro-multisite-tools' ); ?></strong> &mdash; <?php _e( 'The Multisite Tools addon is inactive on the <strong>remote site</strong>. Please install and activate it to enable subsite migrations.', 'wp-migrate-db-pro-multisite-tools' ); ?>
		</p>

		<p class="mst-different-plugin-version-notice inline-message warning" style="display: none; margin: 10px 0 0 0;">
			<strong><?php _e( 'Version Mismatch', 'wp-migrate-db-pro-multisite-tools' ); ?></strong> &mdash; <?php printf( __( 'We have detected you have version <span class="mst-remote-version"></span> of WP Migrate DB Pro Multisite Tools at <span class="mst-remote-location"></span> but are using %1$s here. Please go to the <a href="%2$s">Plugins page</a> on both installs and check for updates.', 'wp-migrate-db-pro-multisite-tools' ), $GLOBALS['wpmdb_meta'][ $this->plugin_slug ]['version'], network_admin_url( 'plugins.php' ) ); ?>
		</p>

		<p class="mst-different-prefix-notice inline-message warning" style="display: none; margin: 10px 0 0 0;">
			<strong><?php _e( 'Different Table Prefixes', 'wp-migrate-db-pro-multisite-tools' ); ?></strong> &mdash; <?php printf( __( 'We have detected you have table prefix "<span class="mst-remote-prefix"></span>" at <span class="mst-remote-location"></span> but have "%1$s" here. Multisite Tools currently only supports migrating subsites between sites with the same base table prefix.', 'wp-migrate-db-pro-multisite-tools' ), $table_prefix ); ?>
		</p>
	</div>
	<?php
}
