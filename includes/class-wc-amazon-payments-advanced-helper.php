<?php
/**
 * Amazon Helper class.
 *
 * @package WC_Gateway_Amazon_Pay
 */

/**
 * Amazon Pay Helper class
 */
class WC_Amazon_Payments_Advanced_Helper {

	/**
	 * Taking advantage of Amazon API's '?' wildcard.
	 *
	 * Postcode ranges are not supported by Amazon API's,
	 * so we convert those ranges to array using wildcard for efficiency.
	 *
	 * example if the client specified 1...10000 this function will return
	 *
	 * array( '?', '??', '???', '????', 10000 );
	 *
	 * @param int $min The start of the range of numbers to be included.
	 * @param int $max The end of the range of numbers to be included.
	 * @return array
	 */
	public static function convert_range_to_wildcards( $start, $end ) {
		while( $start <= $end ) {
			$shift = 0;
			$multiple = 1;
			$done = false;
			$over = false;
			$fits = true;
			while( ! ( $done || $over ) && $fits ) {
				$multiple *= 10;
				$shift += 1;
				$next_value = intval( $start / $multiple ) * $multiple + $multiple;
				$done = $next_value === ( $end + 1 );
				$over = $next_value > ( $end + 1 );
				$fits = intval( $start / $multiple ) === intval( ( $start + $multiple - 1 ) / $multiple );
				
				if ( $over || ! $fits ) {
					$multiple = intval( $multiple / 10 );
					$shift -= 1;
				}
			}
			
			yield strval( intval( $start / $multiple ) ) . str_repeat( '?', $shift );
			$start += $multiple;
		}
	}

	/**
	 * Adds leading zeros that could have been removed
	 * from the converted to wildcards ranges, when being cast to numbers.
	 *
	 * @param array $converted The converted to wildcards ranges.
	 * @param array $originals The original numbers.
	 * @return array
	 */
	public static function maybe_re_add_leading_zeros( array $converted, array $originals ) {
		if ( empty( $originals['0'] ) || empty( $originals['1'] ) ) {
			return $converted;
		}

		$min_cast_to_int = (int) $originals['0'];
		$max_cast_to_int = (int) $originals['1'];

		if ( strlen( $originals['0'] ) === strlen( (string) $min_cast_to_int ) && strlen( $originals['1'] ) === strlen( (string) $max_cast_to_int ) ) {
			return $converted;
		}

		$min_diff = strlen( $originals['0'] ) - strlen( (string) $min_cast_to_int );
		$max_diff = strlen( $originals['1'] ) - strlen( (string) $max_cast_to_int );

		if ( $min_diff !== $max_diff ) {
			return $converted;
		}

		if ( substr( $originals['0'], 0, $min_diff ) !== str_repeat( '0', $min_diff ) ) {
			return $converted;
		}

		return array_map(
			function ( $v ) use ( $min_diff ) {
				return str_repeat( '0', $min_diff ) . $v;
			},
			$converted
		);
	}

	/**
	 * Adds dashes that could have been removed from the converted
	 * to wildcards ranges, when being cast to numbers.
	 *
	 * @param array $converted The converted to wildcards ranges.
	 * @param array $originals The original numbers.
	 * @return array
	 */
	public static function maybe_re_add_dashes( array $converted, array $originals ) {
		if ( empty( $originals['0'] ) || empty( $originals['1'] ) ) {
			return $converted;
		}

		if ( ! strstr( $originals['0'], '-' ) || ! strstr( $originals['1'], '-' ) ) {
			return $converted;
		}

		$min_offset = strpos( $originals['0'], '-' );
		$max_offset = strpos( $originals['1'], '-' );

		if ( $min_offset !== $max_offset ) {
			return $converted;
		}

		return array_map(
			function( $v ) use ( $min_offset ) {
				return substr( $v, 0, $min_offset ) . '-' . substr( $v, $min_offset );
			},
			$converted
		);
	}

	/**
	 * Optimizes the final returned array.
	 *
	 * Example: If in the incoming array the values '1?', '2?', '3? ... '9?' are present,
	 * they will be replaced by one value '??'.
	 *
	 * The same would happen for '1??', '2??' etc...
	 *
	 * @param array $array Array already containing range converted to wildcards.
	 * @return array
	 */
	public static function num_optimizations( array $array ) {
		$array = array_flip( array_unique( $array ) );

		for ( $j = 0; $j < 6; $j ++ ) {
			$isset    = true;
			$to_unset = array();
			for ( $i = 1; $i < 10; $i ++ ) {
				if ( ! isset( $array[ $i . str_repeat( '?', $j ) ] ) ) {
					$isset = false;
				} else {
					$to_unset[] = $i . str_repeat( '?', $j );
				}
			}

			if ( $isset ) {
				foreach ( $to_unset as $unset ) {
					unset( $array[ $unset ] );
				}
				$array[ '?' . str_repeat( '?', $j ) ] = true;
			}
		}

		return array_filter( array_keys( $array ) );
	}
}
