<?php

namespace Stockino\Suppliers;

use WP_Error;

final class SupplierValidator {
	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function supplier( array $input, bool $partial = false ) {
		$data = array();
		if ( ! $partial || array_key_exists( 'name', $input ) ) {
			$name = sanitize_text_field( (string) ( $input['name'] ?? '' ) );
			if ( '' === $name ) {
				return $this->error( 'stockino_supplier_name_required', __( 'Supplier name is required.', 'stockino' ) );
			}
			if ( $this->length( $name ) > 190 ) {
				return $this->error( 'stockino_supplier_name_too_long', __( 'Supplier name must be 190 characters or fewer.', 'stockino' ) );
			}
			$data['name'] = $name;
		}

		if ( ! $partial || array_key_exists( 'code', $input ) ) {
			$raw_code = trim( sanitize_text_field( (string) ( $input['code'] ?? '' ) ) );
			$code     = SupplierCode::normalize( $raw_code );
			if ( '' !== $raw_code && '' === $code ) {
				return $this->error( 'stockino_invalid_supplier_code', __( 'Supplier code must contain letters or numbers.', 'stockino' ) );
			}
			if ( null !== $code && strlen( $code ) > 100 ) {
				return $this->error( 'stockino_supplier_code_too_long', __( 'Supplier code must be 100 characters or fewer.', 'stockino' ) );
			}
			$data['code'] = $code;
		}

		if ( ! $partial || array_key_exists( 'status', $input ) ) {
			$status = sanitize_key( (string) ( $input['status'] ?? 'active' ) );
			if ( ! in_array( $status, array( 'active', 'inactive' ), true ) ) {
				return $this->error( 'stockino_invalid_supplier_status', __( 'Supplier status must be active or inactive.', 'stockino' ) );
			}
			$data['status'] = $status;
		}

		foreach ( array(
			'contact_name' => 190,
			'phone'        => 100,
		) as $field => $max ) {
			if ( ! $partial || array_key_exists( $field, $input ) ) {
				$value = trim( sanitize_text_field( (string) ( $input[ $field ] ?? '' ) ) );
				if ( $this->length( $value ) > $max ) {
					return $this->error( 'stockino_invalid_' . $field, __( 'This supplier field is too long.', 'stockino' ) );
				}
				$data[ $field ] = '' === $value ? null : $value;
			}
		}

		if ( ! $partial || array_key_exists( 'email', $input ) ) {
			$email = sanitize_email( (string) ( $input['email'] ?? '' ) );
			if ( '' !== trim( (string) ( $input['email'] ?? '' ) ) && ! is_email( $email ) ) {
				return $this->error( 'stockino_invalid_supplier_email', __( 'Enter a valid supplier email address.', 'stockino' ) );
			}
			$data['email'] = '' === $email ? null : $email;
		}

		if ( ! $partial || array_key_exists( 'website', $input ) ) {
			$website = trim( (string) ( $input['website'] ?? '' ) );
			if ( '' !== $website && ( false === filter_var( $website, FILTER_VALIDATE_URL ) || ! in_array( wp_parse_url( $website, PHP_URL_SCHEME ), array( 'http', 'https' ), true ) ) ) {
				return $this->error( 'stockino_invalid_supplier_website', __( 'Enter a valid HTTP or HTTPS website URL.', 'stockino' ) );
			}
			$data['website'] = '' === $website ? null : esc_url_raw( $website );
		}

		foreach ( array(
			'address' => 4000,
			'notes'   => 10000,
		) as $field => $max ) {
			if ( ! $partial || array_key_exists( $field, $input ) ) {
				$value = trim( sanitize_textarea_field( (string) ( $input[ $field ] ?? '' ) ) );
				if ( $this->length( $value ) > $max ) {
					return $this->error( 'stockino_invalid_' . $field, __( 'This supplier field is too long.', 'stockino' ) );
				}
				$data[ $field ] = '' === $value ? null : $value;
			}
		}

		if ( ! $partial || array_key_exists( 'lead_time_days', $input ) ) {
			$lead_time = $input['lead_time_days'] ?? null;
			if ( null === $lead_time || '' === $lead_time ) {
				$data['lead_time_days'] = null;
			} elseif ( false === filter_var( $lead_time, FILTER_VALIDATE_INT ) || (int) $lead_time < 0 || (int) $lead_time > 3650 ) {
				return $this->error( 'stockino_invalid_lead_time', __( 'Lead time must be a whole number between 0 and 3650 days.', 'stockino' ) );
			} else {
				$data['lead_time_days'] = (int) $lead_time;
			}
		}

		return $data;
	}

	/** @param array<string,mixed> $input @return array<string,mixed>|WP_Error */
	public function relationship( array $input, bool $partial = false ) {
		$data = array();
		if ( ! $partial || array_key_exists( 'supplier_sku', $input ) ) {
			$sku = trim( sanitize_text_field( (string) ( $input['supplier_sku'] ?? '' ) ) );
			if ( $this->length( $sku ) > 190 ) {
				return $this->error( 'stockino_supplier_sku_too_long', __( 'Supplier SKU must be 190 characters or fewer.', 'stockino' ) );
			}
			$data['supplier_sku'] = '' === $sku ? null : $sku;
		}
		if ( ! $partial || array_key_exists( 'lead_time_days', $input ) ) {
			$lead_time = $input['lead_time_days'] ?? null;
			if ( null === $lead_time || '' === $lead_time ) {
				$data['lead_time_days'] = null;
			} elseif ( false === filter_var( $lead_time, FILTER_VALIDATE_INT ) || (int) $lead_time < 0 || (int) $lead_time > 3650 ) {
				return $this->error( 'stockino_invalid_relation_lead_time', __( 'Lead time override must be between 0 and 3650 days.', 'stockino' ) );
			} else {
				$data['lead_time_days'] = (int) $lead_time;
			}
		}
		foreach ( array( 'minimum_order_quantity', 'order_multiple' ) as $field ) {
			if ( ! $partial || array_key_exists( $field, $input ) ) {
				$value = $input[ $field ] ?? null;
				if ( null === $value || '' === $value ) {
					$data[ $field ] = null;
					continue;
				}
				if ( ! is_numeric( $value ) || (float) $value <= 0 ) {
					return $this->error( 'stockino_invalid_' . $field, __( 'Order quantities must be positive numbers.', 'stockino' ) );
				}
				$decimal = wc_format_decimal( $value, 6, false );
				if ( '' === $decimal || strlen( ltrim( $decimal, '-' ) ) > 21 ) {
					return $this->error( 'stockino_invalid_' . $field, __( 'Order quantity is outside the supported range.', 'stockino' ) );
				}
				$data[ $field ] = $decimal;
			}
		}
		if ( ! $partial || array_key_exists( 'notes', $input ) ) {
			$notes = trim( sanitize_textarea_field( (string) ( $input['notes'] ?? '' ) ) );
			if ( $this->length( $notes ) > 10000 ) {
				return $this->error( 'stockino_relation_notes_too_long', __( 'Relationship notes must be 10000 characters or fewer.', 'stockino' ) );
			}
			$data['notes'] = '' === $notes ? null : $notes;
		}
		return $data;
	}

	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}

	private function error( string $code, string $message ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => 400 ) );
	}
}
