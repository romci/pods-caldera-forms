<?php


/**
 * Verify the pod ID is valid
 *
 * @since 1.0.0
 *
 * @param array $config Settings for the processor
 * @param array $form Full form structure
 *
 * @return void|array Nothing if Pod is good, error array if not good.
 */
function pods_cf_verify_entry_id($config, $form){
	global $transdata;

	// get pod ID
	if( !empty( $config['pod_id'] ) ){
		$pod_id = Caldera_Forms::do_magic_tags( $config['pod_id'] );
		if( empty($pod_id) ){
			return array(
				'type' => 'error',
				'note' => __('Invalid Pod ID', 'pods-caldera-forms' )
			);

		}

	}

}

/**
 * Create Pod entry from submission
 *
 * @since 1.0.0
 *
 * @param array $config Settings for the processor
 * @param array $form Full form structure
 *
 * @return array
 */
function pods_cf_capture_entry($config, $form){
	global $transdata;

	// get pod ID
	$pod_id = null;
	if ( ! empty( $config['pod_id'] ) ) {
		$pod_id = Caldera_Forms::do_magic_tags( $config['pod_id'] );
	}

	// build entry
	$entry = array();

	// add object fields
	if ( ! empty( $config['object_fields'] ) ) {
		foreach ( $config['object_fields'] as $object_field => $binding ) {
			if ( ! empty( $binding ) ) {
				$entry[ $object_field ] = Caldera_Forms::get_field_data( $binding, $form );
			}
		}
	}

	$pods = pods( $config['pod'] );
	$fields = $pods->fields();

	// add pod fields
	if ( ! empty( $config['fields'] ) ) {
		foreach ( $config['fields'] as $pod_field => $binding ) {
			if ( ! empty( $binding ) ) {
				$entry[ $pod_field ] = Caldera_Forms::get_field_data( $binding, $form );

				// process file fields correctly
				$real_pod_field = $pods->fields($pod_field);
				if ($real_pod_field["type"] == "file") {
					$dir = wp_upload_dir();

					// we get URL of the attachment from Caldera
					// need to convert to file path on the server
					if ( 0 === strpos( $entry[ $pod_field ], $dir['baseurl'] . '/' ) ) {
						$path = substr( $entry[ $pod_field ], strlen( $dir['baseurl'] . '/' ) );
					}
					$orig_file_path = $dir['basedir'] . "/" . $path;
					$tmp_file_path = get_temp_dir() . basename($entry[ $pod_field ]);

					// Since Caldera automatically uploads the file into wp-uploads
					// we need to move uploaded file from wp_uploads to temp file
					// as pods_attachment_import creates a duplicate otherwise
					if (file_exists($orig_file_path)&&!is_dir($orig_file_path)&&rename($orig_file_path, $tmp_file_path)) {;
						$attachment_id = pods_attachment_import($tmp_file_path);
						if ($attachmend_id) {
							$entry[ $pod_field ] = $attachment_id;
							unlink($tmp_file_path);
						}
					}
				}
			}
		}
	}




	// Save Entry
	if ( ! empty( $pod_id ) ) {
		$pod_id = $pods->save( $entry, null, $pod_id );
	} else {
		$pod_id = $pods->add( $entry );
	}


	// return entry id for metadata
	return array(
		'pod_id'    => $pod_id,
		'permalink' => get_permalink( $pod_id )
	);

}

/**
 * Pre-populate options for bound fields
 *
 * @since 1.0.0
 *
 * @param array $field Field config
 *
 * @return array Field config
 */
function pods_cf_populate_options( $field ){
	global $form;
	$processors = Caldera_Forms::get_processor_by_type( 'pods', $form );
	if ( empty( $processors ) ) {
		return $field;
	}

	foreach ( $processors as $processor ) {

		// is configured
		$fields = array();
		if ( ! empty( $processor['config']['fields'] ) ) {
			$fields = array_merge( $fields, $processor['config']['fields'] );
		}
		if ( ! empty( $processor['config']['object_fields'] ) ) {
			$fields = array_merge( $fields, $processor['config']['object_fields'] );
		}
		if ( $bound_field = array_search( $field['ID'], $fields ) ) {
			// now lets see if this is a pick field
			$pod       = pods( $processor['config']['pod'], null, false );
			$pod_field = $pod->fields( $bound_field );
			if ( ! empty( $pod_field['options']['required'] ) ) {
				$field['required'] = 1;
			}
			if ( $pod_field['type'] === 'pick' ) {

				$options = PodsForm::options( $pod_field['type'], $pod_field );

				include_once PODS_DIR . 'classes/fields/pick.php';
				$fieldtype                 = new PodsField_Pick();
				$choices                   = $fieldtype->data( $bound_field, null, $options, $pod );
				$field['config']['option'] = array();
				foreach ( $choices as $choice_value => $choice_label ) {
					$field['config']['option'][] = array(
						'value' => $choice_value,
						'label' => $choice_label
					);
				}
			}

		}
	}

	return $field;

}

/**
 * Load Pod Fields config in admin
 *
 * @since 0.1.0
 */
function pods_cf_load_fields(){
	global $form;

		$_POST = stripslashes_deep( $_POST );
		if(!empty($_POST['fields'])){
			$defaults = json_decode( $_POST['fields'] , true);			
		}

		$selected_pod = $_POST['_value'];
		$pods_api     = pods_api();
		$pod_fields   = array();
		if ( ! empty( $selected_pod ) ) {			
			$pod_object = $pods_api->load_pod( array( 'name' => $selected_pod ) );
			if ( ! empty( $pod_object ) && !empty( $pod_object['fields'] ) ) {
				echo '<h4>' . __('Pod Fields', 'pods-caldera-forms') . '</h4>';
				foreach ( $pod_object['fields'] as $name => $field ) {
					$sel = "";
					if(!empty($defaults[ 'fields' ][$name])){
						$sel = 'data-default="'.$defaults[ 'fields' ][$name].'"';
					}

					$locktype = '';
					$caption = '';
					if($field['type'] === 'pick'){
						$locktype = 'data-type="'.$field['options'][ 'pick_format_' . $field['options']['pick_format_type'] ].'"';
						$caption = '<p>'.__('Options will be auto auto-populated', 'pods-caldera-forms').'</p>';
					}
				?>
				<div class="caldera-config-group">
					<label for="<?php echo $_POST['id']; ?>_fields_<?php echo $name; ?>"><?php echo $field['label']; ?></label>
					<div class="caldera-config-field">
						<select class="block-input caldera-field-bind <?php echo ( empty( $field['options']['required'] ) ? '' : 'required' ); ?>" <?php echo $sel; ?> <?php echo $locktype; ?> id="<?php echo $_POST['id']; ?>_fields_<?php echo $name; ?>" name="<?php echo $_POST['name']; ?>[fields][<?php echo $name; ?>]"></select>
						<?php echo $caption ?>
					</div>
				</div>
				<?php
				}
			}
		}


		$wp_object_fields = array();
		if ( ! empty( $pod_object ) && !empty( $pod_object['object_fields'] ) ) {
			echo '<h4>' . __('WP Object Fields', 'pods-caldera-forms') . '</h4>';
			foreach ( $pod_object['object_fields'] as $name => $field ) {
					$sel = "";
					if ( ! empty( $defaults['object_fields'][ $name ] ) ) {
						$sel = 'data-default="' . $defaults['object_fields'][ $name ] . '"';
					}

				?>
				<div class="caldera-config-group">
					<label for="<?php echo $_POST['id']; ?>_object_<?php echo $name; ?>"><?php echo $field['label']; ?></label>
					<div class="caldera-config-field">
						<select class="block-input caldera-field-bind" id="<?php echo $_POST['id']; ?>_object_<?php echo $name; ?>" <?php echo $sel; ?> name="<?php echo $_POST['name']; ?>[object_fields][<?php echo $name; ?>]"></select>
					</div>
				</div>
				<?php
			}
		}

	exit;
	
}
