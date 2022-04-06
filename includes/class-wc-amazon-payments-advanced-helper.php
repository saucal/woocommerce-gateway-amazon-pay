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
	public static function convert_range_to_wildcards( int $min, int $max ) { // phpcs:ignore PHPCompatibility.FunctionDeclarations.NewParamTypeDeclarations.intFound
		$diff = $max - $min;

		$diff_length = strlen( (string) $diff );

		if ( $diff_length - 2 <= 0 ) {
			return array_map( 'strval', range( $min, $max ) );
		}

		$mins = array();
		$meds = array();
		$maxs = array();

		$min_upper_limit = round( $min + 5, -1 );
		$max_lower_limit = round( $max - 5, -1 ) + 1;

		/* Keeping track of where mins could be included as a wildcard or one-by-one. */
		$used_wild_card_mins = false;

		if ( 10 === (int) $min_upper_limit - $min ) {
			$mins[]              = substr( (string) $min, 0, strlen( (string) $min ) - 1 ) . '?';
			$used_wild_card_mins = true;
		} else {
			for ( $i = $min; $i < $min_upper_limit; $i++ ) {
				$mins[] = (string) $i;
			}
		}

		$i    = $min_upper_limit;
		$step = self::determine_step( 0, $i, $max_lower_limit );

		/**
		 * If the mins were included as a wildcard and the first step is hight than 10,
		 * we can and we should reset the mids initial values.
		 *
		 * Example: when min is 99300 and max is 99400, min_upper_limit is 99310
		 * so in the mins we have included already 9930? and the i is 99310 as a result
		 * and the step is 100. so the next iteration of i would be 99410 meaning we will
		 * skip from including the numbers between 99400 and 99409 and going on.
		 *
		 * Resetting based on the conditionals below, resolves that issue.
		 */
		if ( $used_wild_card_mins && $step > 10 ) {
			$i    = $min;
			$mins = array();
			$step = self::determine_step( 0, $i, $max_lower_limit );
		}

		do {
			$meds[] = self::get_in_between_wildcard_numbers( $i, $step );
			$i     += $step;
			$step   = self::determine_step( $step, $i, $max_lower_limit );
		} while ( self::there_are_more_steps( $i, $step, $max_lower_limit ) );

		$meds = array_merge( $meds, self::possible_remaining_numbers( $i, $max_lower_limit ) );

		for ( $i = $max_lower_limit - 1; $i <= $max; $i++ ) {
			$maxs[] = (string) $i;
		}

		return self::num_optimizations( array_merge( $mins, $meds, $maxs ) );
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
	 * Determines with what step the next batch of numbers can be included.
	 *
	 * @param int $current_step The current step.
	 * @param int $start        The current offset.
	 * @param int $target       The maximum offset.
	 * @return int
	 */
	private static function determine_step( int $current_step, int $start, int $target ) { // phpcs:ignore PHPCompatibility.FunctionDeclarations.NewParamTypeDeclarations.intFound
		$step_start = pow( 10, strlen( (string) $start ) - 1 );
		if ( $start + $step_start > $target ) {
			// Try to lower the step now.
			if ( $current_step && 10 >= $current_step ) {
				return $current_step;
			} else {
				$done = 0;
				while ( $start + $step_start > $target && $done < 10 ) {
					$step_start = $step_start / 10;
					$done++;
				}
				return $step_start + $start > $target ? $current_step : $step_start;
			}
		}
		return $step_start;
	}

	/**
	 * Returns the numbers in a wildcard format
	 *
	 * @param integer $from   Where to start including from.
	 * @param integer $offset Where the inclusion should end.
	 * @return string
	 */
	private static function get_in_between_wildcard_numbers( int $from, int $offset ) { // phpcs:ignore PHPCompatibility.FunctionDeclarations.NewParamTypeDeclarations.intFound
		$temp = substr( (string) $from, 0, strlen( (string) $from ) - strlen( (string) $offset ) + 1 );
		$diff = strlen( (string) $from ) - strlen( $temp );
		for ( $j = 0; $j < $diff; $j++ ) {
			$temp .= '?';
		}
		return $temp;
	}

	/**
	 * Returns if there are more steps.
	 *
	 * @param int $start  The current number.
	 * @param int $offset The active step.
	 * @param int $max    The maximum number.
	 * @return bool
	 */
	private static function there_are_more_steps( $start, $offset, $max ) {
		return $start + $offset < $max;
	}

	/**
	 * Checks if there are numbers that should be included but they weren't.
	 *
	 * @param int $start Current number.
	 * @param int $end   Maximum number.
	 * @return array
	 */
	private static function possible_remaining_numbers( $start, $end ) {
		if ( $start + 1 >= $end ) {
			return array();
		}
		$range = range( $start, $end );
		array_shift( $range );
		array_pop( $range );
		return array_map( 'strval', $range );
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
	private static function num_optimizations( array $array ) {
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
