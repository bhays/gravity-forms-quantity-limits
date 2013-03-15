<?php

/**
 * Better Inventory with Gravity Forms / Limit by Sum of Field Values
 * http://gravitywiz.com/2012/09/19/better-inventory-with-gravity-forms/
 *
 * Modified for Gravity Forms Limiter plugin:
 *  - Added display of remaining inventory
 */

class GWLimitBySum {

	private $_args;

	function __construct($args) {

		$this->_args = wp_parse_args($args, array(
				'form_id' => false,
				'field_id' => false,
				'limit' => 20,
				'limit_message' => __('Sorry, this item is sold out.'),
				'validation_message' => __('You ordered %1$s of this item. There are only %2$s of this item left.'),
				'remainder_message' => __('There are %1$s of this item left.'),
				'approved_payments_only' => false,
				'hide_form' => false
			));

		$this->_args['input_id'] = $this->_args['field_id'];
		extract($this->_args);
		
		add_filter("gform_pre_render_$form_id", array(&$this, 'limit_by_field_values'));
		add_filter("gform_validation_$form_id", array(&$this, 'limit_by_field_values_validation'));
		
		if($approved_payments_only) {
			add_filter('gwlimitbysum_query', array(&$this, 'limit_by_approved_only'));
		}
	}

	public function limit_by_field_values($form) {

		$sum = self::get_field_values_sum($form['id'], $this->_args['input_id']);

		// Find number of remaining tickets
		$this->_args['remaining'] = $this->_args['limit'] - $sum;

		// Display remainder of ticketes on field if set and avaliable
		if( $sum < $this->_args['limit'] ){
			
			if( !empty($this->_args['remainder_message']) ){
				add_filter("gform_field_content", array(&$this, 'add_field_note'), 10, 5);
			}
			return $form;
		}
		
		if($this->_args['hide_form']) {
			add_filter('gform_get_form_filter', create_function('', 'return "' . $this->_args['limit_message'] . '";'));
		} else {
			add_filter('gform_field_input', array(&$this, 'hide_field'), 10, 2);
		}

		return $form;
	}

	public function limit_by_field_values_validation($validation_result) {

		extract($this->_args);
		
		$form = $validation_result['form'];
		$exceeded_limit = false;
			
		foreach($form['fields'] as &$field) {

			if($field['id'] != intval($input_id)) {
				continue;
			}
			
			$requested_value = rgpost("input_" . str_replace('.', '_', $input_id));
			$field_sum = self::get_field_values_sum($form['id'], $input_id);

			if($field_sum + $requested_value <= $limit || empty($requested_value)) {
				continue;
			}

			$exceeded_limit = true;
			$number_left = $limit - $field_sum >= 0 ? $limit - $field_sum : 0;

			$field['failed_validation'] = true;
			$field['validation_message'] = sprintf($validation_message, $requested_value, $number_left);

		}

		$validation_result['form'] = $form;
		$validation_result['is_valid'] = !$validation_result['is_valid'] ? false : !$exceeded_limit;
		
		return $validation_result;
	}

	public function hide_field($field_content, $field) {

		if($field['id'] == intval($this->_args['input_id'])) {
			return "<div class=\"ginput_container\">{$this->_args['limit_message']}</div>";
		}

		return $field_content;
	}

	public function add_field_note($field_content, $field) {

		if($field['id'] == intval($this->_args['input_id'])) {
			$field_content .= sprintf($this->_args['remainder_message'], $this->_args['remaining']);
		}
		return $field_content;
	}

	public static function get_field_values_sum($form_id, $input_id) {
		global $wpdb;

		$query = apply_filters('gwlimitbysum_query', array(
				'select' => 'SELECT sum(value)',
				'from' => "FROM {$wpdb->prefix}rg_lead_detail ld",
				'where' => $wpdb->prepare("WHERE ld.form_id = %d AND CAST(ld.field_number as unsigned) = %d", $form_id, $input_id)
			));

		// Count only entries that are active and not in the trash
		$query['from'] .= " INNER JOIN {$wpdb->prefix}rg_lead l ON l.id = ld.lead_id";
		$query['where'] .= ' AND l.status = \'active\'';

		$sql = implode(' ', $query);
		return $wpdb->get_var($sql);
	}

	public static function limit_by_approved_only($query) {
		global $wpdb;
		$query['from'] .= " INNER JOIN {$wpdb->prefix}rg_lead l ON l.id = ld.lead_id";
		$query['where'] .= ' AND l.payment_status = \'Approved\'';
		return $query;
	}

}