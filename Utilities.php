<?php

/**
 * Helper class to provide common functionalities throughout the AADSSO plugin.
 */
class AADSSO_Utilities {
	/**
	 * Loads contents of a text file (local or remote).
	 *
	 * @param string $file_path The path to the file. May be local or remote.
	 *
	 * @return string The contents of the file.
	 */
	public static function get_remote_contents( $file_path ) {

		$response      = wp_remote_get( $file_path );
		$file_contents = wp_remote_retrieve_body( $response );

		return $file_contents;
	}
}
